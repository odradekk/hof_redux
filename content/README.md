# Immutable content package

The JSON files contain inert definitions, never PHP. The application reads them through `App\Domain\Content\ContentCatalog`; it never includes the archived source. Every record retains a string business ID, complete source-data fields, original Unicode strings, and a source path, line, and SHA-256 fingerprint. Source paths are relative to the explicitly supplied reference root, not runtime include paths.

## Rebuild and validate

From the rewritten repository:

```
python3 tools/content/extract.py --source legacy --output content
python3 tools/content/validate.py --content content --assets public/image --report content/validation-report.json
python3 -m unittest discover -s tools/content -p 'test_*.py'
php artisan test --testsuite=Unit
```

For an original checkout use `--source . --assets image`. The extractor uses Python standard library lexical analysis and a closed literal/condition grammar. It does not run PHP, `eval`, `include`, random functions, filesystem functions, population functions, or clocks from the reference. Unknown expressions and procedural case tails stop extraction. Bare enchant identifiers are accepted only after they have been extracted as actual enchant case IDs. `FRONT` and `BACK` constants are explicitly checked against the reference settings.

`manifest.json` records source fingerprints, counts, duplicate definitions/keys, explicit adjustments, and a deterministic SHA-256 content version covering every catalog file. `verifyIntegrity()` rejects version/count mismatches. Preserve a version with every replay/snapshot. Definitions are never edited by the live admin console.

## Catalog API

- `get(kind, id)` returns a definition's data, or throws for unknown IDs/kinds
- `all(kind)` returns ID-keyed data; `record(kind,id)` includes provenance
- `playableSkills()` excludes the explicitly unreachable, empty-handler Berserk 3113; `all('skills')` retains it for forensic completeness
- `playableMonsters()` excludes the two previews and the unreferenced incomplete 1079/1900 definitions; `monster()` also rejects these IDs
- `selectableConditions()` excludes headings and the 18 unlisted empty legacy stat-condition cases; their original labels remain in `all('conditions')`
- `availableSkills(job, level, learned)` evaluates the extracted AND/OR prerequisite tree and excludes already learned IDs
- `canChangeJob(from, to, level)` evaluates all eleven class-change rules
- `availableAreas(inventory, now)` evaluates the three unconditional maps, eight item unlocks, and UTC 02:50-inclusive to 03:00-exclusive timed map
- `monster(id, accountCount, frontIfUnspecified)` resolves explicit population formulas, half-up reward rounding, and caller-chosen formation; it rejects preview-only monsters 1010/1011
- `bossCycle(id, now)` resolves fixed seconds or the original hour-dependent rule for 2004–2007, using UTC

Kinds: items, skills, jobs, monsters, base_characters, areas, conditions, class_changes, recipes, enchants, enchant_pools, economy_rules, skill_tree.

Recipes are keyed by produced item ID and contain `item`, `ingredients`, and `fee`. Enchantment pools are keyed by the original item type and contain `low`/`high` ID lists. `economy_rules/shop.values` contains stock IDs; `auction_types.values` and `refine_types.values` preserve eligible type definitions. Enchantment operations are restricted to `add`, `set`, `append`, and `multiply_round`, with optional `when_type2` restrictions.

## Explicit extraction and rule decisions

- The effective last value of a duplicate PHP array key is retained and both values are listed in the manifest. Duplicate skill case 7005 is identical and unreachable; its first definition is retained.
- Every ordinary monster ID below 2000 has `moneyhold=100`, reflecting the final source override rather than its earlier literal value.
- Boss HP is `base + per_account * account_count`. XP/money are half-up rounded HP/2 and HP/3. Resolve when creating an instance, not whenever users join.
- The cycle of 2004/2005 is 7200 seconds during hour 7/12 respectively; 2006/2007 use hour 19. Outside those hours it is 259200. The procedural override on 2007 takes precedence over its earlier literal cycle.
- Monster 1012 has two source image variants. The application must use its explicit RNG to choose one when instantiating; no ambient random state exists here.
- Monsters 1010/1011 are incomplete display previews with zero encounter weight; they cannot be instantiated as fighters. All remaining monster prototypes have required combat fields.
- Monster 1055 guard `pro50` is normalized to the valid `prob50` policy, with the original spelling recorded as an adjustment. All monster and starter guard/formation enums are validated.
- Item 7500's missing `item_035z.png` uses existing `item_035.png`. Undefined shop item 8012 is removed, not fabricated. Approved reset items 7510–7513 and 7520 are stocked at their existing definition prices. Passive skill fields 7000/7001 normalize `p_maxhp` to `P_MAXHP`, matching their +30/+80 descriptions. These changes are recorded on the affected records and in the manifest.
- Inactive areas remain cataloged with `unlock.kind=unavailable`. The inactive `blow01` references two absent `aband` backgrounds; the validator reports these as explicit non-playable warnings. Every exposed area and combat/item/job icon is validated against reused assets.
- Monsters 1079 and 1900 have empty level/HP/SP source fields and no encounter, summon, or minion references. Their exact strings remain in the catalog, but they are explicitly non-playable and cannot be resolved as fighters. No stats are invented. Numeric validation covers every remaining combat prototype.
- Skill 3113 has no starter, tree, monster-action, item, or recipe reference, and its source handler is empty. It is not offered or accepted as a playable action. No replacement effect is invented.
- Selectable conditions 1617/1618 have descriptions but lack legacy handlers. The combat implementation must supply the explicit enemy-poison percentage semantics; definitions alone do not certify those effects.

## Coverage and release boundary

Current extraction: 181 items, 268 skills (267 potentially playable), 15 jobs, 147 monsters (143 combat prototypes, two previews, and two unreferenced incomplete catalog-only records), four starters, 24 areas (12 exposed), 117 condition records (99 original display definitions plus 18 unlisted source cases), eleven class changes, 90 recipes, 134 enchantments, twelve enchantment pools, three economy rule sets, and 163 skill-tree entries.

Validation checks IDs, source identity, content version, every crafting/stock/job/skill-tree/starting-equipment/action/condition/summon/drop/minion/encounter/unlock/enchantment reference, positive ingredient quantities, nonnegative prices and weights, drop probability sums, target shape, combat required fields, pattern lengths, preview encounter safety, and asset existence. Tests check reproducibility and source-byte hashes as well as representative numerical rules and boundary times.

This package certifies extraction and reference integrity only. It does not certify all skill/effect implementations, combat balance, persistence, concurrency, or end-to-end gameplay. The combat suite must separately verify each playable effect and the application must never silently substitute an unknown effect with basic attack.
