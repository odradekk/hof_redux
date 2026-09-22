#!/usr/bin/env node
/**
 * 重启持久性检查（写侧）：向 baseline_probe 写入一行随机探针值并输出。
 * 用法：node scripts/probe-write.mjs <db-path>
 */
import { randomUUID } from "node:crypto";
import { DatabaseSync } from "node:sqlite";

const dbPath = process.argv[2];
if (!dbPath) {
  console.error("用法：node scripts/probe-write.mjs <db-path>");
  process.exit(2);
}

const token = randomUUID();
const db = new DatabaseSync(dbPath);
db.exec("PRAGMA busy_timeout=10000");
const row = db.prepare("SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1").get();
if (!row) {
  console.error("目标库尚未执行迁移，拒绝写入探针");
  process.exit(1);
}
db.prepare("INSERT OR REPLACE INTO baseline_probe (id, note, written_at) VALUES (1, ?, ?)").run(
  token,
  new Date().toISOString(),
);
db.close();
console.log(JSON.stringify({ token, schemaVersion: row.version }));
