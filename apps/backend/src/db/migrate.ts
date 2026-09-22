import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import type { DatabaseSync } from "node:sqlite";

const MIGRATION_FILE = /^(\d{4})_[a-z0-9_]+\.sql$/;

interface AppliedMigration {
  version: number;
  name: string;
  sha256: string;
}

/**
 * 从空库（或已有库）按序执行带版本的迁移，返回已应用的最高版本号。
 * - 每个迁移在单独 BEGIN IMMEDIATE 事务中执行并登记，失败即回滚并拒绝启动。
 * - 已应用迁移的文件名或内容摘要不一致视为危险漂移，拒绝启动而不是静默跳过。
 * - 编号小于等于当前最高版本的“新文件”属于乱序提交，拒绝启动。
 */
export function runMigrations(db: DatabaseSync, migrationsDir: string): number {
  db.exec(`CREATE TABLE IF NOT EXISTS schema_migrations (
    version INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    sha256 TEXT NOT NULL,
    applied_at TEXT NOT NULL
  ) STRICT`);

  const applied = new Map<number, AppliedMigration>();
  for (const row of db.prepare("SELECT version, name, sha256 FROM schema_migrations").all() as unknown as AppliedMigration[]) {
    applied.set(row.version, row);
  }

  const files = fs
    .readdirSync(migrationsDir)
    .filter((f) => MIGRATION_FILE.test(f))
    .sort();

  let maxVersion = Math.max(0, ...applied.keys());

  for (const file of files) {
    const version = Number(file.slice(0, 4));
    const content = fs.readFileSync(path.join(migrationsDir, file), "utf8");
    const sha256 = crypto.createHash("sha256").update(content, "utf8").digest("hex");

    const existing = applied.get(version);
    if (existing) {
      if (existing.name !== file || existing.sha256 !== sha256) {
        throw new Error(`迁移 ${version} 与已应用记录不一致（库内 ${existing.name}，磁盘 ${file}），拒绝启动`);
      }
      continue;
    }
    if (version <= maxVersion) {
      throw new Error(`迁移 ${file} 编号低于已应用版本 ${maxVersion}，拒绝启动`);
    }

    db.exec("BEGIN IMMEDIATE");
    try {
      db.exec(content);
      db.prepare("INSERT INTO schema_migrations (version, name, sha256, applied_at) VALUES (?, ?, ?, ?)").run(
        version,
        file,
        sha256,
        new Date().toISOString(),
      );
      db.exec("COMMIT");
    } catch (err) {
      db.exec("ROLLBACK");
      throw err;
    }
    maxVersion = version;
  }

  return maxVersion;
}
