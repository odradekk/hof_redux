import fs from "node:fs";
import path from "node:path";

/** 招募模板（S1 建队直接依赖；形状与 content/recruitment.json 对应）。 */
export interface RecruitTemplate {
  id: string;
  jobId: string;
  price: number;
  initialLevel: number;
  initialExperience: number;
  initialStats: { str: number; int: number; dex: number; spd: number; luk: number };
  initialHpSp: { maxHp: number; hp: number; maxSp: number; sp: number };
  initialSkillIds: string[];
  /** 穿戴槽位 → 装备定义身份（如 weapon → item.1000）。 */
  initialEquipment: Record<string, string>;
  position: "front" | "back";
  guardPolicy: { kind: "always" | "never" };
  defaultTactics: Array<{ conditions: Array<{ conditionId: string; quantity: number }>; skillId: string }>;
}

/** 首次建队所需的最小内容子集（#25 直接依赖；引用闭合在加载时校验）。 */
export interface PartyContent {
  releaseId: string;
  recruitments: Map<string, RecruitTemplate>;
  jobNames: Map<string, { male: string; female: string }>;
  /** 装备定义身份 → 穿戴槽位。 */
  equipmentSlots: Map<string, string>;
  equipmentNames: Map<string, string>;
  skillNames: Map<string, string>;
  conditionDescriptions: Map<string, string>;
}

function insertUnique<T>(map: Map<string, T>, id: string, value: T): void {
  if (map.has(id)) throw new Error(`内容标识重复：${id}`);
  map.set(id, value);
}

function asRecord(value: unknown, where: string): Record<string, unknown> {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    throw new Error(`${where} 应为对象`);
  }
  return value as Record<string, unknown>;
}

function asString(value: unknown, where: string): string {
  if (typeof value !== "string" || value.length === 0) throw new Error(`${where} 应为非空字符串`);
  return value;
}

function asInt(value: unknown, where: string): number {
  if (typeof value !== "number" || !Number.isSafeInteger(value)) throw new Error(`${where} 应为整数`);
  return value;
}

/**
 * 从内容快照目录加载建队子集并校验引用闭合。
 * - 招募模板的职业/装备/技能/战术条件须在快照内存在；缺失即抛错（未知机制不退化）。
 * - contentDir 为快照目录（含 release.json 的那一级）。
 */
export function loadPartyContent(contentDir: string): PartyContent {
  const releaseId = asString(
    (asRecord(JSON.parse(fs.readFileSync(path.join(contentDir, "release.json"), "utf8")), "release.json").releaseId),
    "release.json releaseId",
  );
  const read = (file: string): unknown => JSON.parse(fs.readFileSync(path.join(contentDir, file), "utf8"));

  const jobNames = new Map<string, { male: string; female: string }>();
  for (const job of (asRecord(read("jobs.json"), "jobs.json").jobs as unknown[])) {
    const record = asRecord(job, "job");
    const presentation = asRecord(record.presentation, "job.presentation");
    insertUnique(jobNames, asString(record.id, "job.id"), {
      male: asString(presentation.nameMale, "job.nameMale"),
      female: asString(presentation.nameFemale, "job.nameFemale"),
    });
  }
  const equipmentSlots = new Map<string, string>();
  const equipmentNames = new Map<string, string>();
  for (const item of (asRecord(read("items.json"), "items.json").items as unknown[])) {
    const record = asRecord(item, "item");
    if (record.kind !== "equipment") continue;
    const id = asString(record.id, "item.id");
    insertUnique(equipmentSlots, id, asString(record.slot, "item.slot"));
    equipmentNames.set(id, asString(record.name, "item.name"));
  }
  const skillNames = new Map<string, string>();
  for (const skill of (asRecord(read("skills.json"), "skills.json").skills as unknown[])) {
    const record = asRecord(skill, "skill");
    insertUnique(skillNames, asString(record.id, "skill.id"), asString(record.name, "skill.name"));
  }
  const conditionDescriptions = new Map<string, string>();
  const conditionsRaw = asRecord(read("conditions.json"), "conditions.json").conditions as unknown[];
  for (const condition of conditionsRaw) {
    const record = asRecord(condition, "condition");
    insertUnique(conditionDescriptions, asString(record.id, "condition.id"), asString(record.description, "condition.description"));
  }

  const recruitments = new Map<string, RecruitTemplate>();
  for (const raw of (asRecord(read("recruitment.json"), "recruitment.json").recruitments as unknown[])) {
    const record = asRecord(raw, "recruitment");
    const id = asString(record.id, "recruitment.id");
    const template: RecruitTemplate = {
      id,
      jobId: asString(record.jobId, `${id} jobId`),
      price: asInt(record.price, `${id} price`),
      initialLevel: asInt(record.initialLevel, `${id} initialLevel`),
      initialExperience: asInt(record.initialExperience, `${id} initialExperience`),
      initialStats: {
        str: asInt((asRecord(record.initialStats, `${id} initialStats`) as Record<string, unknown>).str, `${id} str`),
        int: asInt((asRecord(record.initialStats, `${id} initialStats`) as Record<string, unknown>).int, `${id} int`),
        dex: asInt((asRecord(record.initialStats, `${id} initialStats`) as Record<string, unknown>).dex, `${id} dex`),
        spd: asInt((asRecord(record.initialStats, `${id} initialStats`) as Record<string, unknown>).spd, `${id} spd`),
        luk: asInt((asRecord(record.initialStats, `${id} initialStats`) as Record<string, unknown>).luk, `${id} luk`),
      },
      initialHpSp: {
        maxHp: asInt((asRecord(record.initialHpSp, `${id} initialHpSp`) as Record<string, unknown>).maxHp, `${id} maxHp`),
        hp: asInt((asRecord(record.initialHpSp, `${id} initialHpSp`) as Record<string, unknown>).hp, `${id} hp`),
        maxSp: asInt((asRecord(record.initialHpSp, `${id} initialHpSp`) as Record<string, unknown>).maxSp, `${id} maxSp`),
        sp: asInt((asRecord(record.initialHpSp, `${id} initialHpSp`) as Record<string, unknown>).sp, `${id} sp`),
      },
      initialSkillIds: (record.initialSkillIds as unknown[]).map((s) => asString(s, `${id} skill`)),
      initialEquipment: Object.fromEntries(
        Object.entries(asRecord(record.initialEquipment, `${id} initialEquipment`)).map(([slot, itemId]) => [
          slot,
          asString(itemId, `${id} equipment ${slot}`),
        ]),
      ),
      position: (() => {
        const position = asString(record.position, `${id} position`);
        if (position !== "front" && position !== "back") throw new Error(`${id} position 非法：${position}`);
        return position;
      })(),
      guardPolicy: (() => {
        const kind = asString(asRecord(record.guardPolicy, `${id} guardPolicy`).kind, `${id} guard kind`);
        if (kind !== "always" && kind !== "never") throw new Error(`${id} guard kind 非法：${kind}`);
        return { kind };
      })(),
      defaultTactics: (record.defaultTactics as unknown[]).map((t, index) => {
        const tactic = asRecord(t, `${id} tactic ${index}`);
        return {
          conditions: (tactic.conditions as unknown[]).map((c) => {
            const condition = asRecord(c, `${id} tactic condition`);
            return {
              conditionId: asString(condition.conditionId, `${id} condition id`),
              quantity: asInt(condition.quantity, `${id} condition quantity`),
            };
          }),
          skillId: asString(tactic.skillId, `${id} tactic skill`),
        };
      }),
    };
    // 引用闭合：未知职业/装备/技能/条件直接拒绝，不退化。
    if (!jobNames.has(template.jobId)) throw new Error(`${id} 引用未知职业：${template.jobId}`);
    for (const [slot, itemId] of Object.entries(template.initialEquipment)) {
      if (equipmentSlots.get(itemId) !== slot) throw new Error(`${id} 装备 ${itemId} 不属于槽位 ${slot}`);
    }
    for (const skillId of template.initialSkillIds) {
      if (!skillNames.has(skillId)) throw new Error(`${id} 引用未知技能：${skillId}`);
    }
    for (const tactic of template.defaultTactics) {
      if (!skillNames.has(tactic.skillId)) throw new Error(`${id} 战术引用未知技能：${tactic.skillId}`);
      for (const condition of tactic.conditions) {
        if (!conditionDescriptions.has(condition.conditionId)) {
          throw new Error(`${id} 战术引用未知条件：${condition.conditionId}`);
        }
      }
    }
    insertUnique(recruitments, id, template);
  }

  return { releaseId, recruitments, jobNames, equipmentSlots, equipmentNames, skillNames, conditionDescriptions };
}
