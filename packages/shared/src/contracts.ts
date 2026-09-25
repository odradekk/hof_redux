/**
 * 前后端共享接口契约（S1 工程基线 + #24 账号）。
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
  /** 灾难恢复代次：旧备份恢复产生新值，旧页面请求失效（#24 起返回，初始为 1）。 */
  recoveryEpoch?: number;
}

/** 账号契约常量（#24）：与 docs/design/accounts.md 一致。 */
export const ACCOUNT_CONTRACT = {
  /** 登录名：4–16 位 ASCII 字母或数字。 */
  loginNamePattern: "^[A-Za-z0-9]{4,16}$",
  /** 密码按 Unicode 码点计数。 */
  passwordMinLength: 15,
  passwordMaxLength: 128,
  /** 请求身份：客户端为一次明确提交生成的高熵标识。 */
  requestIdPattern: "^[A-Za-z0-9_-]{16,64}$",
  /** 注册发放：旧初始金钱与体力（S1 规格已确认）。 */
  initialMoney: "10000",
  initialStamina: 100,
  /** 注册容量默认值（旧 MAX_USERS，可配置覆盖）。 */
  defaultMaxUsers: 500,
  /** 玩家会话：闲置 7 天、绝对 30 天（含等于边界）。 */
  sessionIdleMs: 7 * 24 * 60 * 60 * 1000,
  sessionAbsoluteMs: 30 * 24 * 60 * 60 * 1000,
  /** 会话 Cookie 名（玩家作用域，与管理员作用域分开）。 */
  sessionCookieName: "hof_sid",
} as const;

/** POST /api/auth/register 请求。 */
export interface RegisterRequest {
  loginName: string;
  password: string;
  /** 同一按钮点击的网络重试复用；换参数或换操作者拒绝。 */
  requestId: string;
}

/** POST /api/auth/register 响应。 */
export interface RegisterResponse {
  accountId: string;
  loginName: string;
  /** 金钱以十进制整数字符串传输（接口契约数值规则）。 */
  money: string;
  stamina: number;
  recoveryGeneration: number;
  /** 恢复码明文：仅首次成功创建时返回；幂等重放不重放明文。 */
  recoveryCode?: string;
  /** true 表示本次为同请求身份的幂等重放（恢复码已不在响应中）。 */
  replayed?: boolean;
  teamCompleted: boolean;
  createdAt: string;
  releaseId: string;
  recoveryEpoch: number;
}

/** POST /api/auth/login 请求。 */
export interface LoginRequest {
  loginName: string;
  password: string;
}

/** POST /api/auth/login 响应（会话令牌经 HttpOnly Cookie 下发；JSON 不含令牌明文）。 */
export interface LoginResponse {
  accountId: string;
  loginName: string;
  teamCompleted: boolean;
  money: string;
  stamina: number;
  sessionExpiresAt: string;
  releaseId: string;
  recoveryEpoch: number;
}

/** GET /api/auth/me 响应（重新登录后可读的持久状态）。 */
export interface MeResponse {
  accountId: string;
  loginName: string;
  teamCompleted: boolean;
  money: string;
  stamina: number;
  recoveryGeneration: number;
  createdAt: string;
  releaseId: string;
  recoveryEpoch: number;
}

/** 稳定错误代码（HTTP 状态另按接口契约映射）。 */
export type AuthErrorCode =
  | "INVALID_INPUT"
  | "WEAK_PASSWORD"
  | "LOGIN_TAKEN"
  | "CAPACITY_FULL"
  | "INVALID_CREDENTIALS"
  | "UNAUTHORIZED"
  | "SESSION_EXPIRED"
  | "REQUEST_CONFLICT"
  | "RATE_LIMITED";


/** 首次建队契约常量（#25）：与 docs/design/accounts.md 名称规则一致。 */
export const PARTY_CONTRACT = {
  /** 队伍名/角色名按 Unicode 码点计数。 */
  nameMinLength: 1,
  nameMaxLength: 16,
  /** S1 可选招募模板（战士/法师）。 */
  recruitIds: ["recruit.1", "recruit.2"],
  genders: ["male", "female"],
} as const;

/** POST /api/party/first 请求：一次业务提交获得队伍与免费首角。 */
export interface FirstPartyRequest {
  teamName: string;
  characterName: string;
  /** S1 仅 recruit.1（战士）/ recruit.2（法师）。 */
  recruitId: string;
  gender: string;
  /** 同一按钮点击的网络重试复用；换参数或换操作者拒绝。 */
  requestId: string;
}

/** 角色只读视图（#25 建队结果与 GET /api/party/mine 共用）。 */
export interface PartyCharacterView {
  characterId: string;
  name: string;
  jobId: string;
  jobName: string;
  gender: string;
  level: number;
  experience: number;
  maxHp: number;
  hp: number;
  maxSp: number;
  sp: number;
  stats: { str: number; int: number; dex: number; spd: number; luk: number };
  /** 未分配属性点 / 技能点（S1 只读展示，培养编辑后续开放）。 */
  unassignedAp: number;
  unassignedSp: number;
  skills: Array<{ skillId: string; name: string }>;
  equipment: Array<{ equipmentId: string; definitionId: string; name: string; slot: string }>;
  position: string;
  guardPolicy: { kind: string };
  defaultTactics: Array<{
    conditions: Array<{ conditionId: string; description: string; quantity: number }>;
    skillId: string;
    skillName: string;
  }>;
}

/** POST /api/party/first 响应。 */
export interface FirstPartyResponse {
  teamName: string;
  character: PartyCharacterView;
  /** true 表示本次为同请求身份的幂等重放。 */
  replayed?: boolean;
  releaseId: string;
  recoveryEpoch: number;
}

/** GET /api/party/mine 响应（未建队时 teamName/character 为 null）。 */
export interface MinePartyResponse {
  teamCompleted: boolean;
  teamName: string | null;
  character: PartyCharacterView | null;
  releaseId: string;
  recoveryEpoch: number;
}

/** 建队错误代码（与账号错误码共用 INVALID_INPUT / UNAUTHORIZED / REQUEST_CONFLICT / RATE_LIMITED）。 */
export type PartyErrorCode =
  | "INVALID_INPUT"
  | "UNAUTHORIZED"
  | "SESSION_EXPIRED"
  | "TEAM_NAME_TAKEN"
  | "PARTY_ALREADY_COMPLETED"
  | "TEAM_NOT_COMPLETED"
  | "REQUEST_CONFLICT"
  | "RATE_LIMITED"
  | "INTERNAL_ERROR";

export interface ErrorResponse {
  code: AuthErrorCode | PartyErrorCode;
  message: string;
}
