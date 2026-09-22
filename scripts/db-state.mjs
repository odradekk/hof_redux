#!/usr/bin/env node
/**
 * 只读读取数据库的可观察状态：迁移登记（version/name/sha256/applied_at）
 * 与业务表清单。供重启持久性与空库迁移检查使用；只读连接，不写任何数据。
 * 用法：node scripts/db-state.mjs <db-path>
 */
import { DatabaseSync } from "node:sqlite";

const dbPath = process.argv[2];
if (!dbPath) {
  console.error("用法：node scripts/db-state.mjs <db-path>");
  process.exit(2);
}

let db;
try {
  db = new DatabaseSync(dbPath, { readOnly: true });
} catch (err) {
  console.error(`无法只读打开数据库 ${dbPath}：${err.message}`);
  process.exit(1);
}

const state = {
  migrations: db
    .prepare("SELECT version, name, sha256, applied_at FROM schema_migrations ORDER BY version")
    .all(),
  tables: db
    .prepare("SELECT name FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
    .all()
    .map((row) => row.name),
};
db.close();
console.log(JSON.stringify(state));
