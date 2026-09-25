import type { DatabaseSync } from "node:sqlite";
import type { FastifyInstance, FastifyReply, FastifyRequest } from "fastify";
import { validateRequestId, rejectUnknownFields } from "../auth/validation.js";
import { checkSession } from "../auth/store.js";
import { buildClearedSessionCookie, checkSameOrigin, extractSessionToken } from "../auth/session.js";
import { getRecoveryEpoch } from "../auth/store.js";
import { validateGender, validatePartyName, validateRecruitId } from "./validation.js";
import { createFirstParty, getPartyMine } from "./store.js";
import type { PartyContent } from "./content.js";

export interface PartyRouteOptions {
  db: DatabaseSync;
  releaseId: string;
  partyContent: PartyContent;
}

function sendError(reply: FastifyReply, status: number, code: string, message: string): void {
  // 错误不回显会话令牌、SQL、堆栈或整份请求。
  void reply.status(status).send({ code, message });
}

function readJsonBody(request: FastifyRequest): Record<string, unknown> | undefined {
  const body = request.body as unknown;
  if (typeof body !== "object" || body === null || Array.isArray(body)) return undefined;
  return body as Record<string, unknown>;
}

function requireSession(db: DatabaseSync, request: FastifyRequest, reply: FastifyReply): string | undefined {
  const token = extractSessionToken(request.headers as Record<string, string | string[] | undefined>);
  if (!token) {
    sendError(reply, 401, "UNAUTHORIZED", "需要登录");
    return undefined;
  }
  const checked = checkSession(db, token, Date.now());
  if (!checked.ok) {
    if (checked.reason === "expired") {
      void reply.header("Set-Cookie", buildClearedSessionCookie());
      sendError(reply, 401, "SESSION_EXPIRED", "会话已过期，请重新登录");
      return undefined;
    }
    sendError(reply, 401, "UNAUTHORIZED", "需要登录");
    return undefined;
  }
  return checked.session.account_id;
}

function errorStatus(code: string): number {
  switch (code) {
    case "UNAUTHORIZED":
    case "SESSION_EXPIRED":
      return 401;
    case "PARTY_ALREADY_COMPLETED":
    case "TEAM_NAME_TAKEN":
    case "REQUEST_CONFLICT":
      return 409;
    case "TEAM_NOT_COMPLETED":
      return 403;
    default:
      return 400;
  }
}

/**
 * 角色与编队路由（#25）：首次建队提交与只读查看。
 * 业务规则由 store 事务完成；处理器只做协议解析、身份传递与结果映射。
 */
export function registerPartyRoutes(app: FastifyInstance, opts: PartyRouteOptions): void {
  const { db, releaseId, partyContent } = opts;

  app.post("/api/party/first", async (request, reply) => {
    if (!checkSameOrigin(request.headers as Record<string, string | string[] | undefined>)) {
      sendError(reply, 403, "REQUEST_CONFLICT", "来源校验失败，请经本站页面提交");
      return;
    }
    const accountId = requireSession(db, request, reply);
    if (!accountId) return;
    const body = readJsonBody(request);
    if (!body) {
      sendError(reply, 400, "INVALID_INPUT", "请求正文须为 JSON 对象");
      return;
    }
    const unknown = rejectUnknownFields(body, ["teamName", "characterName", "recruitId", "gender", "requestId"]);
    if (unknown) {
      sendError(reply, 400, "INVALID_INPUT", unknown);
      return;
    }
    const team = validatePartyName(body.teamName, "队伍名");
    if (!team.ok) {
      sendError(reply, 400, "INVALID_INPUT", team.error);
      return;
    }
    const character = validatePartyName(body.characterName, "角色名");
    if (!character.ok) {
      sendError(reply, 400, "INVALID_INPUT", character.error);
      return;
    }
    const recruitErr = validateRecruitId(body.recruitId);
    if (recruitErr) {
      sendError(reply, 400, "INVALID_INPUT", recruitErr);
      return;
    }
    const genderErr = validateGender(body.gender);
    if (genderErr) {
      sendError(reply, 400, "INVALID_INPUT", genderErr);
      return;
    }
    const requestErr = validateRequestId(body.requestId);
    if (requestErr) {
      sendError(reply, 400, "INVALID_INPUT", requestErr);
      return;
    }

    try {
      const result = createFirstParty(db, partyContent, {
        accountId,
        teamName: team.value,
        characterName: character.value,
        recruitId: body.recruitId as string,
        gender: body.gender as string,
        requestId: body.requestId as string,
      });
      const mine = getPartyMine(db, accountId);
      void reply.status(result.replayed ? 200 : 201).send({
        teamName: result.teamName,
        character: mine.character,
        ...(result.replayed ? { replayed: true } : {}),
        releaseId,
        recoveryEpoch: getRecoveryEpoch(db),
      });
    } catch (err) {
      const code = (err as NodeJS.ErrnoException)?.code ?? "INVALID_INPUT";
      const message = err instanceof Error ? err.message : "服务器内部错误";
      if (code === "TEAM_NAME_TAKEN") {
        sendError(reply, 409, code, "队伍名已被占用");
        return;
      }
      if (code === "PARTY_ALREADY_COMPLETED") {
        sendError(reply, 409, code, message);
        return;
      }
      if (code === "REQUEST_CONFLICT" || code === "UNAUTHORIZED") {
        sendError(reply, errorStatus(code), code, message);
        return;
      }
      if (code === "INVALID_INPUT") {
        sendError(reply, 400, code, message);
        return;
      }
      request.log.error({ err: message }, "首次建队失败");
      sendError(reply, 500, "INVALID_INPUT", "服务器内部错误");
    }
  });

  app.get("/api/party/mine", async (request, reply) => {
    const accountId = requireSession(db, request, reply);
    if (!accountId) return;
    try {
      const mine = getPartyMine(db, accountId);
      void reply.status(200).send({ ...mine, releaseId, recoveryEpoch: getRecoveryEpoch(db) });
    } catch (err) {
      const code = (err as NodeJS.ErrnoException)?.code ?? "INVALID_INPUT";
      const message = err instanceof Error ? err.message : "服务器内部错误";
      sendError(reply, errorStatus(code), code, message);
    }
  });
}
