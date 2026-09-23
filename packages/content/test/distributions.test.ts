import test from "node:test";
import assert from "node:assert/strict";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { dropIntervals, pickDrop, pickEncounter } from "../src/distributions.js";
import { validateContent } from "../src/validate.js";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CONTENT_DIR = path.resolve(HERE, "..", "..", "..", "content");

function loadGb0() {
  const result = validateContent(CONTENT_DIR);
  assert.equal(result.errors.length, 0);
  const gb0 = result.content!.maps.find((m) => m.id === "map.gb0")!;
  return gb0;
}

test("gb0 按每个敌位独立严格 50%/50%：权重 300/300，总和 600", () => {
  const gb0 = loadGb0();
  assert.equal(gb0.encounters.length, 2);
  assert.equal(gb0.encounters[0]!.monsterId, "monster.1000");
  assert.equal(gb0.encounters[1]!.monsterId, "monster.1001");
  assert.equal(gb0.encounters[0]!.weight, 300);
  assert.equal(gb0.encounters[1]!.weight, 300);
});

test("gb0 遭遇区间端点：1/300 -> 1000，301/600 -> 1001", () => {
  const gb0 = loadGb0();
  assert.equal(pickEncounter(gb0.encounters, 1).monsterId, "monster.1000");
  assert.equal(pickEncounter(gb0.encounters, 300).monsterId, "monster.1000");
  assert.equal(pickEncounter(gb0.encounters, 301).monsterId, "monster.1001");
  assert.equal(pickEncounter(gb0.encounters, 600).monsterId, "monster.1001");
  assert.throws(() => pickEncounter(gb0.encounters, 0));
  assert.throws(() => pickEncounter(gb0.encounters, 601));
});

test("单只怪物掉落为单次分布：10%/10%/6%/2%/1% + 71% 无掉落", () => {
  const result = validateContent(CONTENT_DIR);
  assert.equal(result.errors.length, 0);
  for (const m of result.content!.monsters) {
    assert.equal(m.drops.denominator, 10000);
    assert.deepEqual(
      m.drops.entries.map((e) => e.weight),
      [1000, 1000, 600, 200, 100],
    );
    const intervals = dropIntervals(m.drops.entries);
    assert.deepEqual(intervals, [
      { itemId: "item.6000", from: 1, to: 1000 },
      { itemId: "item.6001", from: 1001, to: 2000 },
      { itemId: "item.6002", from: 2001, to: 2600 },
      { itemId: "item.6003", from: 2601, to: 2800 },
      { itemId: "item.7100", from: 2801, to: 2900 },
    ]);
  }
});

test("掉落区间端点固定输入验证（含无掉落边界）", () => {
  const result = validateContent(CONTENT_DIR);
  assert.equal(result.errors.length, 0);
  const entries = result.content!.monsters[0]!.drops.entries;
  const cases: [number, string | null][] = [
    [1, "item.6000"],
    [1000, "item.6000"],
    [1001, "item.6001"],
    [2000, "item.6001"],
    [2001, "item.6002"],
    [2600, "item.6002"],
    [2601, "item.6003"],
    [2800, "item.6003"],
    [2801, "item.7100"],
    [2900, "item.7100"],
    [2901, null],
    [10000, null],
  ];
  for (const [roll, expected] of cases) {
    assert.equal(pickDrop(entries, 10000, roll), expected, `roll=${roll}`);
  }
  assert.throws(() => pickDrop(entries, 10000, 0));
  assert.throws(() => pickDrop(entries, 10000, 10001));
});
