import test from "node:test";
import assert from "node:assert/strict";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { validateContent } from "../src/validate.js";
import { handleCapacity, validateWearSet, type WearItem } from "../src/wear.js";
import type { JobDefinition } from "../src/types.js";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CONTENT_DIR = path.resolve(HERE, "..", "..", "..", "content");

function load() {
  const result = validateContent(CONTENT_DIR);
  assert.equal(result.errors.length, 0);
  return result.content!;
}

test("承载公式：5 + floor(level/10) + floor(dex/5)", () => {
  assert.equal(handleCapacity(1, 4), 5);
  assert.equal(handleCapacity(1, 5), 6);
  assert.equal(handleCapacity(10, 5), 7);
});

test("S1 两套初始穿戴均合法", () => {
  const c = load();
  const items = new Map(c.items.map((i) => [i.id, i]));
  for (const r of c.recruitments) {
    const job = c.jobs.find((j) => j.id === r.jobId)!;
    const lookup = (id: string | undefined): WearItem | undefined => {
      if (!id) return undefined;
      const item = items.get(id);
      if (!item || item.kind !== "equipment") throw new Error(`初始装备非装备定义：${id}`);
      return item;
    };
    const errors = validateWearSet(
      job,
      {
        weapon: lookup(r.initialEquipment.weapon),
        shield: lookup(r.initialEquipment.shield),
        armor: lookup(r.initialEquipment.armor),
      },
      { level: r.initialLevel, dex: r.initialStats.dex },
    );
    assert.deepEqual(errors, [], r.id);
  }
});

test("阻塞槽位冲突被拒绝", () => {
  const job = { id: "job.x", allowedEquipmentTypes: ["剑", "盾"] } as JobDefinition;
  const sword = {
    id: "item.x",
    slot: "weapon",
    equipmentType: "剑",
    blockedSlots: ["shield"],
    handleCost: 1,
  } satisfies WearItem;
  const shield = { id: "item.y", slot: "shield", equipmentType: "盾", blockedSlots: [], handleCost: 1 } satisfies WearItem;
  const errors = validateWearSet(job, { weapon: sword, shield }, { level: 1, dex: 1 });
  assert.ok(errors.some((e) => e.includes("冲突")), JSON.stringify(errors));
  assert.deepEqual(validateWearSet(job, { weapon: sword }, { level: 1, dex: 1 }), []);
});

test("承载超限被拒绝", () => {
  const job = { id: "job.x", allowedEquipmentTypes: ["剑"] } as JobDefinition;
  const heavy = {
    id: "item.x",
    slot: "weapon",
    equipmentType: "剑",
    blockedSlots: [],
    handleCost: 99,
  } satisfies WearItem;
  const errors = validateWearSet(job, { weapon: heavy }, { level: 1, dex: 1 });
  assert.ok(errors.some((e) => e.includes("承载不足")), JSON.stringify(errors));
});
