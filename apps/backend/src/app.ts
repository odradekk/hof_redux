import Fastify, { type FastifyInstance } from "fastify";
import type { DatabaseSync } from "node:sqlite";
import type { HealthResponse, VersionResponse } from "@hof/shared";
import type { AppConfig } from "./config.js";
import type { ContentManifest } from "./contentManifest.js";
import { registerAuthRoutes } from "./auth/routes.js";
import { getRecoveryEpoch } from "./auth/store.js";

/**
 * 构建应用（HTTP 入口只做协议解析、身份传递、调用及结果映射；
 * 业务规则由所属模块完成，不在处理器中拼接扣款/建角色/发物品）。
 * 写对象校验由各路由手动严格执行：不做类型转换、不补默认值、不删未知字段。
 */
export function buildApp(
  db: DatabaseSync,
  config: AppConfig,
  content: ContentManifest,
  schemaVersion: number,
): FastifyInstance {
  const app = Fastify({
    logger: { level: "silent" },
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
      recoveryEpoch: getRecoveryEpoch(db),
    };
  });

  registerAuthRoutes(app, { db, releaseId: content.releaseId, maxUsers: config.maxUsers });

  // 未实现路径返回 404（未交付操作不模拟成功，接口亦拒绝未支持命令）。
  app.setNotFoundHandler(async (request, reply) => {
    if (request.url.startsWith("/api/")) {
      void reply.status(404).send({ code: "NOT_FOUND", message: "接口不存在或尚未开放" });
      return;
    }
    void reply.status(404).send({ code: "NOT_FOUND", message: "不存在" });
  });

  return app;
}
