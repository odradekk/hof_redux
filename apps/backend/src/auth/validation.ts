import { ACCOUNT_CONTRACT } from "@hof/shared";

/** 码点计数：不按 UTF-8 字节或 UTF-16 单元计数（账号契约身份与名称）。 */
export function codePoints(text: string): number {
  return [...text].length;
}

const LOGIN_RE = /^[A-Za-z0-9]{4,16}$/;
const REQUEST_ID_RE = /^[A-Za-z0-9_-]{16,64}$/;

/** 版本化弱密码列表 v1：本地校验，不发送用户密码给第三方。 */
const WEAK_PASSWORDS_V1 = new Set(
  [
    "password",
    "password123",
    "123456789012345",
    "1234567890123456",
    "qwertyuiopasdfgh",
    "abcdefghijklmnop",
    "111111111111111",
    "000000000000000",
    "abc123abc123abc",
    "letmeinletmein1",
    "welcome12345678",
    "passwordpassword",
  ].map((s) => s.toLowerCase()),
);

export function validateLoginName(loginName: unknown): string | null {
  if (typeof loginName !== "string") return "登录名须为字符串";
  if (!LOGIN_RE.test(loginName)) return "登录名须为 4–16 位 ASCII 字母或数字";
  return null;
}

export function validatePassword(password: unknown): string | null {
  if (typeof password !== "string") return "密码须为字符串";
  const len = codePoints(password);
  if (len < ACCOUNT_CONTRACT.passwordMinLength || len > ACCOUNT_CONTRACT.passwordMaxLength) {
    return `密码须为 ${ACCOUNT_CONTRACT.passwordMinLength}–${ACCOUNT_CONTRACT.passwordMaxLength} 个字符（按 Unicode 码点计数）`;
  }
  // 拒绝不可见控制字符混入（空格允许且不裁剪，换行拒绝）。
  // eslint-disable-next-line no-control-regex
  if (/[\u0000-\u001f\u007f]/.test(password)) return "密码不得包含换行或控制字符";
  const lower = password.toLowerCase();
  if (WEAK_PASSWORDS_V1.has(lower)) return "密码过于常见，请更换更难猜测的长口令";
  // 单一字符重复（如 15 个 "a"）视为弱密码。
  if (/^(.)\1+$/.test(password)) return "密码过于简单，请更换更难猜测的长口令";
  return null;
}

export function validateRequestId(requestId: unknown): string | null {
  if (typeof requestId !== "string") return "请求身份须为字符串";
  if (!REQUEST_ID_RE.test(requestId)) return "请求身份格式非法（16–64 位字母、数字、下划线或连字符）";
  return null;
}

/**
 * 严格结构校验：写对象声明外字段不允许，不做类型转换/补默认值/删未知字段。
 * 返回错误消息，无错误返回 null。
 */
export function rejectUnknownFields(value: Record<string, unknown>, allowed: readonly string[]): string | null {
  for (const key of Object.keys(value)) {
    if (!allowed.includes(key)) return `未知字段：${key}`;
  }
  return null;
}
