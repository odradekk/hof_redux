import test from "node:test";
import assert from "node:assert/strict";
import { decodeHealth, decodeVersion } from "../src/api.ts";

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
