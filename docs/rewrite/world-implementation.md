# World, information and operator implementation

## Application ownership

- Ordinary hunting was replaced by dungeons on 2026-10-06; see `dungeon-design.md`. `DungeonService` runs every dungeon command inside the shared `GameAction` transaction and idempotency boundary, and `WorldService` now runs only mirror simulations. Dungeon eligibility is checked again after the global mutation lock; map items must be owned in the warehouse. Zero encounter weights never select preview-only monsters. Weighted intervals are inclusive `1..sum(weights)`, with no zero-weight endpoint leak.
- `BattleService` resolves persisted characters, jobs, equipment, enchantments, passives, tactics and summon prototypes before entering the pure engine. All modes are explicit. Ordinary enemies equal selected party size; shared-boss specified minions are additional to random minions, as in reference `EnemyParty`.
- Town battles (shared bosses, ranking, simulations) start with restored player HP/SP, as source `DataSavingFormat` excluded those fields. Dungeon battles intentionally start from the stored current HP/SP and write the survivors' values back; this is a Redux rule change. Only per-action reward batches persist money, items and growth, using the recipients alive at award time. Growth batches retain one-level-at-most and discarded-excess rules. Engine snapshots receive XP thresholds for local growth during combat; final snapshot XP is never persisted a second time.
- Party mirror tests use 50 actions; individual character mirror tests use 10. Reports save the configured action limit independently of the number of actions actually taken, and “fight again” preserves the test type, including after an early finish. Older capped reports can recover the limit from their result; older early-finish reports without a saved limit omit the retry rather than guess a different test type. No stamina, reward or injury changes occur. Remembering a party is a separate explicit checkbox.
- The timed `horh` area and the other areas no dungeon uses are no longer reachable; the catalog lists them as unused. No commented-out map becomes playable.

## Reports and public content

Reports store immutable versions, server seed, initial resolved snapshots, events, results and settled rewards in the transaction. Normal report visibility follows `record_battle_log`; a disabled log remains private to its owner/admin for the POST-redirect result. Simulations are private. Boss and ranking report viewers share the escaped battle renderer. Boss public rendering strips the initial snapshot, true boss HP/SP, damage aggregate and arbitrary event payloads; only a narrow event field whitelist is displayed. Report identifiers are model IDs, never file paths.

Manual, tutorial and advanced-rule pages are rewritten static Blade content. No old PHP manual is executed. Catalog pages read the exact immutable gameplay catalog: every playable job, item, skill, monster, open map, selectable condition and enchantment is published with derived cross references (drops, encounter rates, skill-tree learners, recipes), and the rules page reads the same service constants and formulas the game uses. Shared-boss HP/SP and HP-derived rewards stay hidden, as in the legacy union display. Town retains the latest 50 escaped, single-line, Unicode-length-limited messages under the shared transaction lock. Disabled bottom board/external localhost forum are not invented.

## Operator scope

Every console endpoint verifies current administrator authorization. Supported operations: account enumeration/detail, aggregate counts, bounded balance corrections with asset ledger and reason, account deletion, announcement publication/deletion, town moderation, dated report pruning, and safe due-maintenance/bootstrap for bosses/auctions. Each writes an audit. There is no raw editor, credential/IP exposure, automatic abandoned-account pruning, arbitrary forced boss reset or premature auction cancellation.

Account deletion refuses active seller/bidder references so escrow cannot disappear. Inventory is removed before characters to satisfy composite equipment ownership constraints. Closed auction/challenge history retains nullable user references. Sessions are removed. Self-deletion requires current password plus explicit DELETE; administrator deletion requires administrator password plus DELETE and cannot target the acting administrator.

Challenge records are not deleted by report pruning, so cooldowns, kill uniqueness and statistics survive. No automatic retention schedule is asserted: operators choose an explicit cutoff.

## Verification

`tests/Feature/World/WorldTest.php` covers weighted endpoints, dungeon reward batches, reward batch settlement, private reward-free simulations, public pages, escaped board retention and retry, administrator authorization/audit, hidden boss-resource redaction and safe account deletion/active-auction blocking. Tested on PHP 8.4.26 with SQLite and PostgreSQL 18. Browser visual/interaction QA remains an integration responsibility; route/render tests are not a claim of pixel parity.
