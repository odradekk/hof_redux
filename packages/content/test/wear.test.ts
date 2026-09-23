import test from "node:test";
import assert from "node:assert/strict";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { validateContent } from "../src/validate.js";
import { handleCapacity, validateWearSet } from "../src/wear.js";
import type { EquipmentItemBase, JobDefinition } from "../src/types.js";

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
    const errors = validateWearSet(
      job,
      {
        weapon: r.initialEquipment.weapon ? (items.get(r.initialEquipment.weapon) as EquipmentItemBase) : undefined,
        shield: r.initialEquipment.shield ? (items.get(r.initialEquipment.shield) as EquipmentItemBase) : undefined,
        armor: r.initialEquipment.armor ? (items.get(r.initialEquipment.armor) as EquipmentItemBase) : undefined,
      },
      { level: r.initialLevel, dex: r.initialStats.dex },
    );
    assert.deepEqual(errors, [], r.id);
  }
});

test("双手武器与盾冲突", () => {
  const job = { id: "job.x", allowedEquipmentTypes: ["剑", "盾"] } as JobDefinition;
  const greatsword = {
    id: "item.x",
    slot: "weapon",
    equipmentType: "剑",
    twoHanded: true,
    handleCost: 1,
  } as EquipmentItemBase;
  const shield = { id: "item.y", slot: "shield", equipmentType: "盾", twoHanded: false, handleCost: 1 } as EquipmentItemBase;
  const errors = validateWearSet(job, { weapon: greatsword, shield }, { level: 1, dex: 1 });
  assert.ok(errors.some((e) => e.includes("冲突")), JSON.stringify(errors));
});

test("承载超限被拒绝", () => {
  const job = { id: "job.x", allowedEquipmentTypes: ["剑"] } as JobDefinition;
  const heavy = {
    id: "item.x",
    slot: "weapon",
    equipmentType: "剑",
    twoHanded: false,
    handleCost: 99,
  } as EquipmentItemBase;
  const errors = validateWearSet(job, { weapon: heavy }, { level: 1, dex: 1 });
  assert.ok(errors.some((e) => e.includes("承载不足")), JSON.stringify(errors));
});
