import type { EquipmentItemBase, JobDefinition } from "./types.js";

export interface WearSet {
  weapon?: EquipmentItemBase;
  shield?: EquipmentItemBase;
  armor?: EquipmentItemBase;
}

export interface WearContext {
  level: number;
  dex: number;
}

/**
 * 整套穿戴配置的集中校验（招募模板初始装备与未来招募/换装共用，不由各调用方猜测规则）。
 * 规则来源：槽位映射 class.char.php:690-740；双手武器阻塞盾（dh）；职业许可 data.job.php；
 * 承载上限 handle = 5 + floor(level/10) + floor(dex/5)，class.char.php:1158-1161。
 */
export function handleCapacity(level: number, dex: number): number {
  return 5 + Math.floor(level / 10) + Math.floor(dex / 5);
}

export function validateWearSet(job: JobDefinition, wear: WearSet, ctx: WearContext): string[] {
  const errors: string[] = [];
  const entries: [keyof WearSet, string][] = [
    ["weapon", "weapon"],
    ["shield", "shield"],
    ["armor", "armor"],
  ];
  for (const [key, slot] of entries) {
    const item = wear[key];
    if (!item) continue;
    if (item.slot !== slot) {
      errors.push(`${item.id} 的槽位为 ${item.slot}，不能放入 ${slot}`);
    }
    if (!job.allowedEquipmentTypes.includes(item.equipmentType)) {
      errors.push(`${item.id}（${item.equipmentType}）不在 ${job.id} 的可装备列表中`);
    }
  }
  if (wear.weapon?.twoHanded && wear.shield) {
    errors.push(`双手武器 ${wear.weapon.id} 与盾 ${wear.shield.id} 冲突`);
  }
  const cost = (wear.weapon?.handleCost ?? 0) + (wear.shield?.handleCost ?? 0) + (wear.armor?.handleCost ?? 0);
  const capacity = handleCapacity(ctx.level, ctx.dex);
  if (cost > capacity) {
    errors.push(`承载不足：需求 ${cost}，上限 ${capacity}（level=${ctx.level} dex=${ctx.dex}）`);
  }
  return errors;
}
