# Combat contract and verification

The simulator is pure PHP. It receives resolved catalog arrays and independent unit values; it never reads content files, models, HTTP, a clock, or process-global random state. `BattleEngine` creates a new `BattleRun` for every simulation. `Combatant` recursively copies input values, including arrays that originally contained PHP references.

## Application boundary

- Construct `BattleEngine($catalog->playableSkills(), $resolvedSummonPrototypes)`
- Resolve all population-dependent monster formulas before simulation. Unresolved arrays are rejected as numeric stats
- `SnapshotFactory::character($base, $resolvedEquipmentBySlot, $passiveDefinitions)` combines stats. Equipment instances/enchantments/refinement must already be resolved by Economy/Application
- `SnapshotFactory::monster($resolvedDefinition, $uniqueUnitId, $rng, $isSharedBoss)` handles missing formation and drop roll. `boss=true` is distinct from `monster=true`
- `BattleSnapshot([$team0, $team1], mode: 'pve'|'pvp'|'boss'|'simulation', contentVersion: ..., maxActions: 100)`
- `simulate($snapshot, new SeededRandom($serverSeed))` returns `BattleOutcome`. Application settlement owns persistence, XP growth, balances, and inventory
- Stable unit IDs must be globally unique within a battle. Tactics are ordered `{condition, quantity, skill}` rows; skill 9000 ANDs the next row. Legacy parallel judge/quantity/action arrays are accepted as *content vocabulary*, not old-save compatibility
- Player units should include `exp` and resolved `experienceThresholds` for levels 1–49. PvE/boss applies per-action local level/XP changes (one level, reset excess), affecting subsequent poison and level conditions without recalculating combat vitals. Application still persists each reward batch once; do not also persist local final XP
- Final `teams`, `retired` summons, `magicCircles`, `events`, and local `randomState` are returned. Events contain no HTML. Views must whitelist and redact boss secrets before serialization; raw events include true resources
- Reward batches are `rewardCandidates[team][] = {action, units, experience, recipients, experiencePerRecipient, money, items, bossDamage}`. XP is aggregated per action then divided with ceiling among living non-monster characters *at that action*. Applications must not redistribute based on final survivors. PvP/simulation return no rewards

## Source-derived effective rules

Evidence is the preserved source at initial commit c3920ac, particularly `class.battle.php`, `class.char.php`, `class.skill_effect.php`, `class.union.php`, and configured `class.rank2.php`.

- Type 1 progress threshold 100; gain rate `sqrt(SPD)+5`. Minimum distance acts next, exact ties selected uniformly using the local RNG. Over-ready units act without advancing other units
- Cast/charge starts subtract charge[0] from current progress, do not consume an action, and consume no SP. Release checks SP again and consumes it once. Post-action progress resets to zero then subtracts charge[1]. Boss delay is rounded to one third, including own casting/recovery
- Max actions is 100. Configured max extension is also 100, so no effective overtime. A separate 10,000-step guard and 1,000-unit guard prevent malformed-content infinite work; they produce explicit reasons/events, not hidden ordinary actions
- Ordinary poison is `round(maxHP/10)+ceil(level/2)`, nonlethal. Boss poison is random 50–150% of rounded currentHP/100, capped at 200 before multiplier, nonlethal. No-resistance poison is guaranteed in the actual source; resistance uses the configured chance
- Physical/magic square-root damage, equipment percent then flat defense, 10% minimum, piercing bonuses, barrier, DEX-influenced attacks, and source named effects are implemented. `hit`/evasion/critical systems are not fabricated; no effective hit roll exists in this source
- Front guards can protect back targets, not whole-team attacks. Probability policies preserve strict `<25/<50/<75` boundary from source. HP guard thresholds are strict `>`
- Powered support effects apply stat changes twice in the effective source; retained and explicitly documented rather than silently rebalanced
- Summon strength is `(1+(sqrt(DEX)*5+LUK)/250)*(1+SummonBonus/100)`, scaling HP/SP/basic stats/attacks, not defenses. Quick summons start at 100.1. Dead summons and shared bosses cannot be resurrected
- Stat percentage gains cap STR/INT/DEX/SPD at 25 times unequipped base stats, preserving pre-equipment cap basis
- Magic circles cap at 5; spending/removing requires sufficient circles. Named skills retain their individual restrictions and formulas
- Boss XP includes per-action positive HP differences against original max HP, plus held kill XP on death. Revived ordinary monsters yield halved remaining held XP, but money/item holdings are consumed on first death
- Rank timeout compares surviving original characters, not summons/monsters. Equal counts draw. The damage tiebreak is only a source comment and is deliberately not implemented

## Explicit corrections / exclusions

Parent approved description-backed repairs under the user's instruction to fix bugs while rewriting:

1. Rank undefined translated variable names: compare actual living original characters; equal draws
2. Passives 7000/7001 lowercase `p_maxhp`: normalize +30/+80 HP as described
3. Condition 1506 counts magic casting, not physical charging
4. Conditions 1617/1618 implement enemy poisoned percentage; missing due duplicated 1612/1613 switch cases
5. Front-row condition 1410 follows current formation after moves/knockback rather than original setup. Stat conditions 1300–1381 implement their documented comparisons, though absent from ordinary selectable labels
6. Dead summons actually leave active rosters; retain state in `retired` and events. Legacy unset modified only a local array
7. Unknown skills/conditions/effect vocabulary and malformed snapshots fail explicitly; no fallback ordinary attack
8. HP/SP and stat domains are bounded; failed casts clear pending state, no-target selections do not call random selection on an empty array, and empty tactics consume an idle action rather than looping forever
9. All-target resurrection recognizes the actual `Dead` priority, repairing a mismatched translated literal
10. Berserk 3113 is an unreferenced catalog orphan: no skill-tree/starter/monster/item references, no numeric design, empty source handler. Content marks it catalog-only; engine explicitly rejects use. No invented effect or playable no-op
11. Preview monsters 1010/1011 and incomplete unreferenced monsters 1079/1900 cannot be instantiated as playable prototypes; Content owns these exclusions

Reward/HP arithmetic uses actual nonnegative HP loss rather than negative overkill; displayed damage records both requested and actual amounts. Reward candidates are data, never automatic persistence or ordinary loot for simulation/PvP.

## Tests and limits of evidence

- `php tests/Unit/Combat/run.php`: deterministic numerical/state regressions across effect families, delay, guards, casts, poison, summons, resurrection, boss modifiers/rewards, rank policy, RNG restoration, and rejected effects
- `php tests/Unit/Combat/catalog_scenarios.php [content-directory]`: all 260 active implemented catalog skill IDs in normal/poison fixtures plus all 143 playable monster prototypes in bounded fights, warnings promoted to exceptions; also checks resource invariants
- `php tests/Unit/Combat/condition_scenarios.php`: every one of 100 implemented condition IDs against five independently calculated threshold oracles
- PHPUnit `BattleEngineTest`: isolated engine invariants integrated into the normal suite
- Catalog execution coverage is **not** full golden parity: it establishes dispatch/snapshot compatibility and resource bounds, while dedicated numeric tests establish specific formulas. No differential run of the insecure legacy application is claimed
- Application concurrency, persistent settlement, user-facing redaction, and reward-driven leveling belong to application integration tests; a pure simulator alone cannot prove them
