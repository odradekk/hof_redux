import Ajv2020, { type ErrorObject, type ValidateFunction } from "ajv/dist/2020";
import fs from "node:fs";
import path from "node:path";
import type { Diagnostic } from "./validate-types.js";

export const SCHEMA_BASE = "https://hof-redux.local/schema/s1-content.schema.json";

const FILE_TO_DEFINITION: Record<string, string> = {
  "recruitment.json": "recruitmentFile",
  "jobs.json": "jobsFile",
  "items.json": "itemsFile",
  "skills.json": "skillsFile",
  "conditions.json": "conditionsFile",
  "monsters.json": "monstersFile",
  "maps.json": "mapsFile",
  "assets.json": "assetsFile",
};

export const CONTENT_FILES = Object.keys(FILE_TO_DEFINITION);

export interface SchemaValidation {
  fileValidators: Map<string, ValidateFunction>;
  errorsOf: (file: string) => ErrorObject[];
}

/**
 * 加载 content/schema/*.schema.json 并用 ajv 编译为各内容文件的结构校验器。
 * 单文件字段/类型/边界/未知字段/判别联合（装备-材料、通用-具名技能）/9000 排除
 * 均由 Schema 实际执行；跨文件引用与机制语义由语义检查执行。
 */
export function loadSchemaValidators(schemaDir: string): SchemaValidation {
  const ajv = new Ajv2020({ allErrors: true, strict: true });
  const commonRaw = fs.readFileSync(path.join(schemaDir, "s1-common.schema.json"), "utf8");
  const contentRaw = fs.readFileSync(path.join(schemaDir, "s1-content.schema.json"), "utf8");
  ajv.addSchema(JSON.parse(commonRaw) as Record<string, unknown>);
  ajv.addSchema(JSON.parse(contentRaw) as Record<string, unknown>);

  const fileValidators = new Map<string, ValidateFunction>();
  for (const [file, definition] of Object.entries(FILE_TO_DEFINITION)) {
    const validate = ajv.getSchema(`${SCHEMA_BASE}#/definitions/${definition}`);
    if (!validate) {
      throw new Error(`找不到 Schema 定义：${definition}`);
    }
    fileValidators.set(file, validate);
  }
  return {
    fileValidators,
    errorsOf: (file: string) => fileValidators.get(file)?.errors ?? [],
  };
}

/** 将 ajv 错误转换为内容诊断。 */
export function schemaErrorsToDiagnostics(file: string, errors: ErrorObject[]): Diagnostic[] {
  return errors.map((e) => ({
    code: "E_SCHEMA",
    severity: "error" as const,
    file,
    fieldPath: e.instancePath.length > 0 ? e.instancePath.slice(1) : "(root)",
    message: `结构校验失败 ${e.instancePath || "/"}：${e.keyword}${e.message ? ` ${e.message}` : ""}${e.params ? ` ${JSON.stringify(e.params)}` : ""}`,
  }));
}
