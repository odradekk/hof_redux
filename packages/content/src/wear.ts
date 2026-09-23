import type { EquipmentItemBase, JobDefinition } from "./types.js";

/** 穿戴校验所需的装备投影（完整定义可赋值，测试可用最小对象）。 */
export type WearItem = Pick<EquipmentItemBase, "id" | "slot" | "equipmentType" | "blockedSlots" | "handleCost">;

export interface WearSet {
  weapon?: WearItem;
  shield?: WearItem;
  armor?: WearItem;
}

export interface WearContext {
  level: number;
  dex: number;
}

/**
 * 整套穿戴配置的集中校验（招募模板初始装备与未来招募/换装共用，不由各调用方猜测规则）。
 * 唯一占槽模型为 blockedSlots：装备占用主槽，并阻塞列表中的其他槽；
 * 旧 dh（双手）规范化为阻塞盾槽，不保留第二套特判。
 * 规则来源：槽位映射 class.char.php:690-740；双手阻塞盾（dh）；职业许可 data.job.php；
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
  const occupied = new Map<string, WearItem>();
  for (const [key, slot] of entries) {
    const item = wear[key];
    if (!item) continue;
    if (item.slot !== slot) {
      errors.push(`${item.id} 的槽位为 ${item.slot}，不能放入 ${slot}`);
    }
    if (!job.allowedEquipmentTypes.includes(item.equipmentType)) {
      errors.push(`${item.id}（${item.equipmentType}）不在 ${job.id} 的可装备列表中`);
    }
    occupied.set(slot, item);
  }
  for (const [slot, item] of occupied) {
    for (const blocked of item.blockedSlots) {
      if (blocked === slot) {
        errors.push(`${item.id} 阻塞自身槽位 ${slot}，配置矛盾`);
      } else if (occupied.has(blocked)) {
        errors.push(`${item.id} 阻塞槽位 ${blocked}，与 ${occupied.get(blocked)!.id} 冲突`);
      }
    }
  }
  const cost = (wear.weapon?.handleCost ?? 0) + (wear.shield?.handleCost ?? 0) + (wear.armor?.handleCost ?? 0);
  const capacity = handleCapacity(ctx.level, ctx.dex);
  if (cost > capacity) {
    errors.push(`承载不足：需求 ${cost}，上限 ${capacity}（level=${ctx.level} dex=${ctx.dex}）`);
  }
  return errors;
}
