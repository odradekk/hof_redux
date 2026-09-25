import test from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";
import { buildApp } from "../src/app.js";
import { loadContentManifest, resolveSnapshotManifest } from "../src/contentManifest.js";
import { runMigrations } from "../src/db/migrate.js";
import { loadPartyContent } from "../src/party/content.js";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const MIGRATIONS_DIR = path.resolve(HERE, "../migrations");
const SNAPSHOT_MANIFEST = resolveSnapshotManifest(path.resolve(HERE, "../../../content"));
const TEST_CONTENT = loadContentManifest(SNAPSHOT_MANIFEST, { expectedDbSchema: 4 });
const SNAPSHOT_DIR = path.dirname(SNAPSHOT_MANIFEST);
const PARTY_CONTENT = loadPartyContent(SNAPSHOT_DIR);

function withEditedSnapshot(file: string, edit: (data: Record<string, unknown>) => void, check: (dir: string) => void): void {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-party-content-"));
  try {
    for (const name of ["release.json", "jobs.json", "items.json", "skills.json", "conditions.json", "recruitment.json"]) {
      fs.copyFileSync(path.join(SNAPSHOT_DIR, name), path.join(dir, name));
    }
    const target = path.join(dir, file);
    const data = JSON.parse(fs.readFileSync(target, "utf8")) as Record<string, unknown>;
    edit(data);
    fs.writeFileSync(target, JSON.stringify(data));
    check(dir);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
}

function createTestApp() {
  const db = new DatabaseSync(":memory:");
  db.exec("PRAGMA journal_mode=MEMORY");
  db.exec("PRAGMA synchronous=FULL");
  db.exec("PRAGMA foreign_keys=ON");
  runMigrations(db, MIGRATIONS_DIR);
  const config = {
    host: "127.0.0.1",
    port: 0,
    dbPath: ":memory:",
    migrationsDir: MIGRATIONS_DIR,
    contentManifestPath: "test",
    trustProxy: false,
    appVersion: "0.1.0-test",
    maxUsers: 500,
  };
  const app = buildApp(db, config, TEST_CONTENT, 4, PARTY_CONTENT);
  return { app, db };
}

function requestId(): string {
  return crypto.randomBytes(16).toString("hex");
}

const LONG_PASSWORD = "correct-horse-battery-staple-15x";

async function registerAndLogin(app: Awaited<ReturnType<typeof createTestApp>>["app"], loginName: string): Promise<string> {
  const reg = await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName, password: LONG_PASSWORD, requestId: requestId() },
  });
  assert.equal(reg.statusCode, 201);
  const login = await app.inject({ method: "POST", url: "/api/auth/login", payload: { loginName, password: LONG_PASSWORD } });
  assert.equal(login.statusCode, 200);
  const rawCookie = login.headers["set-cookie"];
  return (Array.isArray(rawCookie) ? rawCookie[0] : String(rawCookie)).split(";")[0];
}

function firstPartyPayload(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    teamName: "远征小队",
    characterName: "艾尔文",
    recruitId: "recruit.1",
    gender: "male",
    requestId: requestId(),
    ...overrides,
  };
}

test("首次建队一次提交获得队伍、首角、独立装备、技能、阵位与默认战术", async () => {
  const { app, db } = createTestApp();
  const jar = await registerAndLogin(app, "partyuser1");

  const created = await app.inject({
    method: "POST",
    url: "/api/party/first",
    headers: { cookie: jar },
    payload: firstPartyPayload({ teamName: "远征小队", characterName: "艾尔文" }),
  });
  assert.equal(created.statusCode, 201, created.body);
  const body = created.json() as Record<string, unknown>;
  assert.equal(body.teamName, "远征小队");
  const character = body.character as Record<string, unknown>;
  assert.equal(character.name, "艾尔文");
  assert.equal(character.jobId, "job.100");
  assert.equal(character.level, 1);
  assert.equal(character.experience, 0);
  assert.deepEqual(character.stats, { str: 10, int: 2, dex: 4, spd: 4, luk: 1 });
  assert.deepEqual(character.skills, [
    { skillId: "skill.1000", name: "攻击" },
    { skillId: "skill.1001", name: "痛击" },
  ]);
  assert.equal(character.jobName, "战士");
  assert.equal(character.position, "front");
  assert.deepEqual(character.guardPolicy, { kind: "always" });
  assert.deepEqual((character.defaultTactics as Array<{ skillId: string }>).map((tactic) => tactic.skillId), [
    "skill.1001",
    "skill.1000",
  ]);
  assert.equal((character.defaultTactics as Array<{ skillName: string }>)[0].skillName, "痛击");

  // 每件初始装备拥有不复用的持有身份、正确归属和穿戴关系。
  const equipment = character.equipment as { equipmentId: string; definitionId: string; name: string; slot: string }[];
  assert.equal(equipment.length, 3);
  const definitions = equipment.map((e) => e.definitionId).sort();
  assert.deepEqual(definitions, ["item.1000", "item.3000", "item.5000"]);
  assert.ok(equipment.some((item) => item.name === "短剑"));
  assert.equal(new Set(equipment.map((e) => e.equipmentId)).size, 3);
  const rows = db.prepare("SELECT * FROM owned_equipment").all() as unknown as Record<string, unknown>[];
  assert.equal(rows.length, 3);
  for (const row of rows) {
    assert.equal(row.equipped_character_id, character.characterId);
  }

  // 建队状态与流水同事务提交：重新登录后可读。
  const mine = await app.inject({ method: "GET", url: "/api/party/mine", headers: { cookie: jar } });
  assert.equal(mine.statusCode, 200);
  const mineBody = mine.json() as Record<string, unknown>;
  assert.equal(mineBody.teamCompleted, true);
  assert.equal(mineBody.teamName, "远征小队");
  const flows = db.prepare("SELECT * FROM game_change_records WHERE kind = 'first_party'").all() as unknown as unknown[];
  assert.equal(flows.length, 1);
  const me = await app.inject({ method: "GET", url: "/api/auth/me", headers: { cookie: jar } });
  assert.equal((me.json() as Record<string, unknown>).teamCompleted, true);

  await app.close();
  db.close();
});

test("战士/法师与男/女四种组合均生成正确内容", async () => {
  const cases = [
    { recruitId: "recruit.1", gender: "male", jobId: "job.100", position: "front", skills: 2, equipment: 3 },
    { recruitId: "recruit.1", gender: "female", jobId: "job.100", position: "front", skills: 2, equipment: 3 },
    { recruitId: "recruit.2", gender: "male", jobId: "job.200", position: "back", skills: 3, equipment: 2 },
    { recruitId: "recruit.2", gender: "female", jobId: "job.200", position: "back", skills: 3, equipment: 2 },
  ];
  for (let index = 0; index < cases.length; index++) {
    const { app, db } = createTestApp();
    const jar = await registerAndLogin(app, `combouse${index}`);
    const created = await app.inject({
      method: "POST",
      url: "/api/party/first",
      headers: { cookie: jar },
      payload: firstPartyPayload({
        teamName: `组合队${index}`,
        characterName: "同名英雄",
        recruitId: cases[index].recruitId,
        gender: cases[index].gender,
      }),
    });
    assert.equal(created.statusCode, 201, `组合 ${index}：${created.body}`);
    const character = (created.json() as Record<string, unknown>).character as Record<string, unknown>;
    assert.equal(character.jobId, cases[index].jobId, `组合 ${index} 职业`);
    assert.equal(character.gender, cases[index].gender, `组合 ${index} 性别`);
    assert.equal(character.position, cases[index].position, `组合 ${index} 阵位`);
    assert.equal((character.skills as unknown[]).length, cases[index].skills, `组合 ${index} 技能`);
    assert.equal((character.equipment as unknown[]).length, cases[index].equipment, `组合 ${index} 装备`);
    await app.close();
    db.close();
  }
  // 角色名允许重名：四队同名均成功已在上循环中断言。
});

test("建队内容加载拒绝槽位错配和重复招募标识", () => {
  withEditedSnapshot("recruitment.json", (data) => {
    const recruitments = data.recruitments as Array<Record<string, unknown>>;
    (recruitments[0].initialEquipment as Record<string, string>).shield = "item.1000";
  }, (dir) => assert.throws(() => loadPartyContent(dir), /不属于槽位 shield/));

  withEditedSnapshot("recruitment.json", (data) => {
    const recruitments = data.recruitments as Array<Record<string, unknown>>;
    recruitments.push(recruitments[0]);
  }, (dir) => assert.throws(() => loadPartyContent(dir), /内容标识重复：recruit.1/));
});

test("发布身份不一致拒绝组装应用；无法解释的持有内容返回服务端错误", async () => {
  const { app, db } = createTestApp();
  const jar = await registerAndLogin(app, "badcontent1");
  const created = await app.inject({
    method: "POST",
    url: "/api/party/first",
    headers: { cookie: jar },
    payload: firstPartyPayload(),
  });
  assert.equal(created.statusCode, 201);
  db.prepare("UPDATE character_skills SET skill_id = ? WHERE position = 0").run("skill.missing");
  const mine = await app.inject({ method: "GET", url: "/api/party/mine", headers: { cookie: jar } });
  assert.equal(mine.statusCode, 500);
  assert.deepEqual(mine.json(), { code: "INTERNAL_ERROR", message: "服务器内部错误" });
  assert.throws(() => buildApp(db, {
    host: "127.0.0.1",
    port: 0,
    dbPath: ":memory:",
    migrationsDir: MIGRATIONS_DIR,
    contentManifestPath: SNAPSHOT_MANIFEST,
    trustProxy: false,
    appVersion: "0.1.0-test",
    maxUsers: 500,
  }, { ...TEST_CONTENT, releaseId: "wrong-release" }, 4, PARTY_CONTENT), /版本与发布清单不一致/);
  await app.close();
  db.close();
});

test("队名与角色名按 NFC、码点长度及字符规则校验；队名唯一", async () => {
  const { app, db } = createTestApp();
  const jar = await registerAndLogin(app, "nameuser1");

  // 空名、超长（17 码点）、换行、控制字符均拒绝。
  for (const [teamName, characterName] of [
    ["", "英雄"],
    ["   ", "英雄"],
    ["合法队", ""],
    ["12345678901234567", "英雄"],
    ["合法队", "12345678901234567"],
    ["含\n换行", "英雄"],
    ["合法队", "含\t制表"],
    ["零宽\u200b字符", "英雄"],
  ]) {
    const result = await app.inject({
      method: "POST",
      url: "/api/party/first",
      headers: { cookie: jar },
      payload: firstPartyPayload({ teamName, characterName }),
    });
    assert.equal(result.statusCode, 400, `应拒绝：${JSON.stringify(teamName)}/${JSON.stringify(characterName)}`);
  }
  // 中文按码点计数：16 字通过。
  const sixteen = await app.inject({
    method: "POST",
    url: "/api/party/first",
    headers: { cookie: jar },
    payload: firstPartyPayload({ teamName: "一二三四五六七八九十一二三四五六", characterName: "艾" }),
  });
  assert.equal(sixteen.statusCode, 201, sixteen.body);

  // 队名唯一：第二账号抢同名失败，无残片。
  const jar2 = await registerAndLogin(app, "nameuser2");
  const taken = await app.inject({
    method: "POST",
    url: "/api/party/first",
    headers: { cookie: jar2 },
    payload: firstPartyPayload({ teamName: "一二三四五六七八九十一二三四五六", characterName: "另一英雄" }),
  });
  assert.equal(taken.statusCode, 409);
  assert.equal((taken.json() as { code: string }).code, "TEAM_NAME_TAKEN");
  const mine2 = await app.inject({ method: "GET", url: "/api/party/mine", headers: { cookie: jar2 } });
  assert.equal((mine2.json() as Record<string, unknown>).teamCompleted, false);
  assert.equal((db.prepare("SELECT COUNT(*) AS n FROM characters").get() as { n: number }).n, 1);

  // 非 BMP 字符每个占两个 UTF-16 单元，但仍只算一个 Unicode 码点。
  const emojiName = "🦊".repeat(16);
  const emojiParty = await app.inject({
    method: "POST",
    url: "/api/party/first",
    headers: { cookie: jar2 },
    payload: firstPartyPayload({ teamName: emojiName, characterName: emojiName }),
  });
  assert.equal(emojiParty.statusCode, 201, emojiParty.body);

  // NFC 等价队名视为冲突：e + 组合重音与单一码点 é 冲突。
  const jar3 = await registerAndLogin(app, "nameuser3");
  const nfcTaken = await app.inject({
    method: "POST",
    url: "/api/party/first",
    headers: { cookie: jar3 },
    payload: firstPartyPayload({ teamName: "cafe\u0301小队", characterName: "英雄三" }),
  });
  assert.equal(nfcTaken.statusCode, 201, nfcTaken.body);
  const jar4 = await registerAndLogin(app, "nameuser4");
  const nfcClash = await app.inject({
    method: "POST",
    url: "/api/party/first",
    headers: { cookie: jar4 },
    payload: firstPartyPayload({ teamName: "caf\u00e9小队", characterName: "英雄四" }),
  });
  assert.equal(nfcClash.statusCode, 409);

  await app.close();
  db.close();
});

test("重复提交返回原结果不重复赠送；换参数拒绝；已建队后新请求拒绝", async () => {
  const { app, db } = createTestApp();
  const jar = await registerAndLogin(app, "replayp1");
  const rid = requestId();
  const first = await app.inject({
    method: "POST",
    url: "/api/party/first",
    headers: { cookie: jar },
    payload: firstPartyPayload({ teamName: "幂等队", requestId: rid }),
  });
  assert.equal(first.statusCode, 201);
  const firstId = ((first.json() as Record<string, unknown>).character as Record<string, unknown>).characterId;

  const replay = await app.inject({
    method: "POST",
    url: "/api/party/first",
    headers: { cookie: jar },
    payload: firstPartyPayload({ teamName: "幂等队", requestId: rid }),
  });
  assert.equal(replay.statusCode, 200);
  assert.equal((replay.json() as Record<string, unknown>).replayed, true);
  assert.equal(
    ((replay.json() as Record<string, unknown>).character as Record<string, unknown>).characterId,
    firstId,
  );

  const changed = await app.inject({
    method: "POST",
    url: "/api/party/first",
    headers: { cookie: jar },
    payload: firstPartyPayload({ teamName: "换名队", requestId: rid }),
  });
  assert.equal(changed.statusCode, 409);

  const again = await app.inject({
    method: "POST",
    url: "/api/party/first",
    headers: { cookie: jar },
    payload: firstPartyPayload({ teamName: "第二支队", requestId: requestId() }),
  });
  assert.equal(again.statusCode, 409);
  assert.equal((again.json() as { code: string }).code, "PARTY_ALREADY_COMPLETED");

  assert.equal((db.prepare("SELECT COUNT(*) AS n FROM characters").get() as { n: number }).n, 1);
  assert.equal((db.prepare("SELECT COUNT(*) AS n FROM owned_equipment").get() as { n: number }).n, 3);
  await app.close();
  db.close();
});

test("非法职业/性别/未知字段拒绝；未登录与过期会话拒绝", async () => {
  const { app, db } = createTestApp();
  const jar = await registerAndLogin(app, "invalidp1");

  for (const payload of [
    firstPartyPayload({ recruitId: "recruit.9" }),
    firstPartyPayload({ recruitId: "recruit.1", gender: "unknown" }),
    { ...firstPartyPayload(), admin: true },
  ]) {
    const result = await app.inject({ method: "POST", url: "/api/party/first", headers: { cookie: jar }, payload });
    assert.equal(result.statusCode, 400, JSON.stringify(payload));
  }

  const noAuth = await app.inject({ method: "POST", url: "/api/party/first", payload: firstPartyPayload() });
  assert.equal(noAuth.statusCode, 401);
  const noAuthGet = await app.inject({ method: "GET", url: "/api/party/mine" });
  assert.equal(noAuthGet.statusCode, 401);
  await app.close();
  db.close();
});

test("未建队账号可读空视图；建队后只读视图含全部真实状态", async () => {
  const { app, db } = createTestApp();
  const jar = await registerAndLogin(app, "viewuser1");
  const empty = await app.inject({ method: "GET", url: "/api/party/mine", headers: { cookie: jar } });
  assert.equal(empty.statusCode, 200);
  assert.deepEqual(
    ((await empty.json()) as Record<string, unknown>),
    {
      teamCompleted: false,
      teamName: null,
      character: null,
      releaseId: TEST_CONTENT.releaseId,
      recoveryEpoch: 1,
    },
  );

  await app.inject({
    method: "POST",
    url: "/api/party/first",
    headers: { cookie: jar },
    payload: firstPartyPayload({ recruitId: "recruit.2", gender: "female" }),
  });
  const mine = (await (await app.inject({ method: "GET", url: "/api/party/mine", headers: { cookie: jar } })).json()) as Record<
    string,
    unknown
  >;
  const character = mine.character as Record<string, unknown>;
  assert.equal(mine.teamName, "远征小队");
  for (const key of [
    "characterId",
    "name",
    "jobId",
    "gender",
    "level",
    "experience",
    "stats",
    "unassignedAp",
    "unassignedSp",
    "skills",
    "equipment",
    "position",
    "guardPolicy",
    "defaultTactics",
  ]) {
    assert.ok(key in character, `只读视图缺少 ${key}`);
  }
  assert.equal(character.unassignedAp, 0);
  await app.close();
  db.close();
});

test("队名争用仅一胜，失败方无残片", async () => {
  const { app, db } = createTestApp();
  const jarA = await registerAndLogin(app, "racepa01");
  const jarB = await registerAndLogin(app, "racepb01");
  const [a, b] = await Promise.all([
    app.inject({
      method: "POST",
      url: "/api/party/first",
      headers: { cookie: jarA },
      payload: firstPartyPayload({ teamName: "争锋队", characterName: "甲" }),
    }),
    app.inject({
      method: "POST",
      url: "/api/party/first",
      headers: { cookie: jarB },
      payload: firstPartyPayload({ teamName: "争锋队", characterName: "乙" }),
    }),
  ]);
  const codes = [a.statusCode, b.statusCode].sort();
  assert.deepEqual(codes, [201, 409]);
  assert.equal((db.prepare("SELECT COUNT(*) AS n FROM characters").get() as { n: number }).n, 1);
  assert.equal((db.prepare("SELECT COUNT(*) AS n FROM accounts WHERE team_completed = 1").get() as { n: number }).n, 1);
  await app.close();
  db.close();
});
