import test from "node:test";
import assert from "node:assert/strict";
import { decodeHealth, decodeLogin, decodeMe, decodeRegister, decodeVersion } from "../src/api.ts";

const goodVersion = {
  app: { name: "@hof/backend", version: "0.1.0" },
  content: { releaseId: "s1-test", schemaVersion: 1, contentHash: `sha256:${"ab".repeat(32)}` },
  database: { schemaVersion: 2 },
};

test("decodeHealth 接受契约内响应", () => {
  assert.deepEqual(decodeHealth({ status: "ok", now: "2026-09-22T00:00:00.000Z" }), {
    status: "ok",
    now: "2026-09-22T00:00:00.000Z",
  });
});

test("decodeHealth 拒绝非对象与非法字段", () => {
  for (const bad of [null, "ok", [], { status: "ok" }, { status: "degraded", now: "x" }, { status: "ok", now: "" }]) {
    assert.throws(() => decodeHealth(bad), /api\/health/);
  }
});

test("decodeVersion 接受契约内响应", () => {
  assert.deepEqual(decodeVersion(goodVersion), goodVersion);
});

test("decodeVersion 拒绝缺字段与非法类型", () => {
  const cases: unknown[] = [
    null,
    "x",
    { app: { name: "a", version: "0.1.0" } },
    { ...goodVersion, app: { name: "a" } },
    { ...goodVersion, content: { releaseId: "r", schemaVersion: 0 } },
    { ...goodVersion, content: { releaseId: "r", schemaVersion: "1" } },
    { ...goodVersion, content: { releaseId: "r", schemaVersion: 1 } },
    { ...goodVersion, content: { releaseId: "r", schemaVersion: 1, contentHash: "nope" } },
    { ...goodVersion, database: { schemaVersion: 1.5 } },
  ];
  for (const bad of cases) {
    assert.throws(() => decodeVersion(bad), /api\/version/);
  }
});

const account = { accountId: "account-1", loginName: "hero1234", teamCompleted: false, money: "10000", stamina: 100 };

test("账号响应解码拒绝错误类型，不将坏数据静默改成默认值", () => {
  const login = { ...account, sessionExpiresAt: "2026-10-25T00:00:00.000Z", releaseId: "s1-test", recoveryEpoch: 1 };
  const me = { ...account, recoveryGeneration: 1, createdAt: "2026-09-25T00:00:00.000Z", releaseId: "s1-test", recoveryEpoch: 1 };
  assert.deepEqual(decodeLogin(login), login);
  assert.deepEqual(decodeMe(me), me);
  for (const bad of [
    { ...login, teamCompleted: "false" },
    { ...login, stamina: "100" },
    { ...login, recoveryEpoch: "1" },
    { ...me, recoveryGeneration: "1" },
    { ...me, stamina: -1 },
  ]) {
    assert.throws(() => ("sessionExpiresAt" in bad ? decodeLogin(bad) : decodeMe(bad)), /api\/auth/);
  }
});

test("注册响应区分首次发码与幂等重放", () => {
  const base = { ...account, recoveryGeneration: 1, createdAt: "2026-09-25T00:00:00.000Z", releaseId: "s1-test", recoveryEpoch: 1 };
  assert.equal(decodeRegister({ ...base, recoveryCode: "saved-once" }).recoveryCode, "saved-once");
  assert.equal(decodeRegister({ ...base, replayed: true }).replayed, true);
  for (const bad of [
    base,
    { ...base, recoveryCode: "saved-once", replayed: true },
    { ...base, recoveryCode: 123 },
  ]) {
    assert.throws(() => decodeRegister(bad), /api\/auth\/register/);
  }
});
