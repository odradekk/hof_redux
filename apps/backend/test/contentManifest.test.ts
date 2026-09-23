import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { loadContentManifest, resolveSnapshotManifest } from "../src/contentManifest.js";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(HERE, "..", "..", "..");
const CONTENT_DIR = path.join(REPO_ROOT, "content");

function snapshotManifest(): string {
  return resolveSnapshotManifest(CONTENT_DIR);
}

function snapshotReleaseId(): string {
  return fs.readFileSync(path.join(CONTENT_DIR, "current-release"), "utf8").trim();
}

test("当前快照清单加载通过：发布身份、摘要与文件数", () => {
  const manifest = loadContentManifest(snapshotManifest(), { expectedDbSchema: 2 });
  assert.match(manifest.releaseId, /^s1-[0-9a-f]{12}$/);
  assert.equal(manifest.releaseId, snapshotReleaseId());
  assert.equal(manifest.schemaVersion, 1);
  assert.ok(manifest.contentHash.startsWith("sha256:"));
  assert.equal(manifest.fileCount, 29);
});

test("快照内文件被篡改时拒绝启动", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-manifest-neg-"));
  fs.cpSync(CONTENT_DIR, path.join(dir, "content"), { recursive: true });
  const id = fs.readFileSync(path.join(dir, "content", "current-release"), "utf8").trim();
  const jobsPath = path.join(dir, "content", "releases", id, "jobs.json");
  fs.writeFileSync(jobsPath, fs.readFileSync(jobsPath, "utf8") + "\n");
  assert.throws(
    () => loadContentManifest(path.join(dir, "content", "releases", id, "release.json"), { expectedDbSchema: 2 }),
    /字节数|摘要/,
  );
  fs.rmSync(dir, { recursive: true, force: true });
});

test("不支持的版本组合被拒绝", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-manifest-neg-"));
  fs.cpSync(CONTENT_DIR, path.join(dir, "content"), { recursive: true });
  const id = fs.readFileSync(path.join(dir, "content", "current-release"), "utf8").trim();
  const manifestPath = path.join(dir, "content", "releases", id, "release.json");
  const raw = JSON.parse(fs.readFileSync(manifestPath, "utf8")) as Record<string, unknown>;
  // schemaVersion=999
  fs.writeFileSync(manifestPath, JSON.stringify({ ...raw, schemaVersion: 999 }));
  assert.throws(() => loadContentManifest(manifestPath, { expectedDbSchema: 2 }), /不受支持/);
  // engine 不支持
  fs.writeFileSync(manifestPath, JSON.stringify({ ...raw, engine: "s9-engine-9" }));
  assert.throws(() => loadContentManifest(manifestPath, { expectedDbSchema: 2 }), /不受支持/);
  // 数据库版本不一致
  fs.writeFileSync(manifestPath, JSON.stringify(raw));
  assert.throws(() => loadContentManifest(manifestPath, { expectedDbSchema: 999 }), /compatibleDbSchema/);
  fs.rmSync(dir, { recursive: true, force: true });
});

test("非快照布局的清单被拒绝", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-manifest-neg-"));
  const contentDir = path.join(dir, "content");
  fs.mkdirSync(contentDir, { recursive: true });
  fs.writeFileSync(
    path.join(contentDir, "release.json"),
    JSON.stringify({ releaseId: "s1-baseline-empty", schemaVersion: 1, files: [] }),
  );
  assert.throws(() => loadContentManifest(path.join(contentDir, "release.json")), /快照|contentHash|为空/);
  fs.rmSync(dir, { recursive: true, force: true });
});
