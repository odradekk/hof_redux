import fs from "node:fs";
import path from "node:path";
import { DatabaseSync } from "node:sqlite";

/**
 * 打开并校验全应用唯一的业务写连接。
 * 约定：整个进程只有这一个业务连接（运行契约 Q18），短 BEGIN IMMEDIATE 事务，
 * WAL + synchronous=FULL + 外键开启，启动时读回校验，失败拒绝启动。
 */
export function openBusinessDatabase(dbPath: string): DatabaseSync {
  fs.mkdirSync(path.dirname(dbPath), { recursive: true });
  const db = new DatabaseSync(dbPath);
  db.exec("PRAGMA journal_mode=WAL");
  db.exec("PRAGMA synchronous=FULL");
  db.exec("PRAGMA foreign_keys=ON");
  db.exec("PRAGMA busy_timeout=5000");

  const journalMode = (db.prepare("PRAGMA journal_mode").get() as { journal_mode: string }).journal_mode;
  const synchronous = (db.prepare("PRAGMA synchronous").get() as { synchronous: number }).synchronous;
  const foreignKeys = (db.prepare("PRAGMA foreign_keys").get() as { foreign_keys: number }).foreign_keys;

  if (journalMode.toLowerCase() !== "wal") {
    throw new Error(`SQLite 未进入 WAL 模式（实际：${journalMode}），拒绝启动`);
  }
  if (synchronous !== 2) {
    throw new Error(`SQLite synchronous 不是 FULL（实际：${synchronous}），拒绝启动`);
  }
  if (foreignKeys !== 1) {
    throw new Error("SQLite 外键约束未开启，拒绝启动");
  }
  return db;
}
