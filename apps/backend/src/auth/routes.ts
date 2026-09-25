import type { DatabaseSync } from "node:sqlite";
import type { FastifyInstance, FastifyReply, FastifyRequest } from "fastify";
import { ACCOUNT_CONTRACT } from "@hof/shared";
import {
  rejectUnknownFields,
  validateLoginName,
  validatePassword,
  validateRequestId,
} from "./validation.js";
import { hashPassword, verifyPassword } from "./password.js";
import { generateRecoveryCode, generateSessionToken } from "./tokens.js";
import {
  checkSession,
  createSession,
  getAccountByKey,
  getAccountWithAssets,
  getRecoveryEpoch,
  registerAccount,
  revokeSession,
} from "./store.js";
import { buildClearedSessionCookie, buildSessionCookie, checkSameOrigin, extractSessionToken } from "./session.js";

export interface AuthRouteOptions {
  db: DatabaseSync;
  releaseId: string;
  maxUsers: number;
}

interface RateEntry {
  times: number[];
}

const rateBuckets = new Map<string, RateEntry>();
const RATE_WINDOW_MS = 60_000;
const RATE_LIMIT_PER_IP = 60;

function checkRateLimit(ip: string): boolean {
  const now = Date.now();
  const entry = rateBuckets.get(ip) ?? { times: [] };
  entry.times = entry.times.filter((t) => now - t < RATE_WINDOW_MS);
  if (entry.times.length >= RATE_LIMIT_PER_IP) {
    rateBuckets.set(ip, entry);
    return false;
  }
  entry.times.push(now);
  rateBuckets.set(ip, entry);
  // 有界内存：上限外清理最旧桶。
  if (rateBuckets.size > 10_000) {
    const oldest = [...rateBuckets.keys()].slice(0, 1000);
    for (const k of oldest) rateBuckets.delete(k);
  }
  return true;
}

/** 测试隔离：重置进程内限流桶。 */
export function resetAuthRateLimits(): void {
  rateBuckets.clear();
}

function clientIp(request: FastifyRequest): string {
  return request.ip ?? "unknown";
}

function isSecureRequest(request: FastifyRequest): boolean {
  const proto = (request.headers["x-forwarded-proto"] as string | undefined) ?? request.protocol;
  return proto === "https";
}

function sendError(reply: FastifyReply, status: number, code: string, message: string): void {
  // 错误不回显密码、恢复码、令牌、SQL、堆栈或整份请求。
  void reply.status(status).send({ code, message });
}

function readJsonBody(request: FastifyRequest): Record<string, unknown> | undefined {
  const body = request.body as unknown;
  if (typeof body !== "object" || body === null || Array.isArray(body)) return undefined;
  return body as Record<string, unknown>;
}

export function registerAuthRoutes(app: FastifyInstance, opts: AuthRouteOptions): void {
  const { db, releaseId, maxUsers } = opts;

  app.post("/api/auth/register", async (request, reply) => {
    if (!checkSameOrigin(request.headers as Record<string, string | string[] | undefined>)) {
      sendError(reply, 403, "REQUEST_CONFLICT", "来源校验失败，请经本站页面提交");
      return;
    }
    if (!checkRateLimit(clientIp(request))) {
      sendError(reply, 429, "RATE_LIMITED", "请求过于频繁，请稍后重试");
      return;
    }
    const body = readJsonBody(request);
    if (!body) {
      sendError(reply, 400, "INVALID_INPUT", "请求正文须为 JSON 对象");
      return;
    }
    const unknown = rejectUnknownFields(body, ["loginName", "password", "requestId"]);
    if (unknown) {
      sendError(reply, 400, "INVALID_INPUT", unknown);
      return;
    }
    const loginErr = validateLoginName(body.loginName);
    if (loginErr) {
      sendError(reply, 400, "INVALID_INPUT", loginErr);
      return;
    }
    const passwordErr = validatePassword(body.password);
    if (passwordErr) {
      const status = passwordErr.startsWith("密码过于") ? 422 : 400;
      sendError(reply, status, passwordErr.startsWith("密码过于") ? "WEAK_PASSWORD" : "INVALID_INPUT", passwordErr);
      return;
    }
    const requestErr = validateRequestId(body.requestId);
    if (requestErr) {
      sendError(reply, 400, "INVALID_INPUT", requestErr);
      return;
    }
    const loginName = body.loginName as string;
    const password = body.password as string;
    const requestId = body.requestId as string;

    // 耗时工作（哈希、随机量）在写事务之外。
    const passwordHash = await hashPassword(password);
    const recoveryCode = generateRecoveryCode();

    try {
      const result = registerAccount(db, { loginName, passwordHash, recoveryCode, requestId, maxUsers, releaseId });
      const assets = getAccountWithAssets(db, result.accountId);
      const recoveryEpoch = getRecoveryEpoch(db);
      if (result.replayed) {
        // 幂等重放不重放恢复码明文；用已设置的密码登录后可重新签发（#26）。
        void reply.status(200).send({
          accountId: result.accountId,
          loginName: result.loginName,
          money: String(assets?.money ?? ACCOUNT_CONTRACT.initialMoney),
          stamina: assets?.stamina ?? ACCOUNT_CONTRACT.initialStamina,
          recoveryGeneration: 1,
          replayed: true,
          teamCompleted: false,
          createdAt: result.createdAt,
          releaseId,
          recoveryEpoch,
        });
        return;
      }
      void reply.status(201).send({
        accountId: result.accountId,
        loginName: result.loginName,
        money: String(assets?.money ?? ACCOUNT_CONTRACT.initialMoney),
        stamina: assets?.stamina ?? ACCOUNT_CONTRACT.initialStamina,
        recoveryGeneration: 1,
        recoveryCode,
        teamCompleted: false,
        createdAt: result.createdAt,
        releaseId,
        recoveryEpoch,
      });
    } catch (err) {
      const code = (err as NodeJS.ErrnoException)?.code;
      if (code === "LOGIN_TAKEN") {
        sendError(reply, 409, "LOGIN_TAKEN", "登录名已被占用");
        return;
      }
      if (code === "CAPACITY_FULL") {
        sendError(reply, 422, "CAPACITY_FULL", "注册容量已满");
        return;
      }
      if (code === "REQUEST_CONFLICT") {
        sendError(reply, 409, "REQUEST_CONFLICT", "同一请求身份不得更换参数");
        return;
      }
      request.log.error({ err: err instanceof Error ? err.message : String(err) }, "注册失败");
      sendError(reply, 500, "INVALID_INPUT", "服务器内部错误");
    }
  });

  app.post("/api/auth/login", async (request, reply) => {
    if (!checkSameOrigin(request.headers as Record<string, string | string[] | undefined>)) {
      sendError(reply, 403, "REQUEST_CONFLICT", "来源校验失败，请经本站页面提交");
      return;
    }
    if (!checkRateLimit(clientIp(request))) {
      sendError(reply, 429, "RATE_LIMITED", "请求过于频繁，请稍后重试");
      return;
    }
    const body = readJsonBody(request);
    if (!body) {
      sendError(reply, 400, "INVALID_INPUT", "请求正文须为 JSON 对象");
      return;
    }
    const unknown = rejectUnknownFields(body, ["loginName", "password"]);
    if (unknown) {
      sendError(reply, 400, "INVALID_INPUT", unknown);
      return;
    }
    const loginErr = validateLoginName(body.loginName);
    if (loginErr) {
      sendError(reply, 400, "INVALID_INPUT", loginErr);
      return;
    }
    if (typeof body.password !== "string" || body.password.length === 0) {
      sendError(reply, 400, "INVALID_INPUT", "密码须为字符串");
      return;
    }
    const loginName = body.loginName as string;
    const password = body.password as string;
    const loginKey = loginName.toLowerCase();

    const account = getAccountByKey(db, loginKey);
    if (!account || account.deleted_at !== null) {
      // 未知名称仍执行一次哈希以收敛耗时侧信道；返回与错误凭据一致的说明。
      await hashPassword(password).catch(() => undefined);
      sendError(reply, 401, "INVALID_CREDENTIALS", "登录名或密码不正确");
      return;
    }
    const ok = await verifyPassword(account.password_hash, password);
    if (!ok) {
      sendError(reply, 401, "INVALID_CREDENTIALS", "登录名或密码不正确");
      return;
    }

    try {
      const token = generateSessionToken();
      const created = createSession(db, account.id, token);
      const assets = getAccountWithAssets(db, account.id);
      const recoveryEpoch = getRecoveryEpoch(db);
      const secure = isSecureRequest(request);
      const maxAge = Math.floor(ACCOUNT_CONTRACT.sessionAbsoluteMs / 1000);
      void reply.header("Set-Cookie", buildSessionCookie(token, secure, maxAge));
      void reply.status(200).send({
        accountId: account.id,
        loginName: account.login_name,
        teamCompleted: account.team_completed === 1,
        money: String(assets?.money ?? "0"),
        stamina: assets?.stamina ?? 0,
        sessionExpiresAt: new Date(Date.parse(created.createdAt) + ACCOUNT_CONTRACT.sessionAbsoluteMs).toISOString(),
        releaseId,
        recoveryEpoch,
      });
    } catch (err) {
      request.log.error({ err: err instanceof Error ? err.message : String(err) }, "登录签发会话失败");
      sendError(reply, 500, "INVALID_INPUT", "服务器内部错误");
    }
  });

  app.post("/api/auth/logout", async (request, reply) => {
    if (!checkSameOrigin(request.headers as Record<string, string | string[] | undefined>)) {
      sendError(reply, 403, "REQUEST_CONFLICT", "来源校验失败，请经本站页面提交");
      return;
    }
    const token = extractSessionToken(request.headers as Record<string, string | string[] | undefined>);
    if (!token) {
      sendError(reply, 401, "UNAUTHORIZED", "需要登录");
      return;
    }
    revokeSession(db, token);
    void reply.header("Set-Cookie", buildClearedSessionCookie());
    void reply.status(200).send({ ok: true });
  });

  app.get("/api/auth/me", async (request, reply) => {
    const token = extractSessionToken(request.headers as Record<string, string | string[] | undefined>);
    if (!token) {
      sendError(reply, 401, "UNAUTHORIZED", "需要登录");
      return;
    }
    const checked = checkSession(db, token, Date.now());
    if (!checked.ok) {
      if (checked.reason === "expired") {
        void reply.header("Set-Cookie", buildClearedSessionCookie());
        sendError(reply, 401, "SESSION_EXPIRED", "会话已过期，请重新登录");
        return;
      }
      sendError(reply, 401, "UNAUTHORIZED", "需要登录");
      return;
    }
    const assets = getAccountWithAssets(db, checked.session.account_id);
    if (!assets || assets.deleted_at !== null) {
      sendError(reply, 401, "UNAUTHORIZED", "需要登录");
      return;
    }
    // GET /me 为只读查询，不续会话闲置期（自动轮询不延长会话）。
    void reply.status(200).send({
      accountId: assets.id,
      loginName: assets.login_name,
      teamCompleted: assets.team_completed === 1,
      money: String(assets.money),
      stamina: assets.stamina,
      recoveryGeneration: assets.recovery_generation,
      createdAt: assets.created_at,
      releaseId,
      recoveryEpoch: getRecoveryEpoch(db),
    });
  });
}
