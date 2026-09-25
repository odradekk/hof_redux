import test from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";
import { runMigrations } from "../src/db/migrate.js";

const MIGRATIONS_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../migrations");

interface MigrationRow {
  version: number;
  name: string;
  sha256: string;
  applied_at: string;
}

function businessTables(db: DatabaseSync): string[] {
  const rows = db
    .prepare("SELECT name FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
    .all() as unknown as { name: string }[];
  return rows.map((r) => r.name);
}

function migrationRows(db: DatabaseSync): MigrationRow[] {
  return db
    .prepare("SELECT version, name, sha256, applied_at FROM schema_migrations ORDER BY version")
    .all() as unknown as MigrationRow[];
}

function expectedFiles(): { version: number; name: string; sha256: string }[] {
  return fs
    .readdirSync(MIGRATIONS_DIR)
    .filter((f) => /^\d{4}_[a-z0-9_]+\.sql$/.test(f))
    .sort()
    .map((f) => ({
      version: Number(f.slice(0, 4)),
      name: f,
      sha256: crypto.createHash("sha256").update(fs.readFileSync(path.join(MIGRATIONS_DIR, f), "utf8"), "utf8").digest("hex"),
    }));
}

/** 造一个“旧 v1 本地库”：只应用过 0001，并按当时的记录方式登记。 */
function legacyV1Database(): { db: DatabaseSync; dir: string } {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-migrate-"));
  const db = new DatabaseSync(":memory:");
  const first = expectedFiles()[0];
  db.exec(`CREATE TABLE schema_migrations (
    version INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    sha256 TEXT NOT NULL,
    applied_at TEXT NOT NULL
  ) STRICT`);
  db.exec("BEGIN IMMEDIATE");
  db.exec(fs.readFileSync(path.join(MIGRATIONS_DIR, first.name), "utf8"));
  db.prepare("INSERT INTO schema_migrations (version, name, sha256, applied_at) VALUES (?, ?, ?, ?)").run(
    first.version,
    first.name,
    first.sha256,
    "2026-09-01T00:00:00.000Z",
  );
  db.exec("COMMIT");
  return { db, dir };
}

test("空库按序应用全部迁移，记录与磁盘文件一致", () => {
  const db = new DatabaseSync(":memory:");
  const version = runMigrations(db, MIGRATIONS_DIR);
  const expected = expectedFiles();
  assert.equal(version, expected[expected.length - 1].version);
  assert.deepEqual(
    migrationRows(db).map(({ version: v, name, sha256 }) => ({ version: v, name, sha256 })),
    expected,
  );
  assert.equal(businessTables(db).includes("schema_migrations"), true);
});

test("迁移后的业务 schema 为账号基线表集合（无测试设施表）", () => {
  const db = new DatabaseSync(":memory:");
  runMigrations(db, MIGRATIONS_DIR);
  // #24 起生产 schema 包含账号/资产/会话/幂等/流水/元数据表；探针表已在 0002 移除。
  assert.deepEqual(businessTables(db), [
    "account_assets",
    "accounts",
    "app_meta",
    "game_change_records",
    "register_requests",
    "schema_migrations",
    "sessions",
  ]);
});

test("重复执行幂等：记录（含 applied_at）不变", () => {
  const db = new DatabaseSync(":memory:");
  const first = runMigrations(db, MIGRATIONS_DIR);
  const rowsAfterFirst = migrationRows(db);
  const second = runMigrations(db, MIGRATIONS_DIR);
  assert.equal(second, first);
  assert.deepEqual(migrationRows(db), rowsAfterFirst);
});

test("已应用迁移内容漂移时拒绝启动", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-migrate-"));
  fs.cpSync(MIGRATIONS_DIR, dir, { recursive: true });
  const db = new DatabaseSync(":memory:");
  runMigrations(db, dir);
  const first = expectedFiles()[0];
  fs.appendFileSync(path.join(dir, first.name), "\n-- 漂移\n");
  assert.throws(() => runMigrations(db, dir), /不一致|漂移|拒绝/);
});

test("编号低于已应用版本的新文件属于乱序提交，拒绝启动", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-migrate-"));
  fs.cpSync(MIGRATIONS_DIR, dir, { recursive: true });
  const db = new DatabaseSync(":memory:");
  runMigrations(db, dir);
  fs.writeFileSync(path.join(dir, "0000_late.sql"), "CREATE TABLE late (id INTEGER PRIMARY KEY) STRICT;\n");
  assert.throws(() => runMigrations(db, dir), /乱序|低于|拒绝/);
});

test("旧 v1 本地库升级：0001 记录原样保留，后续迁移继续应用", () => {
  const { db } = legacyV1Database();
  const version = runMigrations(db, MIGRATIONS_DIR);
  const expected = expectedFiles();
  assert.equal(version, expected[expected.length - 1].version);
  const rows = migrationRows(db);
  // v1 记录不被重写。
  assert.equal(rows[0].name, expected[0].name);
  assert.equal(rows[0].sha256, expected[0].sha256);
  assert.equal(rows[0].applied_at, "2026-09-01T00:00:00.000Z");
  assert.equal(rows.length, expected.length);
  // 升级后的 schema 与空库迁移结果一致。
  assert.deepEqual(businessTables(db), [
    "account_assets",
    "accounts",
    "app_meta",
    "game_change_records",
    "register_requests",
    "schema_migrations",
    "sessions",
  ]);
});
