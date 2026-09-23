/**
 * 前后端共享接口契约（S1 工程基线）。
 * 仅包含本阶段已交付的最小端点形状；玩法契约随对应模块任务扩展。
 * 后端返回必须满足这些类型；前端消费时据此校验。
 */

/** GET /api/health —— 对外只暴露最小状态；应用存活且业务数据库可读时为 ok。 */
export interface HealthResponse {
  status: "ok";
  /** 服务器生成响应的 UTC 时刻（ISO 8601）。 */
  now: string;
}

/** GET /api/version —— 应用 / 内容 / 数据库三类版本。 */
export interface VersionResponse {
  app: {
    name: string;
    /** 应用构建版本（后端包版本）。 */
    version: string;
  };
  content: {
    /** 已加载内容发布快照标识；首批内容包随 #23 以不可变快照发布。 */
    releaseId: string;
    schemaVersion: number;
    /** 快照内容摘要（排序后的 path:sha256 清单哈希），用于区分同名不同内容的发布。 */
    contentHash: string;
  };
  database: {
    /** 已应用的最高迁移版本号。 */
    schemaVersion: number;
  };
}
