import crypto from "node:crypto";

/** 恢复码：至少 128 位不可预测随机量；服务端只保存摘要。 */
export function generateRecoveryCode(): string {
  // 16 字节 → 32 位小写十六进制，便于复制保存。
  return crypto.randomBytes(16).toString("hex");
}

/** 会话令牌：256 位随机量，客户端持有，服务端只存摘要。 */
export function generateSessionToken(): string {
  return crypto.randomBytes(32).toString("hex");
}

export function sha256Hex(text: string): string {
  return crypto.createHash("sha256").update(text, "utf8").digest("hex");
}

export function newId(): string {
  return crypto.randomUUID();
}
