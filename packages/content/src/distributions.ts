import type { DropEntry, EncounterEntry } from "./types.js";

/**
 * S1 纯分布函数：与旧语义对齐、供校验与未来引擎共用。
 * 全部使用“调用方提供的固定随机整数”，不自行取随机数、不读时间。
 */

/** gb0 遭遇：总权重 W>0，取均匀整数 roll（1..W），返回首个累计权重 >= roll 的候选。零权重永不命中。 */
export function pickEncounter(candidates: EncounterEntry[], roll: number): EncounterEntry {
  const total = candidates.reduce((sum, c) => sum + c.weight, 0);
  if (!Number.isInteger(total) || total <= 0) {
    throw new Error(`遭遇总权重非法：${total}`);
  }
  if (!Number.isInteger(roll) || roll < 1 || roll > total) {
    throw new Error(`遭遇 roll 越界：${roll}（应为 1..${total}）`);
  }
  let cumulative = 0;
  for (const c of candidates) {
    cumulative += c.weight;
    if (roll <= cumulative) return c;
  }
  throw new Error(`遭遇选择失败：roll=${roll} total=${total}`);
}

/** 单只怪物掉落：固定分母 10000 单次抽取。roll 为 1..10000，返回命中的物品 ID 或 null（无掉落）。 */
export function pickDrop(entries: DropEntry[], denominator: number, roll: number): string | null {
  if (denominator !== 10000) {
    throw new Error(`掉落分母非法：${denominator}（S1 固定 10000）`);
  }
  if (!Number.isInteger(roll) || roll < 1 || roll > 10000) {
    throw new Error(`掉落 roll 越界：${roll}（应为 1..10000）`);
  }
  let cumulative = 0;
  for (const e of entries) {
    cumulative += e.weight;
    if (roll <= cumulative) return e.itemId;
  }
  return null;
}

/** S1 掉落区间端点（entries 顺序即抽取顺序）：便于测试断言每个区间边界。 */
export function dropIntervals(entries: DropEntry[]): { itemId: string; from: number; to: number }[] {
  let cursor = 1;
  return entries.map((e) => {
    const from = cursor;
    const to = cursor + e.weight - 1;
    cursor = to + 1;
    return { itemId: e.itemId, from, to };
  });
}
