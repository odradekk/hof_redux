import { ACCOUNT_CONTRACT } from "@hof/shared";

/** 从 Cookie 或 Authorization: Bearer 提取会话令牌（Cookie 优先）。 */
export function extractSessionToken(headers: Record<string, string | string[] | undefined>): string | undefined {
  const auth = headers.authorization ?? headers.Authorization;
  const authValue = Array.isArray(auth) ? auth[0] : auth;
  let bearer: string | undefined;
  if (typeof authValue === "string") {
    const match = /^Bearer (.+)$/.exec(authValue.trim());
    if (match) bearer = match[1].trim();
  }
  const cookieHeader = headers.cookie ?? headers.Cookie;
  const cookieValue = Array.isArray(cookieHeader) ? cookieHeader.join("; ") : cookieHeader;
  if (typeof cookieValue === "string") {
    for (const part of cookieValue.split(";")) {
      const idx = part.indexOf("=");
      if (idx < 0) continue;
      if (part.slice(0, idx).trim() === ACCOUNT_CONTRACT.sessionCookieName) {
        const token = decodeURIComponent(part.slice(idx + 1).trim());
        if (token) return token;
      }
    }
  }
  return bearer;
}

/** 会话 Cookie：HttpOnly + SameSite=Lax；HTTPS 下追加 Secure（S1 测试实例为 HTTP，不强制）。 */
export function buildSessionCookie(token: string, isSecure: boolean, maxAgeSeconds: number): string {
  const parts = [
    `${ACCOUNT_CONTRACT.sessionCookieName}=${encodeURIComponent(token)}`,
    "Path=/",
    "HttpOnly",
    "SameSite=Lax",
    `Max-Age=${maxAgeSeconds}`,
  ];
  if (isSecure) parts.push("Secure");
  return parts.join("; ");
}

export function buildClearedSessionCookie(): string {
  return `${ACCOUNT_CONTRACT.sessionCookieName}=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0`;
}

/** 同源校验：状态修改请求若携带 Origin，须与 Host 同源（接口契约 CSRF/Origin）。 */
export function checkSameOrigin(headers: Record<string, string | string[] | undefined>): boolean {
  const originRaw = headers.origin ?? headers.Origin;
  const hostRaw = headers.host ?? headers.Host ?? headers[":authority"];
  const origin = Array.isArray(originRaw) ? originRaw[0] : originRaw;
  const host = Array.isArray(hostRaw) ? hostRaw[0] : hostRaw;
  if (!origin || typeof origin !== "string" || origin.length === 0) return true;
  if (!host || typeof host !== "string") return false;
  try {
    const url = new URL(origin);
    return url.host === host;
  } catch {
    return false;
  }
}
