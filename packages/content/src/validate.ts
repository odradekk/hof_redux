import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { resolveAssetPath } from "./assetPath.js";
import { findDuplicateKeys } from "./duplicateKeys.js";
import { CONTENT_FILES, loadSchemaValidators, schemaErrorsToDiagnostics } from "./schemas.js";
import type { Diagnostic, ValidationResult } from "./validate-types.js";
import { validateWearSet, type WearSet } from "./wear.js";
import type {
  AssetSourceDefinition,
  ConditionDefinition,
  ItemDefinition,
  JobDefinition,
  LoadedContent,
  MapDefinition,
  MonsterDefinition,
  RecruitmentDefinition,
  SkillDefinition,
} from "./types.js";

/** S1 已登记的具名机制处理器。未知 handlerId 一律报错，不退化。 */
export const HANDLER_REGISTRY: Record<
  string,
  { params: Record<string, "percent-0-100" | "rounding">; appliesTo: string[]; source: string }
> = {
  "recover-sp-max-percent": {
    params: { percent: "percent-0-100", rounding: "rounding" },
    appliesTo: ["skill.3010"],
    source: "old_hof/class/class.skill_effect.php:213-217",
  },
};

function err(list: Diagnostic[], code: string, file: string, message: string, extra?: Partial<Diagnostic>): void {
  list.push({ code, severity: "error", file, message, ...extra });
}

/** 读取并严格校验全部 S1 内容。contentDir 为仓库 content/ 目录。 */
export function validateContent(contentDir: string): ValidationResult {
  const diags: Diagnostic[] = [];
  const rawFiles = new Map<string, string>();
  const parsed = new Map<string, unknown>();

  for (const f of CONTENT_FILES) {
    const p = path.join(contentDir, f);
    if (!fs.existsSync(p)) {
      err(diags, "E_MISSING_FILE", f, `缺少内容文件 ${f}`);
      continue;
    }
    const raw = fs.readFileSync(p, "utf8");
    rawFiles.set(f, raw);
    diags.push(...findDuplicateKeys(raw, f));
    try {
      parsed.set(f, JSON.parse(raw) as unknown);
    } catch (e) {
      err(diags, "E_JSON", f, `JSON 解析失败：${e instanceof Error ? e.message : String(e)}`);
    }
  }
  if (diags.some((d) => d.severity === "error")) {
    return { diagnostics: diags, errors: diags.filter((d) => d.severity === "error") };
  }

  // 单文件结构规则由 JSON Schema 实际执行（含字段/边界/未知字段/判别联合/9000 排除）。
  const schemas = loadSchemaValidators(path.join(contentDir, "schema"));
  for (const f of CONTENT_FILES) {
    const validate = schemas.fileValidators.get(f)!;
    if (!validate(parsed.get(f))) {
      diags.push(...schemaErrorsToDiagnostics(f, schemas.errorsOf(f)));
    }
  }
  if (diags.some((d) => d.severity === "error")) {
    return { diagnostics: diags, errors: diags.filter((d) => d.severity === "error") };
  }

  // 以下形状已由 Schema 保证；此处只做跨表引用与机制语义检查。
  // 每个文件一次类型断言（断言点），不再逐字段重复断言。
  const recruitments = (parsed.get("recruitment.json") as { recruitments: RecruitmentDefinition[] }).recruitments;
  const jobs = (parsed.get("jobs.json") as { jobs: JobDefinition[] }).jobs;
  const items = (parsed.get("items.json") as { items: ItemDefinition[] }).items;
  const skills = (parsed.get("skills.json") as { skills: SkillDefinition[] }).skills;
  const conditions = (parsed.get("conditions.json") as { conditions: ConditionDefinition[] }).conditions;
  const monsters = (parsed.get("monsters.json") as { monsters: MonsterDefinition[] }).monsters;
  const maps = (parsed.get("maps.json") as { maps: MapDefinition[] }).maps;
  const sourceAssets = (parsed.get("assets.json") as { assets: AssetSourceDefinition[] }).assets;

  const ids = new Map<string, string>();
  function register(id: string, prefix: string, file: string): void {
    if (!id.startsWith(prefix)) {
      err(diags, "E_ID_PREFIX", file, `标识前缀非法：${id}（期望 ${prefix}*）`, { definitionId: id });
    }
    if (ids.has(id)) {
      err(diags, "E_DUP_ID", file, `重复的内容标识 ${id}（已见于 ${ids.get(id)}）`, { definitionId: id });
      return;
    }
    ids.set(id, file);
  }
  for (const r of recruitments) register(r.id, "recruit.", "recruitment.json");
  for (const j of jobs) register(j.id, "job.", "jobs.json");
  for (const i of items) register(i.id, "item.", "items.json");
  for (const s of skills) register(s.id, "skill.", "skills.json");
  for (const c of conditions) register(c.id, "condition.", "conditions.json");
  for (const m of monsters) register(m.id, "monster.", "monsters.json");
  for (const m of maps) register(m.id, "map.", "maps.json");
  for (const a of sourceAssets) register(a.id, "asset.", "assets.json");

  const jobById = new Map(jobs.map((j) => [j.id, j]));
  const itemById = new Map(items.map((i) => [i.id, i]));
  const skillById = new Map(skills.map((s) => [s.id, s]));
  const conditionById = new Map(conditions.map((c) => [c.id, c]));
  const monsterById = new Map(monsters.map((m) => [m.id, m]));

  function requireRef(id: string, prefix: string, file: string, defId: string, fieldPath: string): boolean {
    const holder = ids.get(id);
    if (!holder) {
      err(diags, "E_REF", file, `${fieldPath} 引用缺失：${id}`, { definitionId: defId, fieldPath });
      return false;
    }
    if (!id.startsWith(prefix)) {
      err(diags, "E_REF_CATEGORY", file, `${fieldPath} 类别错误：${id}（期望 ${prefix}*，实际见于 ${holder}）`, {
        definitionId: defId,
        fieldPath,
      });
      return false;
    }
    return true;
  }

  // 具名处理器登记：成员、参数结构与作用域（存在性与形状已由 Schema 保证）。
  for (const s of skills) {
    if (s.handlerId === undefined) continue;
    const reg = HANDLER_REGISTRY[s.handlerId];
    if (!reg) {
      err(diags, "E_UNKNOWN_HANDLER", "skills.json", `未知机制处理器 ${s.handlerId}（拒绝发布，不退化）`, {
        definitionId: s.id,
        fieldPath: "handlerId",
        decisionRef: "content-release: 未知处理器即错误",
      });
      continue;
    }
    for (const [pk, pv] of Object.entries(s.handlerParams)) {
      const spec = reg.params[pk];
      if (!spec) {
        err(diags, "E_HANDLER_PARAMS", "skills.json", `处理器 ${s.handlerId} 未知参数 ${pk}`, { definitionId: s.id });
        continue;
      }
      if (spec === "percent-0-100" && (!Number.isInteger(pv) || (pv as number) < 0 || (pv as number) > 100)) {
        err(diags, "E_HANDLER_PARAMS", "skills.json", `参数 ${pk} 须为 0..100 整数`, { definitionId: s.id });
      }
      if (spec === "rounding" && pv !== "ceil" && pv !== "floor" && pv !== "round") {
        err(diags, "E_HANDLER_PARAMS", "skills.json", `参数 ${pk} 须为 ceil|floor|round`, { definitionId: s.id });
      }
    }
    for (const pk of Object.keys(reg.params)) {
      if (!(pk in s.handlerParams)) {
        err(diags, "E_HANDLER_PARAMS", "skills.json", `处理器 ${s.handlerId} 缺少参数 ${pk}`, { definitionId: s.id });
      }
    }
    if (!reg.appliesTo.includes(s.id)) {
      err(diags, "E_HANDLER_SCOPE", "skills.json", `处理器 ${s.handlerId} 未登记用于 ${s.id}`, { definitionId: s.id });
    }
  }

  // 战术：条件引用存在 + 数量区间（区间定义在条件侧）+ 技能引用存在；有序由数组表达。
  function checkTactics(
    file: string,
    defId: string,
    groups: { conditions: { conditionId: string; quantity: number }[]; skillId: string }[],
    fieldPath: string,
  ): void {
    groups.forEach((g, gi) => {
      g.conditions.forEach((c, ci) => {
        const cp = `${fieldPath}[${gi}].conditions[${ci}]`;
        if (!requireRef(c.conditionId, "condition.", file, defId, `${cp}.conditionId`)) return;
        const cdef = conditionById.get(c.conditionId)!;
        if (c.quantity < cdef.quantityRange[0] || c.quantity > cdef.quantityRange[1]) {
          err(diags, "E_COND_QTY", file, `${cp}.quantity 越界（${cdef.id} 允许 ${cdef.quantityRange[0]}..${cdef.quantityRange[1]}，得 ${c.quantity}）`, {
            definitionId: defId,
            fieldPath: `${cp}.quantity`,
          });
        }
      });
      requireRef(g.skillId, "skill.", file, defId, `${fieldPath}[${gi}].skillId`);
    });
  }

  // 招募：职业/技能/装备引用 + 整套穿戴合法性（槽位、职业许可、双手冲突、承载）。
  for (const r of recruitments) {
    requireRef(r.jobId, "job.", "recruitment.json", r.id, "jobId");
    for (const s of r.initialSkillIds) requireRef(s, "skill.", "recruitment.json", r.id, "initialSkillIds");
    const wear: WearSet = {};
    for (const [slot, ref] of Object.entries(r.initialEquipment) as [keyof WearSet, string][]) {
      if (!requireRef(ref, "item.", "recruitment.json", r.id, `initialEquipment.${slot}`)) continue;
      const item = itemById.get(ref)!;
      if (item.kind !== "equipment") {
        err(diags, "E_REF_CATEGORY", "recruitment.json", `initialEquipment.${slot} 须引用装备定义：${ref}`, {
          definitionId: r.id,
        });
        continue;
      }
      wear[slot] = item;
    }
    const job = jobById.get(r.jobId);
    if (job) {
      for (const e of validateWearSet(job, wear, { level: r.initialLevel, dex: r.initialStats.dex })) {
        err(diags, "E_WEAR", "recruitment.json", `初始穿戴非法：${e}`, { definitionId: r.id, fieldPath: "initialEquipment" });
      }
    }
    checkTactics("recruitment.json", r.id, r.defaultTactics, "defaultTactics");
  }

  // 怪物：掉落须引用材料且权重和不超过固定分母（分母与单项正数已由 Schema 保证）。
  for (const m of monsters) {
    let sum = 0;
    m.drops.entries.forEach((e, k) => {
      if (!requireRef(e.itemId, "item.", "monsters.json", m.id, `drops.entries[${k}].itemId`)) return;
      if (itemById.get(e.itemId)!.kind !== "material") {
        err(diags, "E_REF_CATEGORY", "monsters.json", `掉落须引用材料定义：${e.itemId}`, { definitionId: m.id });
      }
      sum += e.weight;
    });
    if (sum > m.drops.denominator) {
      err(diags, "E_DROP", "monsters.json", `掉落权重和 ${sum} 超过固定分母 ${m.drops.denominator}`, { definitionId: m.id });
    }
    checkTactics("monsters.json", m.id, m.tactics, "tactics");
  }

  // 地图：遭遇须引用怪物定义；开放地图须有正权重候选且权重和大于 0；人数区间一致。
  for (const m of maps) {
    let sum = 0;
    let hasPositive = false;
    m.encounters.forEach((e, k) => {
      if (requireRef(e.monsterId, "monster.", "maps.json", m.id, `encounters[${k}].monsterId`)) {
        void monsterById.get(e.monsterId);
      }
      if (e.weight > 0) hasPositive = true;
      sum += e.weight;
    });
    if (!hasPositive) err(diags, "E_ENCOUNTER", "maps.json", "开放地图须有正权重候选", { definitionId: m.id });
    if (sum <= 0) err(diags, "E_ENCOUNTER", "maps.json", "遭遇权重和须大于 0", { definitionId: m.id });
    if (m.enemyCount.min > m.enemyCount.max) {
      err(diags, "E_FIELD", "maps.json", `enemyCount 区间非法：min ${m.enemyCount.min} > max ${m.enemyCount.max}`, {
        definitionId: m.id,
      });
    }
  }

  // 素材：文件存在性/摘要/字节/来源白名单（大小写精确路径）；引用双向闭合。
  const assetById = new Map(sourceAssets.map((a) => [a.id, a]));
  for (const a of sourceAssets) {
    let abs: string;
    try {
      abs = resolveAssetPath(contentDir, a.path).absolutePath;
    } catch (e) {
      err(diags, "E_ASSET_PATH", "assets.json", e instanceof Error ? e.message : String(e), { definitionId: a.id });
      continue;
    }
    if (!fs.existsSync(abs)) {
      err(diags, "E_ASSET_MISSING", "assets.json", `素材文件缺失：${a.path}`, { definitionId: a.id, source: a.sourceFile });
      continue;
    }
    const data = fs.readFileSync(abs);
    if (crypto.createHash("sha256").update(data).digest("hex") !== a.sha256) {
      err(diags, "E_ASSET_HASH", "assets.json", `素材摘要不符：${a.path}`, { definitionId: a.id });
    }
    if (data.length !== a.bytes) {
      err(diags, "E_ASSET_HASH", "assets.json", `素材字节数不符：${a.path}`, { definitionId: a.id });
    }
  }
  const assetUses = new Map<string, Set<string>>();
  function addAssetUse(assetId: string, definitionId: string): void {
    const uses = assetUses.get(assetId) ?? new Set<string>();
    uses.add(definitionId);
    assetUses.set(assetId, uses);
  }
  for (const j of jobs) {
    addAssetUse(j.presentation.imageMaleAssetId, j.id);
    addAssetUse(j.presentation.imageFemaleAssetId, j.id);
  }
  for (const i of items) addAssetUse(i.imageAssetId, i.id);
  for (const s of skills) addAssetUse(s.imageAssetId, s.id);
  for (const m of monsters) addAssetUse(m.imageAssetId, m.id);
  for (const m of maps) addAssetUse(m.backgroundAssetId, m.id);
  for (const aid of assetUses.keys()) {
    if (!assetById.has(aid)) err(diags, "E_REF", "assets.json", `素材引用缺失：${aid}`, { definitionId: aid });
  }
  for (const aid of assetById.keys()) {
    if (!assetUses.has(aid)) err(diags, "E_ASSET_ORPHAN", "assets.json", `素材 ${aid} 未被任何内容引用`, { definitionId: aid });
  }
  const assets = sourceAssets.map((a) => ({ ...a, usedBy: [...(assetUses.get(a.id) ?? [])].sort() }));

  // S1 范围完整性。
  for (const id of [
    "recruit.1",
    "recruit.2",
    "job.100",
    "job.200",
    "item.1000",
    "item.1700",
    "item.3000",
    "item.5000",
    "item.5200",
    "item.6000",
    "item.6001",
    "item.6002",
    "item.6003",
    "item.7100",
    "skill.1000",
    "skill.1001",
    "skill.1002",
    "skill.1014",
    "skill.1017",
    "skill.3010",
    "condition.1000",
    "condition.1205",
    "condition.1206",
    "condition.1940",
    "monster.1000",
    "monster.1001",
    "map.gb0",
  ]) {
    if (!ids.has(id)) err(diags, "E_S1_SCOPE", "release", `S1 首批内容缺失：${id}`, { definitionId: id });
  }

  const errors = diags.filter((d) => d.severity === "error");
  if (errors.length > 0) return { diagnostics: diags, errors };
  return {
    diagnostics: diags,
    errors,
    content: { recruitments, jobs, items, skills, conditions, monsters, maps, assets, rawFiles },
  };
}
