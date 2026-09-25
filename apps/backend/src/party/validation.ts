import { PARTY_CONTRACT } from "@hof/shared";

/** 码点计数：不按 UTF-8 字节或 UTF-16 单元计数（账号契约身份与名称）。 */
export function codePoints(text: string): number {
  return [...text].length;
}

/**
 * 队伍名/角色名校验（账号契约 Q5）。
 * - NFC 规范化后，先拒绝换行、控制及不可见格式字符，再裁剪首尾空白并按码点计 1–16。
 * - 存储规范化原文，展示时转义；不将转义串作为身份。
 */
export function validatePartyName(
  raw: unknown,
  label = "名称",
): { ok: true; value: string } | { ok: false; error: string } {
  if (typeof raw !== "string") return { ok: false, error: `${label}须为字符串` };
  const normalized = raw.normalize("NFC");
  // Cc（控制，含换行/制表）与 Cf（不可见格式）一律拒绝；普通空格允许，稍后裁剪首尾。
  // eslint-disable-next-line no-misleading-character-class
  if (/[\p{Cc}\p{Cf}]/u.test(normalized)) return { ok: false, error: `${label}不得包含换行、控制或不可见格式字符` };
  const trimmed = normalized.trim();
  if (trimmed.length === 0) return { ok: false, error: `${label}不能为空` };
  const length = codePoints(trimmed);
  if (length < PARTY_CONTRACT.nameMinLength || length > PARTY_CONTRACT.nameMaxLength) {
    return {
      ok: false,
      error: `${label}须为 ${PARTY_CONTRACT.nameMinLength}–${PARTY_CONTRACT.nameMaxLength} 个字符（按 Unicode 码点计数）`,
    };
  }
  return { ok: true, value: trimmed };
}

/** S1 招募模板：仅战士/法师；非法选择拒绝，不沿用旧默认落入女法师的隐式转换。 */
export function validateRecruitId(recruitId: unknown): string | null {
  if (typeof recruitId !== "string") return "职业选择须为字符串";
  if (!(PARTY_CONTRACT.recruitIds as readonly string[]).includes(recruitId)) {
    return "职业选择非法（S1 仅支持战士或法师）";
  }
  return null;
}

export function validateGender(gender: unknown): string | null {
  if (typeof gender !== "string") return "性别选择须为字符串";
  if (!(PARTY_CONTRACT.genders as readonly string[]).includes(gender)) return "性别选择非法";
  return null;
}
