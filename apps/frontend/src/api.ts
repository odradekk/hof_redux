import type {
  HealthResponse,
  LoginResponse,
  MeResponse,
  RegisterResponse,
  VersionResponse,
} from "@hof/shared";

/**
 * 共享契约只描述类型；HTTP 边界的形状在运行期由这些轻量解码器核实，
 * 不把任意 JSON 直接断言成契约类型。
 */

export class ApiError extends Error {
  status: number;
  code: string;
  constructor(status: number, code: string, message: string) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

async function getJson(path: string): Promise<unknown> {
  const response = await fetch(path, { headers: { Accept: "application/json" }, credentials: "same-origin" });
  if (!response.ok) {
    throw await toApiError(path, response);
  }
  return response.json();
}

async function postJson(path: string, body: Record<string, unknown>): Promise<unknown> {
  const response = await fetch(path, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify(body),
  });
  if (!response.ok) {
    throw await toApiError(path, response);
  }
  return response.json();
}

async function toApiError(path: string, response: Response): Promise<ApiError> {
  let code = "UNKNOWN";
  let message = `${path} 返回 ${response.status}`;
  try {
    const data = (await response.json()) as { code?: unknown; message?: unknown };
    if (typeof data.code === "string") code = data.code;
    if (typeof data.message === "string" && data.message.length > 0) message = data.message;
  } catch {
    // 非 JSON 错误页（如网关 5xx）保持状态码说明，进入“结果待确认”处理。
  }
  return new ApiError(response.status, code, message);
}

function asRecord(value: unknown, where: string): Record<string, unknown> {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    throw new Error(`${where} 应为对象，实际：${JSON.stringify(value)}`);
  }
  return value as Record<string, unknown>;
}

function asString(value: unknown, where: string): string {
  if (typeof value !== "string" || value.length === 0) {
    throw new Error(`${where} 应为非空字符串，实际：${JSON.stringify(value)}`);
  }
  return value;
}

function asPositiveInt(value: unknown, where: string): number {
  if (typeof value !== "number" || !Number.isInteger(value) || value < 1) {
    throw new Error(`${where} 应为正整数，实际：${JSON.stringify(value)}`);
  }
  return value;
}

function asMoneyString(value: unknown, where: string): string {
  if (typeof value !== "string" || !/^\d+$/.test(value)) {
    throw new Error(`${where} 应为十进制整数字符串，实际：${JSON.stringify(value)}`);
  }
  return value;
}

export function decodeHealth(value: unknown): HealthResponse {
  const record = asRecord(value, "/api/health");
  if (record.status !== "ok") {
    throw new Error(`/api/health status 应为 ok，实际：${JSON.stringify(record.status)}`);
  }
  return { status: "ok", now: asString(record.now, "/api/health now") };
}

export function decodeVersion(value: unknown): VersionResponse {
  const record = asRecord(value, "/api/version");
  const app = asRecord(record.app, "/api/version app");
  const content = asRecord(record.content, "/api/version content");
  const database = asRecord(record.database, "/api/version database");
  const contentHash = asString(content.contentHash, "/api/version content.contentHash");
  if (!contentHash.startsWith("sha256:")) {
    throw new Error(`/api/version content.contentHash 非法：${contentHash}`);
  }
  const out: VersionResponse = {
    app: {
      name: asString(app.name, "/api/version app.name"),
      version: asString(app.version, "/api/version app.version"),
    },
    content: {
      releaseId: asString(content.releaseId, "/api/version content.releaseId"),
      schemaVersion: asPositiveInt(content.schemaVersion, "/api/version content.schemaVersion"),
      contentHash,
    },
    database: {
      schemaVersion: asPositiveInt(database.schemaVersion, "/api/version database.schemaVersion"),
    },
  };
  if (record.recoveryEpoch !== undefined) {
    if (typeof record.recoveryEpoch !== "number" || !Number.isInteger(record.recoveryEpoch)) {
      throw new Error("/api/version recoveryEpoch 非法");
    }
    out.recoveryEpoch = record.recoveryEpoch;
  }
  return out;
}

function decodeAccountFields(record: Record<string, unknown>, where: string): Omit<MeResponse, "releaseId" | "recoveryEpoch"> & { releaseId?: string; recoveryEpoch?: number } {
  return {
    accountId: asString(record.accountId, `${where} accountId`),
    loginName: asString(record.loginName, `${where} loginName`),
    teamCompleted: record.teamCompleted === true,
    money: asMoneyString(record.money, `${where} money`),
    stamina: typeof record.stamina === "number" ? record.stamina : Number(asString(record.stamina, `${where} stamina`)),
    recoveryGeneration:
      typeof record.recoveryGeneration === "number"
        ? record.recoveryGeneration
        : Number(asString(record.recoveryGeneration, `${where} recoveryGeneration`)),
    createdAt: (record.createdAt as string | undefined) ?? (record.sessionExpiresAt as string | undefined) ?? "",
  };
}

export function decodeRegister(value: unknown): RegisterResponse {
  const record = asRecord(value, "/api/auth/register");
  const base = decodeAccountFields(record, "/api/auth/register");
  return {
    ...base,
    createdAt: asString(record.createdAt, "/api/auth/register createdAt"),
    recoveryCode: typeof record.recoveryCode === "string" ? record.recoveryCode : undefined,
    replayed: record.replayed === true ? true : undefined,
    releaseId: asString(record.releaseId, "/api/auth/register releaseId"),
    recoveryEpoch: Number(record.recoveryEpoch ?? 1),
  };
}

export function decodeLogin(value: unknown): LoginResponse {
  const record = asRecord(value, "/api/auth/login");
  return {
    accountId: asString(record.accountId, "/api/auth/login accountId"),
    loginName: asString(record.loginName, "/api/auth/login loginName"),
    teamCompleted: record.teamCompleted === true,
    money: asMoneyString(record.money, "/api/auth/login money"),
    stamina: typeof record.stamina === "number" ? record.stamina : 0,
    sessionExpiresAt: asString(record.sessionExpiresAt, "/api/auth/login sessionExpiresAt"),
    releaseId: asString(record.releaseId, "/api/auth/login releaseId"),
    recoveryEpoch: Number(record.recoveryEpoch ?? 1),
  };
}

export function decodeMe(value: unknown): MeResponse {
  const record = asRecord(value, "/api/auth/me");
  return {
    accountId: asString(record.accountId, "/api/auth/me accountId"),
    loginName: asString(record.loginName, "/api/auth/me loginName"),
    teamCompleted: record.teamCompleted === true,
    money: asMoneyString(record.money, "/api/auth/me money"),
    stamina: typeof record.stamina === "number" ? record.stamina : 0,
    recoveryGeneration: Number(record.recoveryGeneration ?? 1),
    createdAt: asString(record.createdAt, "/api/auth/me createdAt"),
    releaseId: asString(record.releaseId, "/api/auth/me releaseId"),
    recoveryEpoch: Number(record.recoveryEpoch ?? 1),
  };
}

export async function fetchHealth(): Promise<HealthResponse> {
  return decodeHealth(await getJson("/api/health"));
}

export async function fetchVersion(): Promise<VersionResponse> {
  return decodeVersion(await getJson("/api/version"));
}

export async function registerAccount(loginName: string, password: string, requestId: string): Promise<RegisterResponse> {
  return decodeRegister(await postJson("/api/auth/register", { loginName, password, requestId }));
}

export async function login(loginName: string, password: string): Promise<LoginResponse> {
  return decodeLogin(await postJson("/api/auth/login", { loginName, password }));
}

export async function fetchMe(): Promise<MeResponse> {
  return decodeMe(await getJson("/api/auth/me"));
}

export async function logout(): Promise<void> {
  const response = await fetch("/api/auth/logout", { method: "POST", credentials: "same-origin" });
  if (!response.ok) {
    throw await toApiError("/api/auth/logout", response);
  }
}

/** 为一次明确提交生成高熵请求身份（32 位十六进制，符合契约格式）。 */
export function newRequestId(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return [...bytes].map((b) => b.toString(16).padStart(2, "0")).join("");
}
