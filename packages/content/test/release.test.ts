import test, { type TestContext } from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { publishSnapshot } from "../src/publish.js";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(HERE, "..", "..", "..");
const CONTENT_DIR = path.join(REPO_ROOT, "content");

function publicationFixture(t: TestContext) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-publish-test-"));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  const files = new Map([
    ["jobs.json", Buffer.from('{"jobs":[]}\n')],
    ["assets/image.png", Buffer.from("fixture")],
    ["release.json", Buffer.from('{"releaseId":"a"}\n')],
  ]);
  return { dir, files, pointer: () => fs.readFileSync(path.join(dir, "current-release"), "utf8").trim() };
}

test("发布 A → B → 已有 A：切换指针但不重写快照", (t) => {
  const { dir, files, pointer } = publicationFixture(t);
  publishSnapshot(dir, "a", files);
  const manifestPath = path.join(dir, "releases/a/release.json");
  const before = fs.statSync(manifestPath, { bigint: true });
  publishSnapshot(dir, "b", new Map(files).set("release.json", Buffer.from('{"releaseId":"b"}\n')));
  assert.equal(pointer(), "b");
  publishSnapshot(dir, "a", files);
  assert.equal(pointer(), "a");
  const after = fs.statSync(manifestPath, { bigint: true });
  assert.equal(after.ino, before.ino);
  assert.equal(after.mtimeNs, before.mtimeNs);
});

test("快照写入中断不暴露半成品，旧指针保持有效，重试成功", (t) => {
  const { dir, files, pointer } = publicationFixture(t);
  publishSnapshot(dir, "old", files);
  const write = fs.writeFileSync;
  const fault = t.mock.method(fs, "writeFileSync", (...args: Parameters<typeof fs.writeFileSync>) => {
    if (String(args[0]).endsWith("image.png")) throw new Error("injected write failure");
    return write(...args);
  });
  assert.throws(() => publishSnapshot(dir, "new", files), /injected write failure/);
  fault.mock.restore();
  assert.equal(pointer(), "old");
  assert.equal(fs.existsSync(path.join(dir, "releases/new")), false);
  assert.ok(!fs.readdirSync(dir).some((name) => name.startsWith(".release-")));
  publishSnapshot(dir, "new", files);
  assert.equal(pointer(), "new");
  for (const [name, data] of files) assert.deepEqual(fs.readFileSync(path.join(dir, "releases/new", name)), data);
});

test("快照安装后指针切换失败：重试复用完整快照并切换指针", (t) => {
  const { dir, files, pointer } = publicationFixture(t);
  publishSnapshot(dir, "old", files);
  const rename = fs.renameSync;
  const fault = t.mock.method(fs, "renameSync", (...args: Parameters<typeof fs.renameSync>) => {
    if (String(args[1]) === path.join(dir, "current-release")) throw new Error("injected pointer failure");
    return rename(...args);
  });
  assert.throws(() => publishSnapshot(dir, "new", files), /injected pointer failure/);
  fault.mock.restore();
  assert.equal(pointer(), "old");
  for (const [name, data] of files) assert.deepEqual(fs.readFileSync(path.join(dir, "releases/new", name)), data);
  publishSnapshot(dir, "new", files);
  assert.equal(pointer(), "new");
});

test("已有快照内容不一致时拒绝覆盖且不切换指针", (t) => {
  const { dir, files, pointer } = publicationFixture(t);
  publishSnapshot(dir, "a", files);
  publishSnapshot(dir, "b", files);
  assert.throws(
    () => publishSnapshot(dir, "a", new Map(files).set("jobs.json", Buffer.from("changed"))),
    /不可变快照不得原地改写/,
  );
  assert.equal(pointer(), "b");
  assert.deepEqual(fs.readFileSync(path.join(dir, "releases/a/jobs.json")), files.get("jobs.json"));
});

const EDIT_FILES = [
  "recruitment.json",
  "jobs.json",
  "items.json",
  "skills.json",
  "conditions.json",
  "monsters.json",
  "maps.json",
  "assets.json",
];

const sha256Hex = (data: Buffer | string): string => crypto.createHash("sha256").update(data).digest("hex");

function maxMigrationVersion(): number {
  const dir = path.join(REPO_ROOT, "apps", "backend", "migrations");
  return Math.max(
    ...fs
      .readdirSync(dir)
      .filter((f) => /^\d{4}_[a-z0-9_]+\.sql$/.test(f))
      .map((f) => Number(f.slice(0, 4))),
  );
}

test("发布身份由编辑源与版本配对共同派生", () => {
  // 回归 tripwire：若实现把配对元数据移出身份推导，本测试失败。
  // 键顺序须与 build.ts 的 pairing 字面量一致。
  const releaseId = fs.readFileSync(path.join(CONTENT_DIR, "current-release"), "utf8").trim();
  const release = JSON.parse(
    fs.readFileSync(path.join(CONTENT_DIR, "releases", releaseId, "release.json"), "utf8"),
  ) as Record<string, unknown>;

  const pairing = {
    schemaVersion: 1,
    engine: "s1-engine-0",
    randomProtocol: "s1-random-0",
    eventFormat: "s1-event-0",
    compatibleDbSchema: maxMigrationVersion(),
  };
  assert.deepEqual(
    { schemaVersion: release.schemaVersion, engine: release.engine, randomProtocol: release.randomProtocol, eventFormat: release.eventFormat, compatibleDbSchema: release.compatibleDbSchema },
    pairing,
  );

  const editHashes = EDIT_FILES.map((f) => `${f}:${sha256Hex(fs.readFileSync(path.join(CONTENT_DIR, f)))}`).sort();
  const sourceHash = sha256Hex(editHashes.join("\n") + "\n");
  assert.equal(release.sourceHash, `sha256:${sourceHash}`);
  assert.equal(releaseId, `s1-${sha256Hex(`${sourceHash}\n${JSON.stringify(pairing)}\n`).slice(0, 12)}`);
});

test("快照内容摘要与文件清单一致", () => {
  const releaseId = fs.readFileSync(path.join(CONTENT_DIR, "current-release"), "utf8").trim();
  const snapshotDir = path.join(CONTENT_DIR, "releases", releaseId);
  const release = JSON.parse(fs.readFileSync(path.join(snapshotDir, "release.json"), "utf8")) as {
    contentHash: string;
    files: { path: string; sha256: string; bytes: number }[];
  };
  assert.equal(release.files.length, 29);
  for (const f of release.files) {
    const data = fs.readFileSync(path.join(REPO_ROOT, f.path));
    assert.equal(data.length, f.bytes, f.path);
    assert.equal(sha256Hex(data), f.sha256, f.path);
  }
  const recomputed = `sha256:${sha256Hex(release.files.map((f) => `${f.path}:${f.sha256}`).join("\n") + "\n")}`;
  assert.equal(release.contentHash, recomputed);
});
