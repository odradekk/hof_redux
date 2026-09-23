import Fastify from "fastify";
import type { HealthResponse, VersionResponse } from "@hof/shared";
import { loadConfig } from "./config.js";
import { loadContentManifest } from "./contentManifest.js";
import { openBusinessDatabase } from "./db/database.js";
import { InstanceLock } from "./db/instanceLock.js";
import { runMigrations } from "./db/migrate.js";

const config = loadConfig();

// 启动互斥：锁位已存在（含崩溃残留）一律拒绝；恢复由操作者确认旧实例停止后删锁。
const lock = new InstanceLock(`${config.dbPath}.instance.lock`, config.dbPath);
lock.acquire();

// 单一业务写连接：WAL / synchronous=FULL / 外键开启，读回校验。
const db = openBusinessDatabase(config.dbPath);
const schemaVersion = runMigrations(db, config.migrationsDir);
// 内容快照门控：版本组合与数据库版本一致后才受理业务。
const content = loadContentManifest(config.contentManifestPath, { expectedDbSchema: schemaVersion });

const app = Fastify({
  logger: { level: "info" },
  trustProxy: config.trustProxy,
});

app.get("/api/health", async (): Promise<HealthResponse> => {
  // 应用健康以业务数据库可读为前提；失败时 fastify 返回 500，入口视为不健康。
  db.prepare("SELECT 1 AS ok").get();
  return { status: "ok", now: new Date().toISOString() };
});

app.get("/api/version", async (): Promise<VersionResponse> => {
  return {
    app: { name: "@hof/backend", version: config.appVersion },
    content: { releaseId: content.releaseId, schemaVersion: content.schemaVersion, contentHash: content.contentHash },
    database: { schemaVersion },
  };
});

async function shutdown(signal: string): Promise<void> {
  app.log.info({ signal }, "收到停止信号，开始安全关闭");
  try {
    await app.close();
  } finally {
    db.close();
    lock.release();
  }
  process.exit(0);
}
process.on("SIGINT", () => void shutdown("SIGINT"));
process.on("SIGTERM", () => void shutdown("SIGTERM"));

app.log.info(
  { dbPath: config.dbPath, schemaVersion, contentRelease: content.releaseId, appVersion: config.appVersion },
  "启动完成：迁移与版本核对通过（启动不执行清库/补发资产/重置身份）",
);
await app.listen({ host: config.host, port: config.port });
