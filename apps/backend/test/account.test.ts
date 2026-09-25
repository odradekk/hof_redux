import test from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";
import { buildApp } from "../src/app.js";
import { runMigrations } from "../src/db/migrate.js";
import { createSession } from "../src/auth/store.js";
import { hashPassword } from "../src/auth/password.js";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const MIGRATIONS_DIR = path.resolve(HERE, "../migrations");

const TEST_CONTENT = {
  releaseId: "s1-test",
  schemaVersion: 1,
  contentHash: "sha256:test",
  fileCount: 0,
};

function createTestApp(maxUsers = 500) {
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
    maxUsers,
  };
  const app = buildApp(db, config, TEST_CONTENT, 3);
  return { app, db };
}

function requestId(): string {
  return crypto.randomBytes(16).toString("hex");
}

const LONG_PASSWORD = "correct-horse-battery-staple-15x";

test("注册成功原子创建账号、资产与流水，重登录后可读", async () => {
  const { app, db } = createTestApp();
  const rid = requestId();
  const reg = await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName: "hero1234", password: LONG_PASSWORD, requestId: rid },
  });
  assert.equal(reg.statusCode, 201);
  const body = reg.json() as Record<string, unknown>;
  assert.ok(typeof body.accountId === "string");
  assert.equal(body.loginName, "hero1234");
  assert.equal(body.money, "10000");
  assert.equal(body.stamina, 100);
  assert.ok(typeof body.recoveryCode === "string" && (body.recoveryCode as string).length === 32);
  const recoveryCode = body.recoveryCode as string;

  // 凭据非明文存储：密码 Argon2id，恢复码仅摘要。
  const row = db.prepare("SELECT * FROM accounts WHERE id = ?").get(body.accountId as string) as unknown as Record<string, unknown>;
  assert.ok(String(row.password_hash).startsWith("$argon2id$"));
  assert.notEqual(row.password_hash, LONG_PASSWORD);
  assert.notEqual(row.recovery_hash, recoveryCode);
  assert.equal(row.recovery_hash, crypto.createHash("sha256").update(recoveryCode, "utf8").digest("hex"));

  // 幂等缓存与流水不含明文。
  const idem = db.prepare("SELECT * FROM register_requests").all() as unknown as Record<string, unknown>[];
  assert.equal(idem.length, 1);
  assert.ok(!JSON.stringify(idem[0]).includes(recoveryCode));
  assert.ok(!JSON.stringify(idem[0]).includes(LONG_PASSWORD));
  const flows = db.prepare("SELECT * FROM game_change_records").all() as unknown as Record<string, unknown>[];
  assert.equal(flows.length, 1);
  assert.equal(flows[0].money_delta, 10000);

  // 登录后可读持久状态。
  const login = await app.inject({
    method: "POST",
    url: "/api/auth/login",
    payload: { loginName: "hero1234", password: LONG_PASSWORD },
  });
  assert.equal(login.statusCode, 200);
  const cookie = login.headers["set-cookie"];
  assert.ok(cookie);
  const sessionCookie = Array.isArray(cookie) ? cookie[0] : String(cookie);
  assert.match(sessionCookie, /hof_sid=.+;.*HttpOnly.*SameSite=Lax/);
  const me = await app.inject({ method: "GET", url: "/api/auth/me", headers: { cookie: sessionCookie.split(";")[0] } });
  assert.equal(me.statusCode, 200);
  const meBody = me.json() as Record<string, unknown>;
  assert.equal(meBody.accountId, body.accountId);
  assert.equal(meBody.money, "10000");
  await app.close();
  db.close();
});

test("输入严格校验：格式、弱密码与未知字段", async () => {
  const { app, db } = createTestApp();
  const badLogin = await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName: "ab", password: LONG_PASSWORD, requestId: requestId() },
  });
  assert.equal(badLogin.statusCode, 400);
  const shortPass = await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName: "validname1", password: "short", requestId: requestId() },
  });
  assert.equal(shortPass.statusCode, 400);
  const weakExact = await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName: "validname3", password: "passwordpassword", requestId: requestId() },
  });
  assert.equal(weakExact.statusCode, 422);
  const unknown = await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName: "validname4", password: LONG_PASSWORD, requestId: requestId(), admin: true },
  });
  assert.equal(unknown.statusCode, 400);
  await app.close();
  db.close();
});

test("同一请求身份重复提交返回原结果且不重放恢复码；换参数拒绝", async () => {
  const { app, db } = createTestApp();
  const rid = requestId();
  const first = await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName: "replayuser", password: LONG_PASSWORD, requestId: rid },
  });
  assert.equal(first.statusCode, 201);
  const firstBody = first.json() as Record<string, unknown>;
  const second = await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName: "replayuser", password: LONG_PASSWORD, requestId: rid },
  });
  assert.equal(second.statusCode, 200);
  const secondBody = second.json() as Record<string, unknown>;
  assert.equal(secondBody.accountId, firstBody.accountId);
  assert.equal(secondBody.replayed, true);
  assert.equal(secondBody.recoveryCode, undefined);
  const accounts = db.prepare("SELECT COUNT(*) AS n FROM accounts").get() as unknown as { n: number };
  assert.equal(accounts.n, 1);

  const conflict = await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName: "otheruser99", password: LONG_PASSWORD, requestId: rid },
  });
  assert.equal(conflict.statusCode, 409);
  const changedPassword = await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName: "replayuser", password: "another-long-password-12345", requestId: rid },
  });
  assert.equal(changedPassword.statusCode, 409);
  await app.close();
  db.close();
});

test("并发用户名争用只成功一次，失败无残片", async () => {
  const { app, db } = createTestApp();
  const [a, b] = await Promise.all([
    app.inject({ method: "POST", url: "/api/auth/register", payload: { loginName: "raceuser1", password: LONG_PASSWORD, requestId: requestId() } }),
    app.inject({ method: "POST", url: "/api/auth/register", payload: { loginName: "RACEUSER1", password: LONG_PASSWORD, requestId: requestId() } }),
  ]);
  const codes = [a.statusCode, b.statusCode].sort();
  assert.deepEqual(codes, [201, 409]);
  const accounts = db.prepare("SELECT * FROM accounts").all() as unknown as Record<string, unknown>[];
  assert.equal(accounts.length, 1);
  const assets = db.prepare("SELECT * FROM account_assets").all() as unknown as unknown[];
  assert.equal(assets.length, 1);
  const flows = db.prepare("SELECT * FROM game_change_records").all() as unknown as unknown[];
  assert.equal(flows.length, 1);
  await app.close();
  db.close();
});

test("登录大小写不敏感；错误凭据返回一致说明；登出后会话失效", async () => {
  const { app, db } = createTestApp();
  await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName: "CaseUser22", password: LONG_PASSWORD, requestId: requestId() },
  });
  const login = await app.inject({
    method: "POST",
    url: "/api/auth/login",
    payload: { loginName: "caseuser22", password: LONG_PASSWORD },
  });
  assert.equal(login.statusCode, 200);
  const cookie = String((login.headers["set-cookie"] as string | string[] | undefined) ?? "");
  const jar = Array.isArray(login.headers["set-cookie"])
    ? (login.headers["set-cookie"] as string[])[0].split(";")[0]
    : cookie.split(";")[0];

  const wrong = await app.inject({
    method: "POST",
    url: "/api/auth/login",
    payload: { loginName: "caseuser22", password: "wrong-password-long-enough-1" },
  });
  assert.equal(wrong.statusCode, 401);
  const unknown = await app.inject({
    method: "POST",
    url: "/api/auth/login",
    payload: { loginName: "nouser9999", password: "wrong-password-long-enough-1" },
  });
  assert.equal(unknown.statusCode, 401);
  assert.equal((wrong.json() as { message: string }).message, (unknown.json() as { message: string }).message);

  const out = await app.inject({ method: "POST", url: "/api/auth/logout", headers: { cookie: jar } });
  assert.equal(out.statusCode, 200);
  const after = await app.inject({ method: "GET", url: "/api/auth/me", headers: { cookie: jar } });
  assert.equal(after.statusCode, 401);
  const noAuth = await app.inject({ method: "GET", url: "/api/auth/me" });
  assert.equal(noAuth.statusCode, 401);
  await app.close();
  db.close();
});

test("注册容量满时拒绝且无残片", async () => {
  const { app, db } = createTestApp(1);
  const first = await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName: "firstuser1", password: LONG_PASSWORD, requestId: requestId() },
  });
  assert.equal(first.statusCode, 201);
  const second = await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName: "seconduser", password: LONG_PASSWORD, requestId: requestId() },
  });
  assert.equal(second.statusCode, 422);
  const accounts = db.prepare("SELECT COUNT(*) AS n FROM accounts").get() as unknown as { n: number };
  assert.equal(accounts.n, 1);
  await app.close();
  db.close();
});

test("过期会话被拒绝（闲置与绝对边界）", async () => {
  const { app, db } = createTestApp();
  await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName: "expireuser", password: LONG_PASSWORD, requestId: requestId() },
  });
  const login = await app.inject({
    method: "POST",
    url: "/api/auth/login",
    payload: { loginName: "expireuser", password: LONG_PASSWORD },
  });
  const rawCookie = login.headers["set-cookie"];
  const jar = (Array.isArray(rawCookie) ? rawCookie[0] : String(rawCookie)).split(";")[0];
  // 人为回拨会话创建时间至 31 天前，触发绝对期限失效。
  db.prepare("UPDATE sessions SET created_at = ?, last_activity_at = ?").run(
    new Date(Date.now() - 31 * 24 * 60 * 60 * 1000).toISOString(),
    new Date(Date.now() - 31 * 24 * 60 * 60 * 1000).toISOString(),
  );
  const me = await app.inject({ method: "GET", url: "/api/auth/me", headers: { cookie: jar } });
  assert.equal(me.statusCode, 401);
  assert.equal((me.json() as { code: string }).code, "SESSION_EXPIRED");
  await app.close();
  db.close();
});

test("密码核验后凭据已改变时，旧核验结果不能签发会话", async () => {
  const { app, db } = createTestApp();
  const reg = await app.inject({
    method: "POST",
    url: "/api/auth/register",
    payload: { loginName: "credentialrace", password: LONG_PASSWORD, requestId: requestId() },
  });
  assert.equal(reg.statusCode, 201);
  const accountId = (reg.json() as { accountId: string }).accountId;
  const oldHash = (db.prepare("SELECT password_hash FROM accounts WHERE id = ?").get(accountId) as { password_hash: string }).password_hash;
  db.prepare("UPDATE accounts SET password_hash = ? WHERE id = ?").run("changed-credential", accountId);
  assert.throws(() => createSession(db, accountId, "test-token", oldHash));
  assert.equal((db.prepare("SELECT COUNT(*) AS n FROM sessions").get() as { n: number }).n, 0);
  await app.close();
  db.close();
});

test("共享出口的 100 个不同账号并发登录时，普通读取仍可用", async () => {
  const { app, db } = createTestApp();
  const passwordHash = await hashPassword(LONG_PASSWORD);
  const now = new Date().toISOString();
  const insertAccount = db.prepare(
    "INSERT INTO accounts (id, login_name, login_key, password_hash, recovery_hash, created_at) VALUES (?, ?, ?, ?, ?, ?)",
  );
  const insertAssets = db.prepare(
    "INSERT INTO account_assets (account_id, money, stamina, stamina_updated_at) VALUES (?, 10000, 100, ?)",
  );
  const names: string[] = [];
  for (let n = 0; n < 100; n++) {
    const id = `account-${n}`;
    const name = `player${String(n).padStart(4, "0")}`;
    names.push(name);
    insertAccount.run(id, name, name, passwordHash, "test-recovery-hash", now);
    insertAssets.run(id, now);
  }
  const logins = names.map((name) => app.inject({ method: "POST", url: "/api/auth/login", payload: { loginName: name, password: LONG_PASSWORD } }));
  const health = app.inject({ method: "GET", url: "/api/health" });
  const results = await Promise.all(logins);
  const healthResult = await health;
  results.forEach((result, n) => assert.equal(result.statusCode, 200, `第 ${n + 1} 个登录：${result.body}`));
  assert.equal(healthResult.statusCode, 200);
  await app.close();
  db.close();
});

test("同一登录目标的连续猜测受到独立限流", async () => {
  const { app, db } = createTestApp();
  for (let n = 0; n < 10; n++) {
    const result = await app.inject({
      method: "POST",
      url: "/api/auth/login",
      payload: { loginName: "targetuser", password: LONG_PASSWORD },
    });
    assert.equal(result.statusCode, 401);
  }
  const limited = await app.inject({
    method: "POST",
    url: "/api/auth/login",
    payload: { loginName: "targetuser", password: LONG_PASSWORD },
  });
  assert.equal(limited.statusCode, 429);
  await app.close();
  db.close();
});
