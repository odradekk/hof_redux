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
  jobIds: Set<string>;
  /** 装备定义身份 → 穿戴槽位。 */
  equipmentSlots: Map<string, string>;
  skillIds: Set<string>;
  conditionIds: Set<string>;
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

  const jobIds = new Set<string>();
  for (const job of (asRecord(read("jobs.json"), "jobs.json").jobs as unknown[])) {
    jobIds.add(asString(asRecord(job, "job").id, "job.id"));
  }
  const equipmentSlots = new Map<string, string>();
  for (const item of (asRecord(read("items.json"), "items.json").items as unknown[])) {
    const record = asRecord(item, "item");
    if (record.kind !== "equipment") continue;
    equipmentSlots.set(asString(record.id, "item.id"), asString(record.slot, "item.slot"));
  }
  const skillIds = new Set<string>();
  for (const skill of (asRecord(read("skills.json"), "skills.json").skills as unknown[])) {
    skillIds.add(asString(asRecord(skill, "skill").id, "skill.id"));
  }
  const conditionIds = new Set<string>();
  const conditionsRaw = asRecord(read("conditions.json"), "conditions.json").conditions as unknown[];
  for (const condition of conditionsRaw) {
    conditionIds.add(asString(asRecord(condition, "condition").id, "condition.id"));
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
    if (!jobIds.has(template.jobId)) throw new Error(`${id} 引用未知职业：${template.jobId}`);
    for (const itemId of Object.values(template.initialEquipment)) {
      if (!equipmentSlots.has(itemId)) throw new Error(`${id} 引用未知装备：${itemId}`);
    }
    for (const skillId of template.initialSkillIds) {
      if (!skillIds.has(skillId)) throw new Error(`${id} 引用未知技能：${skillId}`);
    }
    for (const tactic of template.defaultTactics) {
      if (!skillIds.has(tactic.skillId)) throw new Error(`${id} 战术引用未知技能：${tactic.skillId}`);
      for (const condition of tactic.conditions) {
        if (!conditionIds.has(condition.conditionId)) {
          throw new Error(`${id} 战术引用未知条件：${condition.conditionId}`);
        }
      }
    }
    recruitments.set(id, template);
  }

  return { releaseId, recruitments, jobIds, equipmentSlots, skillIds, conditionIds };
}

/** 测试夹具：与 S1 快照同值的最小建队内容（避免测试依赖真实快照目录）。 */
export function buildTestPartyContent(): PartyContent {
  const recruitments = new Map<string, RecruitTemplate>([
    [
      "recruit.1",
      {
        id: "recruit.1",
        jobId: "job.100",
        price: 2000,
        initialLevel: 1,
        initialExperience: 0,
        initialStats: { str: 10, int: 2, dex: 4, spd: 4, luk: 1 },
        initialHpSp: { maxHp: 300, hp: 300, maxSp: 50, sp: 50 },
        initialSkillIds: ["skill.1000", "skill.1001"],
        initialEquipment: { weapon: "item.1000", shield: "item.3000", armor: "item.5000" },
        position: "front",
        guardPolicy: { kind: "always" },
        defaultTactics: [
          { conditions: [{ conditionId: "condition.1205", quantity: 8 }], skillId: "skill.1001" },
          { conditions: [{ conditionId: "condition.1000", quantity: 0 }], skillId: "skill.1000" },
        ],
      },
    ],
    [
      "recruit.2",
      {
        id: "recruit.2",
        jobId: "job.200",
        price: 2000,
        initialLevel: 1,
        initialExperience: 0,
        initialStats: { str: 2, int: 10, dex: 5, spd: 3, luk: 1 },
        initialHpSp: { maxHp: 150, hp: 150, maxSp: 100, sp: 100 },
        initialSkillIds: ["skill.1000", "skill.1002", "skill.3010"],
        initialEquipment: { weapon: "item.1700", armor: "item.5200" },
        position: "back",
        guardPolicy: { kind: "never" },
        defaultTactics: [
          { conditions: [{ conditionId: "condition.1206", quantity: 20 }], skillId: "skill.3010" },
          { conditions: [{ conditionId: "condition.1000", quantity: 0 }], skillId: "skill.1002" },
          { conditions: [{ conditionId: "condition.1000", quantity: 0 }], skillId: "skill.1000" },
        ],
      },
    ],
  ]);
  return {
    releaseId: "s1-test",
    recruitments,
    jobIds: new Set(["job.100", "job.200"]),
    equipmentSlots: new Map([
      ["item.1000", "weapon"],
      ["item.1700", "weapon"],
      ["item.3000", "shield"],
      ["item.5000", "armor"],
      ["item.5200", "armor"],
    ]),
    skillIds: new Set(["skill.1000", "skill.1001", "skill.1002", "skill.1014", "skill.1017", "skill.3010"]),
    conditionIds: new Set(["condition.1000", "condition.1205", "condition.1206", "condition.1940"]),
  };
}
