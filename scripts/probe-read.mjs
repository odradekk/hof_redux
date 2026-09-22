#!/usr/bin/env node
/**
 * 重启持久性检查（读侧）：读取 baseline_probe 探针行并输出。
 * 用法：node scripts/probe-read.mjs <db-path>
 */
import { DatabaseSync } from "node:sqlite";

const dbPath = process.argv[2];
if (!dbPath) {
  console.error("用法：node scripts/probe-read.mjs <db-path>");
  process.exit(2);
}

const db = new DatabaseSync(dbPath, { readOnly: true });
const row = db.prepare("SELECT note, written_at FROM baseline_probe WHERE id = 1").get();
db.close();
if (!row) {
  console.error("探针行不存在");
  process.exit(1);
}
console.log(JSON.stringify(row));
