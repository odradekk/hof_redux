# Player and economy implementation evidence

## Scope and entry points

`routes/player.php` exposes authenticated Blade pages for roster/recruitment, character management, inventory, shop/work, crafting/refining, and preferences. All mutations are POST requests with CSRF protection and an operation UUID. `PlayerService` validates intent, then uses the shared `GameAction` outer transaction, owner lock, bounded transaction retry, and operation result. Callback-generated randomness uses the operation's server seed. Replayed requests return the committed result; a reused key with changed input is rejected.

No reference PHP is executed. The immutable `ContentCatalog` owns prices, job/skill prerequisites, materials, recipes, enchantment operations, and all displayed content. Inventory rows are the only equipment ownership record. Backpack/equipment movement is atomic and emits asset audit entries.

## Retained source behavior

- Recruitment: `class.main.php:1968–2113`; base types 1–4 cost 2,000 / 2,000 / 2,500 / 4,000; cap five, locked server-side. Starter equipment is included through `CharacterFactory`.
- Stats: `class.main.php:379+`; allocate only nonnegative integers, maximum 255; 3 status points and 1 skill point per level. Superseded on 2026-10-07 by `attribute-design.md`: six attributes including vitality, no maximum, 5 status points per level, HP from vitality. `PlayerRules` retains the XP thresholds and the reference's one-level-per-grant/excess-XP-discard rule (`class.char.php:616–666`). It does not silently rebalance progression.
- Jobs and skill tree: `data.classchange.php`, `data.skilltree.php`, skill `learn` prices. Every job change returns all equipment. Existing skills survive a job change.
- Equipment: `class.char.php:678–759`; job whitelist, four slots, two-handed/shield replacement, capacity `5 + floor(level/10) + floor(DEX/5)`. Replacements are checked before mutation. Stacks are split into a one-unit equipped row.
- Tactics: ordered condition / quantity / action rows, memo swap, fixed-capacity insert/delete, row count derived from INT and level. Only selectable conditions and learned active skills are accepted. Missing memo starts with ordinary attack. Simulations belong to the battle/web module.
- Shop: `ShopList` whitelist; multi-item atomic purchases/sales; explicit sell price or rounded base buy price / 5. Client prices are ignored. Generated items keep the same source-defined sale valuation.
- Crafting: every catalog recipe, zero fee, exact ingredients, optional valid 7000–7199 special material; 1/3 low-only, 1/3 high-only, 1/3 both, matching actual reference switch behavior rather than its stale ratio comment. Output order is special, high, low.
- Refining: `class.smithy.php`; first four steps guaranteed, then 60/40/40/20/20/10%; +10 cap; half base buy price rounded per attempt; failure destroys exactly one item; insufficient funds preserves current refinement, including before the first attempt. Retry cannot reroll a committed attempt.
- Item modifiers: base content, refinement, ordered enchantments. Weapon refinement is `ceil(atk * (1 + refine²/100))`, defense is `ceil(def * (1 + 0.03*refine))`; all extracted enchantment operations have explicit handling. Unknown operations throw rather than becoming inert.

## Explicit corrections / restoration decisions

1. Restore work as one consistent 100-stamina → 500-gold exchange. No abandoned 1–10 selector.
2. Complete skill reset item 7520: retain innate base skills, refund the catalog cost of each learned non-innate skill once, restore valid base tactics, clear the tactic memo. A no-op reset consumes nothing. Reset items 7510–7513 / 7520 are available at existing catalog prices by the coordinator's explicit restoration decision.
3. Preserve stat reset floors 1 / 30 / 50 / 100 and point refunds. Unequip everything; shrink both tactic arrays to the new INT limit. Hidden stone6000 SPD reset is not exposed or accepted.
4. Dismissal returns all equipment and removes the character from saved party selection. Last-character dismissal is refused, avoiding the unsupported zero-roster account state. This is an explicit launch safety decision.
5. INT 251–255 uses the highest tactic tier instead of the reference's undefined local variable.
6. Invalid item/material/job/skill/condition IDs, inactive condition headings, unknown commands, negative quantities/points, over-cap stats, foreign ownership, and malformed colors are rejected server-side.
7. Names are escaped at output and validated as 1–16 Unicode characters, not the old inconsistent byte-count/HTML-escaping approach.
8. Non-JavaScript inventory preference opens every native details section. Default details use browser-native disclosure; all filtering and all actions work without JavaScript.

## Verification

PHP 8.4.26 + Laravel 13 isolated integration harness, SQLite:

- 31 feature tests and over 1,100 assertions pass (the count varies with generated option count)
- Every retained recipe is crafted from exactly its listed material quantities; replay causes no second consumption/output
- Every extracted enchantment is resolved on weapon and armor fixtures; ordered generated-item math and pricing assertions
- Exact work-regeneration boundary; locked economy operations, atomic rollback, identity/ownership, operation replay/hash mismatch
- Recruitment cap/costs, skill reset/refund, stat reset, job prerequisites, equipment weight/two-hand/stack conservation, dismissal, tactics/memo, preferences and unique paid team rename
- Six player pages render through HTTP; names are escaped; writes require authentication/operation IDs and reject GET
- PHP lint and Laravel Pint applied to all authored PHP

PostgreSQL 18.6: all 36 tests pass, including five independent-process concurrency scenarios for duplicate work, recruitment cap, purchase overdraft, double sale, and crafting-material duplication. Each race uses separate PHP processes and database connections, queued behind the shared advisory lock. Browser visual QA remains an integration release gate and is not claimed by these HTTP render tests. Shared combat simulation, growth persistence during battles, and full battle effect coverage are owned by their respective modules.

## Independent-review corrections

- Learned skill 9000 (continue thinking / AND) is selectable and accepted alongside active skills; passive 7000-series skills remain invalid tactic actions. A learn → rendered option → saved rows → seeded battle regression checks both true and false AND chains.
- Empty hunter memos and tactic row padding/insertion/deletion use the hunter's innate Shot 2300, rather than unlearned melee Attack 1000. Fresh hunter memo swaps and repeated row edits round-trip without introducing unusable actions.
- Character detail now shows base / bonus / effective HP, SP and attributes, plus attack, defense, summon and piercing totals through the shared combat `SnapshotFactory`. Displaying totals does not mutate character state.
- Tactic help now states that no matching row skips the action; guard-health labels use strict `>` thresholds matching retained combat behavior.
- Reverified against integrated combat/content on PHP 8.4.26 / PostgreSQL 18.6: 40 player tests pass, including all five independent-process races and the four new review regressions (over 1,200 assertions).
