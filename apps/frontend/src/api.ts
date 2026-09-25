import type {
  FirstPartyResponse,
  HealthResponse,
  LoginResponse,
  MeResponse,
  MinePartyResponse,
  PartyCharacterView,
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
  if (typeof value !== "number" || !Number.isSafeInteger(value) || value < 1) {
    throw new Error(`${where} 应为正整数，实际：${JSON.stringify(value)}`);
  }
  return value;
}

function asNonnegativeInt(value: unknown, where: string): number {
  if (typeof value !== "number" || !Number.isSafeInteger(value) || value < 0) {
    throw new Error(`${where} 应为非负整数，实际：${JSON.stringify(value)}`);
  }
  return value;
}

function asBoolean(value: unknown, where: string): boolean {
  if (typeof value !== "boolean") throw new Error(`${where} 应为布尔值，实际：${JSON.stringify(value)}`);
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

function decodeAccountSummary(record: Record<string, unknown>, where: string) {
  return {
    accountId: asString(record.accountId, `${where} accountId`),
    loginName: asString(record.loginName, `${where} loginName`),
    teamCompleted: asBoolean(record.teamCompleted, `${where} teamCompleted`),
    money: asMoneyString(record.money, `${where} money`),
    stamina: asNonnegativeInt(record.stamina, `${where} stamina`),
  };
}

export function decodeRegister(value: unknown): RegisterResponse {
  const record = asRecord(value, "/api/auth/register");
  const replayed = record.replayed === true;
  if (record.replayed !== undefined && !replayed) throw new Error("/api/auth/register replayed 非法");
  if (replayed && record.recoveryCode !== undefined) throw new Error("/api/auth/register 重放不得包含恢复码");
  if (!replayed && record.recoveryCode === undefined) throw new Error("/api/auth/register 首次响应缺少恢复码");
  return {
    ...decodeAccountSummary(record, "/api/auth/register"),
    createdAt: asString(record.createdAt, "/api/auth/register createdAt"),
    recoveryGeneration: asPositiveInt(record.recoveryGeneration, "/api/auth/register recoveryGeneration"),
    recoveryCode: replayed ? undefined : asString(record.recoveryCode, "/api/auth/register recoveryCode"),
    replayed: replayed ? true : undefined,
    releaseId: asString(record.releaseId, "/api/auth/register releaseId"),
    recoveryEpoch: asPositiveInt(record.recoveryEpoch, "/api/auth/register recoveryEpoch"),
  };
}

export function decodeLogin(value: unknown): LoginResponse {
  const record = asRecord(value, "/api/auth/login");
  return {
    ...decodeAccountSummary(record, "/api/auth/login"),
    sessionExpiresAt: asString(record.sessionExpiresAt, "/api/auth/login sessionExpiresAt"),
    releaseId: asString(record.releaseId, "/api/auth/login releaseId"),
    recoveryEpoch: asPositiveInt(record.recoveryEpoch, "/api/auth/login recoveryEpoch"),
  };
}

export function decodeMe(value: unknown): MeResponse {
  const record = asRecord(value, "/api/auth/me");
  return {
    ...decodeAccountSummary(record, "/api/auth/me"),
    recoveryGeneration: asPositiveInt(record.recoveryGeneration, "/api/auth/me recoveryGeneration"),
    createdAt: asString(record.createdAt, "/api/auth/me createdAt"),
    releaseId: asString(record.releaseId, "/api/auth/me releaseId"),
    recoveryEpoch: asPositiveInt(record.recoveryEpoch, "/api/auth/me recoveryEpoch"),
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

export async function createFirstParty(
  teamName: string,
  characterName: string,
  recruitId: string,
  gender: string,
  requestId: string,
): Promise<FirstPartyResponse> {
  return decodeFirstParty(await postJson("/api/party/first", { teamName, characterName, recruitId, gender, requestId }));
}

export async function fetchMineParty(): Promise<MinePartyResponse> {
  return decodeMineParty(await getJson("/api/party/mine"));
}

export async function logout(): Promise<void> {
  const response = await fetch("/api/auth/logout", { method: "POST", credentials: "same-origin" });
  if (!response.ok) {
    throw await toApiError("/api/auth/logout", response);
  }
}

export function decodePartyCharacter(value: unknown, where: string): PartyCharacterView {
  const record = asRecord(value, where);
  const stats = asRecord(record.stats, `${where} stats`);
  const skills = record.skills as unknown;
  if (!Array.isArray(skills)) throw new Error(`${where} skills 应为数组`);
  const equipment = record.equipment as unknown;
  if (!Array.isArray(equipment)) throw new Error(`${where} equipment 应为数组`);
  const tactics = record.defaultTactics as unknown;
  if (!Array.isArray(tactics)) throw new Error(`${where} defaultTactics 应为数组`);
  return {
    characterId: asString(record.characterId, `${where} characterId`),
    name: asString(record.name, `${where} name`),
    jobId: asString(record.jobId, `${where} jobId`),
    jobName: asString(record.jobName, `${where} jobName`),
    gender: asString(record.gender, `${where} gender`),
    level: asPositiveInt(record.level, `${where} level`),
    experience: asNonnegativeInt(record.experience, `${where} experience`),
    maxHp: asPositiveInt(record.maxHp, `${where} maxHp`),
    hp: asNonnegativeInt(record.hp, `${where} hp`),
    maxSp: asPositiveInt(record.maxSp, `${where} maxSp`),
    sp: asNonnegativeInt(record.sp, `${where} sp`),
    stats: {
      str: asNonnegativeInt(stats.str, `${where} stats.str`),
      int: asNonnegativeInt(stats.int, `${where} stats.int`),
      dex: asNonnegativeInt(stats.dex, `${where} stats.dex`),
      spd: asNonnegativeInt(stats.spd, `${where} stats.spd`),
      luk: asNonnegativeInt(stats.luk, `${where} stats.luk`),
    },
    unassignedAp: asNonnegativeInt(record.unassignedAp, `${where} unassignedAp`),
    unassignedSp: asNonnegativeInt(record.unassignedSp, `${where} unassignedSp`),
    skills: skills.map((entry, index) => {
      const skill = asRecord(entry, `${where} skills[${index}]`);
      return {
        skillId: asString(skill.skillId, `${where} skills[${index}].skillId`),
        name: asString(skill.name, `${where} skills[${index}].name`),
      };
    }),
    equipment: equipment.map((entry, index) => {
      const item = asRecord(entry, `${where} equipment[${index}]`);
      return {
        equipmentId: asString(item.equipmentId, `${where} equipment[${index}].equipmentId`),
        definitionId: asString(item.definitionId, `${where} equipment[${index}].definitionId`),
        name: asString(item.name, `${where} equipment[${index}].name`),
        slot: asString(item.slot, `${where} equipment[${index}].slot`),
      };
    }),
    position: asString(record.position, `${where} position`),
    guardPolicy: { kind: asString(asRecord(record.guardPolicy, `${where} guardPolicy`).kind, `${where} guardPolicy.kind`) },
    defaultTactics: tactics.map((entry, index) => {
      const tactic = asRecord(entry, `${where} defaultTactics[${index}]`);
      const conditions = tactic.conditions as unknown;
      if (!Array.isArray(conditions)) throw new Error(`${where} defaultTactics[${index}].conditions 应为数组`);
      return {
        conditions: conditions.map((condition, conditionIndex) => {
          const item = asRecord(condition, `${where} condition[${conditionIndex}]`);
          return {
            conditionId: asString(item.conditionId, `${where} condition.conditionId`),
            description: asString(item.description, `${where} condition.description`),
            quantity: asNonnegativeInt(item.quantity, `${where} condition.quantity`),
          };
        }),
        skillId: asString(tactic.skillId, `${where} defaultTactics[${index}].skillId`),
        skillName: asString(tactic.skillName, `${where} defaultTactics[${index}].skillName`),
      };
    }),
  };
}

export function decodeFirstParty(value: unknown): FirstPartyResponse {
  const record = asRecord(value, "/api/party/first");
  const replayed = record.replayed === true;
  if (record.replayed !== undefined && !replayed) throw new Error("/api/party/first replayed 非法");
  return {
    teamName: asString(record.teamName, "/api/party/first teamName"),
    character: decodePartyCharacter(record.character, "/api/party/first character"),
    replayed: replayed ? true : undefined,
    releaseId: asString(record.releaseId, "/api/party/first releaseId"),
    recoveryEpoch: asPositiveInt(record.recoveryEpoch, "/api/party/first recoveryEpoch"),
  };
}

export function decodeMineParty(value: unknown): MinePartyResponse {
  const record = asRecord(value, "/api/party/mine");
  const teamCompleted = asBoolean(record.teamCompleted, "/api/party/mine teamCompleted");
  if (record.teamName !== null && typeof record.teamName !== "string") {
    throw new Error("/api/party/mine teamName 应为字符串或 null");
  }
  return {
    teamCompleted,
    teamName: record.teamName as string | null,
    character: record.character == null ? null : decodePartyCharacter(record.character, "/api/party/mine character"),
    releaseId: asString(record.releaseId, "/api/party/mine releaseId"),
    recoveryEpoch: asPositiveInt(record.recoveryEpoch, "/api/party/mine recoveryEpoch"),
  };
}

/** 为一次明确提交生成高熵请求身份（32 位十六进制，符合契约格式）。 */
export function newRequestId(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return [...bytes].map((b) => b.toString(16).padStart(2, "0")).join("");
}
