import type { Diagnostic } from "./validate-types.js";

interface Token {
  kind: "brace-open" | "brace-close" | "bracket-open" | "bracket-close" | "colon" | "comma" | "string" | "other";
  /** string token 的解码值（JSON.parse 结果）；非 string 为 ""。 */
  value: string;
}

/** 将 JSON 文本切分为结构 token；字符串值按 JSON 语义解码（转义键归一化）。 */
function tokenize(raw: string, file: string): { tokens: Token[]; error?: Diagnostic } {
  const tokens: Token[] = [];
  let i = 0;
  const n = raw.length;
  while (i < n) {
    const ch = raw[i]!;
    if (ch === "{") {
      tokens.push({ kind: "brace-open", value: "" });
      i++;
    } else if (ch === "}") {
      tokens.push({ kind: "brace-close", value: "" });
      i++;
    } else if (ch === "[") {
      tokens.push({ kind: "bracket-open", value: "" });
      i++;
    } else if (ch === "]") {
      tokens.push({ kind: "bracket-close", value: "" });
      i++;
    } else if (ch === ":") {
      tokens.push({ kind: "colon", value: "" });
      i++;
    } else if (ch === ",") {
      tokens.push({ kind: "comma", value: "" });
      i++;
    } else if (ch === '"') {
      let j = i + 1;
      let escaped = false;
      while (j < n) {
        const c = raw[j]!;
        if (escaped) {
          escaped = false;
          j++;
          continue;
        }
        if (c === "\\") {
          escaped = true;
          j++;
          continue;
        }
        if (c === '"') break;
        j++;
      }
      if (j >= n) {
        return { tokens, error: { code: "E_JSON", severity: "error", file, message: "字符串未闭合" } };
      }
      const literal = raw.slice(i, j + 1);
      let value: string;
      try {
        value = JSON.parse(literal) as string;
      } catch {
        return { tokens, error: { code: "E_JSON", severity: "error", file, message: `字符串解析失败：${literal.slice(0, 40)}` } };
      }
      tokens.push({ kind: "string", value });
      i = j + 1;
    } else if (/\s/.test(ch)) {
      i++;
    } else {
      // 数字/字面量：跳到下一个结构字符（字符串已在上分支处理，引号不会出现在这里）
      let j = i;
      while (j < n && !/[{}\[\]:",\s]/.test(raw[j]!)) j++;
      if (j === i) j = i + 1;
      tokens.push({ kind: "other", value: "" });
      i = j;
    }
  }
  return { tokens };
}

/**
 * 检测 JSON 文本中的重复对象键（含数组内对象与嵌套对象，转义键按解码值归一化）。
 * 返回诊断；文本本身的括号失衡等错误也以诊断报告（调用方随后 JSON.parse 会复核）。
 */
export function findDuplicateKeys(raw: string, file: string): Diagnostic[] {
  const diags: Diagnostic[] = [];
  const { tokens, error } = tokenize(raw, file);
  if (error) {
    diags.push(error);
    return diags;
  }
  let pos = 0;
  const path: string[] = [];

  function peek(): Token | undefined {
    return tokens[pos];
  }

  function next(): Token | undefined {
    return tokens[pos++];
  }

  function parseValue(): boolean {
    const t = peek();
    if (!t) return false;
    if (t.kind === "brace-open") return parseObject();
    if (t.kind === "bracket-open") return parseArray();
    next();
    return true;
  }

  function parseObject(): boolean {
    next(); // {
    const seen = new Set<string>();
    for (;;) {
      const t = peek();
      if (!t) return false;
      if (t.kind === "brace-close") {
        next();
        return true;
      }
      if (t.kind !== "string") return false;
      next();
      const key = t.value;
      const colon = next();
      if (!colon || colon.kind !== "colon") return false;
      if (seen.has(key)) {
        diags.push({
          code: "E_DUP_KEY",
          severity: "error",
          file,
          fieldPath: [...path, key].join("."),
          message: `重复的对象键 ${JSON.stringify(key)}（路径 ${[...path, key].join(".")}）`,
        });
      } else {
        seen.add(key);
      }
      path.push(key);
      if (!parseValue()) return false;
      path.pop();
      const sep = peek();
      if (!sep) return false;
      if (sep.kind === "comma") {
        next();
        continue;
      }
      if (sep.kind === "brace-close") continue;
      return false;
    }
  }

  function parseArray(): boolean {
    next(); // [
    let index = 0;
    for (;;) {
      const t = peek();
      if (!t) return false;
      if (t.kind === "bracket-close") {
        next();
        return true;
      }
      path.push(String(index));
      if (!parseValue()) return false;
      path.pop();
      index++;
      const sep = peek();
      if (!sep) return false;
      if (sep.kind === "comma") {
        next();
        continue;
      }
      if (sep.kind === "bracket-close") continue;
      return false;
    }
  }

  if (!parseValue()) {
    diags.push({ code: "E_JSON", severity: "error", file, message: "JSON 结构失衡" });
  } else if (pos !== tokens.length) {
    diags.push({ code: "E_JSON", severity: "error", file, message: "顶层值后存在多余内容" });
  }
  return diags;
}
