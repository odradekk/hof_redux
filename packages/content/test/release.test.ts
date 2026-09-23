import test from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(HERE, "..", "..", "..");
const CONTENT_DIR = path.join(REPO_ROOT, "content");

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
