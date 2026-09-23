/** S1 内容包的 TypeScript 形状（与 content/*.json 编辑源一一对应）。 */

export interface SourceRef {
  file: string;
  lines: string;
  note: string;
}

export interface JobDefinition {
  id: string;
  oldNo: string;
  presentation: {
    nameMale: string;
    nameFemale: string;
    imageMaleAssetId: string;
    imageFemaleAssetId: string;
  };
  hpSpCoefficients: [number, number];
  allowedEquipmentTypes: string[];
  sources: SourceRef[];
}

export interface TacticsCondition {
  conditionId: string;
  quantity: number;
}

export interface TacticsGroup {
  conditions: TacticsCondition[];
  skillId: string;
}

export interface GuardPolicy {
  kind: "always" | "never" | "hp-rate-gte" | "probability";
  threshold?: number;
}

export interface RecruitmentDefinition {
  id: string;
  oldNo: string;
  presentation: { name: string };
  jobId: string;
  price: number;
  initialLevel: number;
  initialExperience: number;
  initialStats: { str: number; int: number; dex: number; spd: number; luk: number };
  initialHpSp: { maxHp: number; hp: number; maxSp: number; sp: number };
  initialSkillIds: string[];
  initialEquipment: { weapon?: string; shield?: string; armor?: string };
  position: "front" | "back";
  guardPolicy: GuardPolicy;
  defaultTactics: TacticsGroup[];
  sources: SourceRef[];
}

export interface FutureNeedOld {
  oldNo: string;
  quantity: number;
}

export interface EquipmentItemBase {
  id: string;
  oldNo: string;
  kind: "equipment";
  name: string;
  equipmentType: string;
  slot: "weapon" | "shield" | "armor";
  blockedSlots?: string[];
  atk?: [number, number];
  def?: [number, number, number, number];
  handleCost: number;
  /** 旧 dh 标记；缺席即单手。S1 首批全部为 false。 */
  twoHanded: boolean;
  buyPrice: number;
  imageAssetId: string;
  /** 持有装备逐件独立身份，定义本身不携带实例状态。 */
  instancePolicy: "independent";
  /** 未来制作来源含义（旧 need），S1 不开放制作，不参与闭合引用。 */
  futureCraftNeedsOld?: FutureNeedOld[];
  sources: SourceRef[];
}

export interface MaterialItemBase {
  id: string;
  oldNo: string;
  kind: "material";
  name: string;
  buyPrice: number;
  sellPrice?: number;
  imageAssetId: string;
  stackable: true;
  futureUse?: string;
  futureAffix?: string;
  sources: SourceRef[];
}

export type ItemDefinition = EquipmentItemBase | MaterialItemBase;

export interface SkillTarget {
  side: "enemy" | "self" | "friend" | "all";
  mode: "single" | "random-multi" | "all";
  hits: number;
}

export interface GenericSkillBase {
  id: string;
  oldNo: string;
  name: string;
  imageAssetId: string;
  spCost: number;
  damageType: "physical" | "magic";
  target: SkillTarget;
  power: number;
  preDelay: number;
  postDelay: number;
  ignoreGuard?: boolean;
  support?: boolean;
  handlerId?: undefined;
  handlerParams?: undefined;
  sources: SourceRef[];
}

export interface HandlerSkillBase {
  id: string;
  oldNo: string;
  name: string;
  imageAssetId: string;
  spCost: number;
  damageType: "physical" | "magic";
  target: SkillTarget;
  ignoreGuard?: boolean;
  support?: boolean;
  /** 具名机制处理器；未知 handlerId 拒绝发布，不退化为普攻或空效果。 */
  handlerId: string;
  handlerParams: Record<string, number | string>;
  sources: SourceRef[];
}

export type SkillDefinition = GenericSkillBase | HandlerSkillBase;

export interface ConditionDefinition {
  id: string;
  oldNo: string;
  kind: "always" | "self-sp-absolute-gte" | "self-sp-absolute-lte" | "probability-percent";
  quantityUnit?: string;
  quantityRange: [number, number];
  description: string;
  sources: SourceRef[];
}

export interface DropEntry {
  itemId: string;
  weight: number;
}

export interface MonsterDefinition {
  id: string;
  oldNo: string;
  name: string;
  level: number;
  baseStats: { str: number; int: number; dex: number; spd: number; luk: number };
  maxHp: number;
  maxSp: number;
  atk: [number, number];
  def: [number, number, number, number];
  position: "front" | "back" | null;
  guardPolicy: GuardPolicy;
  tactics: TacticsGroup[];
  experienceReward: number;
  moneyReward: number;
  drops: { denominator: number; entries: DropEntry[] };
  imageAssetId: string;
  sources: SourceRef[];
}

export interface EncounterEntry {
  monsterId: string;
  weight: number;
  preview: boolean;
}

export interface MapDefinition {
  id: string;
  oldId: string;
  name: string;
  nameEn: string;
  background: string;
  backgroundAssetId: string;
  levelLabel: string;
  enemyCount: { mode: "equal-party-size"; min: number; max: number };
  encounters: EncounterEntry[];
  sources: SourceRef[];
}

export interface AssetDefinition {
  id: string;
  path: string;
  sourceFile: string;
  sha256: string;
  bytes: number;
  usedBy: string[];
}

export interface LoadedContent {
  recruitments: RecruitmentDefinition[];
  jobs: JobDefinition[];
  items: ItemDefinition[];
  skills: SkillDefinition[];
  conditions: ConditionDefinition[];
  monsters: MonsterDefinition[];
  maps: MapDefinition[];
  assets: AssetDefinition[];
  rawFiles: Map<string, string>;
}
