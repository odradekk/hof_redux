import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { validateContent } from "../src/validate.js";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CONTENT_DIR = path.resolve(HERE, "..", "..", "..", "content");

test("S1 首批内容包校验通过：零错误", () => {
  const result = validateContent(CONTENT_DIR);
  assert.equal(result.errors.length, 0, JSON.stringify(result.errors.slice(0, 5), null, 2));
  assert.ok(result.content);
});

test("素材使用者由内容引用生成，而非由编辑源维护", (t) => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-content-neg-"));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  fs.cpSync(CONTENT_DIR, dir, { recursive: true });
  const source = JSON.parse(fs.readFileSync(path.join(dir, "assets.json"), "utf8")) as {
    assets: Record<string, unknown>[];
  };
  assert.ok(source.assets.every((a) => !("usedBy" in a)));
  const p = path.join(dir, "items.json");
  const items = JSON.parse(fs.readFileSync(p, "utf8")) as { items: Record<string, unknown>[] };
  const item = items.items.find((i) => i["id"] === "item.6002")!;
  item["imageAssetId"] = "asset.item.6000";
  fs.writeFileSync(p, JSON.stringify(items, null, 2));
  const result = validateContent(dir);
  assert.equal(result.errors.length, 0, JSON.stringify(result.errors));
  assert.deepEqual(result.content!.assets.find((a) => a.id === "asset.item.6001")?.usedBy, ["item.6001", "item.6003"]);
  assert.deepEqual(result.content!.assets.find((a) => a.id === "asset.item.6000")?.usedBy, ["item.6000", "item.6002"]);
});

test("素材路径不能逃出 content/assets", (t) => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-content-neg-"));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  fs.cpSync(CONTENT_DIR, dir, { recursive: true });
  const p = path.join(dir, "assets.json");
  const o = JSON.parse(fs.readFileSync(p, "utf8")) as { assets: Record<string, unknown>[] };
  o.assets[0]!["path"] = "content/assets/../../package.json";
  fs.writeFileSync(p, JSON.stringify(o, null, 2));
  const result = validateContent(dir);
  assert.ok(result.errors.some((d) => d.code === "E_ASSET_PATH"), JSON.stringify(result.errors));
});

test("保护策略的阈值必须与种类匹配", (t) => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-content-neg-"));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  fs.cpSync(CONTENT_DIR, dir, { recursive: true });
  const p = path.join(dir, "recruitment.json");
  const raw = fs.readFileSync(p, "utf8");
  for (const policy of [{ kind: "probability" }, { kind: "always", threshold: 25 }]) {
    const o = JSON.parse(raw) as { recruitments: Record<string, unknown>[] };
    o.recruitments[0]!["guardPolicy"] = policy;
    fs.writeFileSync(p, JSON.stringify(o, null, 2));
    const result = validateContent(dir);
    assert.ok(result.errors.some((d) => d.code === "E_SCHEMA"), JSON.stringify(result.errors));
  }
});

test("S1 范围恰好包含指定的首批定义", () => {
  const result = validateContent(CONTENT_DIR);
  assert.equal(result.errors.length, 0);
  const c = result.content!;
  assert.deepEqual(
    c.recruitments.map((r) => r.id),
    ["recruit.1", "recruit.2"],
  );
  assert.deepEqual(
    c.jobs.map((j) => j.id),
    ["job.100", "job.200"],
  );
  assert.deepEqual(
    c.items.map((i) => i.id),
    [
      "item.1000",
      "item.1700",
      "item.3000",
      "item.5000",
      "item.5200",
      "item.6000",
      "item.6001",
      "item.6002",
      "item.6003",
      "item.7100",
    ],
  );
  assert.deepEqual(
    c.skills.map((s) => s.id),
    ["skill.1000", "skill.1001", "skill.1002", "skill.1014", "skill.1017", "skill.3010"],
  );
  assert.deepEqual(
    c.conditions.map((c) => c.id),
    ["condition.1000", "condition.1205", "condition.1206", "condition.1940"],
  );
  assert.deepEqual(
    c.monsters.map((m) => m.id),
    ["monster.1000", "monster.1001"],
  );
  assert.deepEqual(
    c.maps.map((m) => m.id),
    ["map.gb0"],
  );
});

test("初始装备逐件独立、材料可堆叠", () => {
  const result = validateContent(CONTENT_DIR);
  assert.equal(result.errors.length, 0);
  const c = result.content!;
  for (const i of c.items) {
    if (i.kind === "equipment") {
      assert.equal(i.instancePolicy, "independent", i.id);
    } else {
      assert.equal(i.stackable, true, i.id);
    }
  }
});

test("怪物经验与金钱：20 经验、100 金钱（已裁决保留旧行为）", () => {
  const result = validateContent(CONTENT_DIR);
  assert.equal(result.errors.length, 0);
  const c = result.content!;
  for (const m of c.monsters) {
    assert.equal(m.experienceReward, 20, m.id);
    // 100 系 CreateMonster 对编号 <2000 怪物的 moneyhold 覆盖（:3914-3917），表内 40 不生效；
    // S1 规格原 40 属来源核对遗漏，已修正（非批准差异）。
    assert.equal(m.moneyReward, 100, m.id);
  }
});

test("旧 9000 不会被当成可施放技能", () => {
  const result = validateContent(CONTENT_DIR);
  assert.equal(result.errors.length, 0);
  const c = result.content!;
  const allSkillRefs = [
    ...c.recruitments.flatMap((r) => [
      ...r.initialSkillIds,
      ...r.defaultTactics.map((g) => g.skillId),
    ]),
    ...c.monsters.flatMap((m) => m.tactics.map((g) => g.skillId)),
  ];
  assert.ok(!allSkillRefs.some((s) => s === "skill.9000"));
  assert.ok(!c.skills.some((s) => s.id === "skill.9000"));
  assert.ok(!c.conditions.some((cond) => cond.id === "condition.9000"));
});

test("默认战术完整表达概率、SP 条件和有序 AND", () => {
  const result = validateContent(CONTENT_DIR);
  assert.equal(result.errors.length, 0);
  const c = result.content!;
  // 怪物首组为有序 AND：概率 ∧ SP
  for (const m of c.monsters) {
    const first = m.tactics[0]!;
    assert.ok(first.conditions.length === 2, m.id);
    assert.equal(first.conditions[0]!.conditionId, "condition.1940");
    assert.equal(first.conditions[1]!.conditionId, "condition.1205");
  }
  // 招募覆盖 SP>= 与 SP<=
  const conds = new Set(c.recruitments.flatMap((r) => r.defaultTactics.flatMap((g) => g.conditions.map((x) => x.conditionId))));
  assert.ok(conds.has("condition.1205"));
  assert.ok(conds.has("condition.1206"));
  assert.ok(conds.has("condition.1000"));
});

test("未知处理器不会退化：构造非法包应报错", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-content-neg-"));
  fs.cpSync(CONTENT_DIR, dir, { recursive: true });
  const skillsPath = path.join(dir, "skills.json");
  const skills = JSON.parse(fs.readFileSync(skillsPath, "utf8")) as { skills: Record<string, unknown>[] };
  // 结构合法的具名技能形状，但引用未登记的处理器：必须被语义检查拒绝，不得退化。
  delete skills.skills[0]!["power"];
  delete skills.skills[0]!["preDelay"];
  delete skills.skills[0]!["postDelay"];
  skills.skills[0]!["handlerId"] = "no-such-handler";
  skills.skills[0]!["handlerParams"] = { percent: 30 };
  fs.writeFileSync(skillsPath, JSON.stringify(skills, null, 2));
  const result = validateContent(dir);
  assert.ok(result.errors.some((d) => d.code === "E_UNKNOWN_HANDLER"), JSON.stringify(result.errors));
  fs.rmSync(dir, { recursive: true, force: true });
});

test("重复标识应报错", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-content-neg-"));
  fs.cpSync(CONTENT_DIR, dir, { recursive: true });
  const jobsPath = path.join(dir, "jobs.json");
  const jobs = JSON.parse(fs.readFileSync(jobsPath, "utf8")) as { jobs: Record<string, unknown>[] };
  jobs.jobs.push({ ...jobs.jobs[0]! });
  fs.writeFileSync(jobsPath, JSON.stringify(jobs, null, 2));
  const result = validateContent(dir);
  assert.ok(result.errors.some((d) => d.code === "E_DUP_ID"), JSON.stringify(result.errors));
  fs.rmSync(dir, { recursive: true, force: true });
});

test("缺失引用应报错", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-content-neg-"));
  fs.cpSync(CONTENT_DIR, dir, { recursive: true });
  const mapsPath = path.join(dir, "maps.json");
  const maps = JSON.parse(fs.readFileSync(mapsPath, "utf8")) as { maps: Record<string, unknown>[] };
  const encounters = (maps.maps[0]!["encounters"] as Record<string, unknown>[]);
  encounters[0]!["monsterId"] = "monster.9999";
  fs.writeFileSync(mapsPath, JSON.stringify(maps, null, 2));
  const result = validateContent(dir);
  assert.ok(result.errors.some((d) => d.code === "E_REF"), JSON.stringify(result.errors));
  fs.rmSync(dir, { recursive: true, force: true });
});

test("负等级应报错（Schema 边界执行）", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-content-neg-"));
  fs.cpSync(CONTENT_DIR, dir, { recursive: true });
  const p = path.join(dir, "recruitment.json");
  const o = JSON.parse(fs.readFileSync(p, "utf8")) as { recruitments: Record<string, unknown>[] };
  o.recruitments[0]!["initialLevel"] = -10;
  fs.writeFileSync(p, JSON.stringify(o, null, 2));
  const result = validateContent(dir);
  assert.ok(result.errors.some((d) => d.code === "E_SCHEMA"), JSON.stringify(result.errors));
  fs.rmSync(dir, { recursive: true, force: true });
});

test("负敌人数应报错（Schema 边界执行）", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-content-neg-"));
  fs.cpSync(CONTENT_DIR, dir, { recursive: true });
  const p = path.join(dir, "maps.json");
  const o = JSON.parse(fs.readFileSync(p, "utf8")) as { maps: Record<string, unknown>[] };
  (o.maps[0]!["enemyCount"] as Record<string, unknown>)["min"] = -10;
  fs.writeFileSync(p, JSON.stringify(o, null, 2));
  const result = validateContent(dir);
  assert.ok(result.errors.some((d) => d.code === "E_SCHEMA"), JSON.stringify(result.errors));
  fs.rmSync(dir, { recursive: true, force: true });
});

test("职业 ID 不能充当怪物引用", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-content-neg-"));
  fs.cpSync(CONTENT_DIR, dir, { recursive: true });
  const p = path.join(dir, "maps.json");
  const o = JSON.parse(fs.readFileSync(p, "utf8")) as { maps: Record<string, unknown>[] };
  (o.maps[0]!["encounters"] as Record<string, unknown>[])[0]!["monsterId"] = "job.100";
  fs.writeFileSync(p, JSON.stringify(o, null, 2));
  const result = validateContent(dir);
  assert.ok(
    result.errors.some((d) => d.code === "E_SCHEMA" || d.code === "E_REF_CATEGORY"),
    JSON.stringify(result.errors),
  );
  fs.rmSync(dir, { recursive: true, force: true });
});

test("武器不能穿在防具槽", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-content-neg-"));
  fs.cpSync(CONTENT_DIR, dir, { recursive: true });
  const p = path.join(dir, "recruitment.json");
  const o = JSON.parse(fs.readFileSync(p, "utf8")) as { recruitments: Record<string, unknown>[] };
  (o.recruitments[0]!["initialEquipment"] as Record<string, unknown>)["armor"] = "item.1000";
  fs.writeFileSync(p, JSON.stringify(o, null, 2));
  const result = validateContent(dir);
  assert.ok(result.errors.some((d) => d.code === "E_WEAR"), JSON.stringify(result.errors));
  fs.rmSync(dir, { recursive: true, force: true });
});

test("职业不可装备的武器不能作为初始装备", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-content-neg-"));
  fs.cpSync(CONTENT_DIR, dir, { recursive: true });
  const p = path.join(dir, "recruitment.json");
  const o = JSON.parse(fs.readFileSync(p, "utf8")) as { recruitments: Record<string, unknown>[] };
  // 法师（job.200：魔杖/杖/书/衣服/长袍/道具）配剑 item.1000
  (o.recruitments[1]!["initialEquipment"] as Record<string, unknown>)["weapon"] = "item.1000";
  fs.writeFileSync(p, JSON.stringify(o, null, 2));
  const result = validateContent(dir);
  assert.ok(result.errors.some((d) => d.code === "E_WEAR"), JSON.stringify(result.errors));
  fs.rmSync(dir, { recursive: true, force: true });
});

test("阻塞槽位与已穿戴装备冲突时应报错", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-content-neg-"));
  fs.cpSync(CONTENT_DIR, dir, { recursive: true });
  const p = path.join(dir, "items.json");
  const o = JSON.parse(fs.readFileSync(p, "utf8")) as { items: Record<string, unknown>[] };
  // 短剑阻塞盾槽，但战士初始穿戴仍保留木盾
  o.items.find((i) => i["id"] === "item.1000")!["blockedSlots"] = ["shield"];
  fs.writeFileSync(p, JSON.stringify(o, null, 2));
  const result = validateContent(dir);
  assert.ok(result.errors.some((d) => d.code === "E_WEAR"), JSON.stringify(result.errors));
  fs.rmSync(dir, { recursive: true, force: true });
});

test("未知字段应报错（Schema 执行未知字段拒绝）", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-content-neg-"));
  fs.cpSync(CONTENT_DIR, dir, { recursive: true });
  const p = path.join(dir, "jobs.json");
  const o = JSON.parse(fs.readFileSync(p, "utf8")) as { jobs: Record<string, unknown>[] };
  o.jobs[0]!["whatever"] = 1;
  fs.writeFileSync(p, JSON.stringify(o, null, 2));
  const result = validateContent(dir);
  assert.ok(result.errors.some((d) => d.code === "E_SCHEMA"), JSON.stringify(result.errors));
  fs.rmSync(dir, { recursive: true, force: true });
});
