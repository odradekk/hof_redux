# World, information and operator implementation

## Application ownership

- `WorldService` runs hunts and mirror simulations inside the shared `GameAction` transaction and idempotency boundary. Map eligibility is checked again after the global mutation lock; unlock items must be owned in the backpack. Zero encounter weights never select preview-only monsters. Weighted intervals are inclusive `1..sum(weights)`, with no zero-weight endpoint leak.
- `BattleService` resolves persisted characters, jobs, equipment, enchantments, passives, tactics and summon prototypes before entering the pure engine. All modes are explicit. Ordinary enemies equal selected party size; shared-boss specified minions are additional to random minions, as in reference `EnemyParty`.
- Combat starts with restored player HP/SP. Source `DataSavingFormat` explicitly excludes those fields. Only per-action reward batches persist money, items and growth, using the recipients alive at award time. Growth batches retain one-level-at-most and discarded-excess rules. Engine snapshots receive XP thresholds for local growth during combat; final snapshot XP is never persisted a second time.
- Party mirror tests use 50 actions; individual character mirror tests use 10. No stamina, reward or injury changes occur. Remembering a party is a separate explicit checkbox.
- The configured timed map uses UTC, 02:50 inclusive to 03:00 exclusive, from the immutable area content. No commented-out map becomes playable.

## Reports and public content

Reports store immutable versions, server seed, initial resolved snapshots, events, results and settled rewards in the transaction. Normal report visibility follows `record_battle_log`; a disabled log remains private to its owner/admin for the POST-redirect result. Simulations are private. Boss and ranking report viewers share the escaped battle renderer. Boss public rendering strips the initial snapshot, true boss HP/SP, damage aggregate and arbitrary event payloads; only a narrow event field whitelist is displayed. Report identifiers are model IDs, never file paths.

Manual, tutorial and advanced-rule pages are rewritten static Blade content. No old PHP manual is executed. Catalog pages read the exact immutable gameplay catalog. Town retains the latest 50 escaped, single-line, Unicode-length-limited messages under the shared transaction lock. Disabled bottom board/external localhost forum are not invented.

## Operator scope

Every console endpoint verifies current administrator authorization. Supported operations: account enumeration/detail, aggregate counts, bounded balance corrections with asset ledger and reason, account deletion, announcement publication/deletion, town moderation, dated report pruning, and safe due-maintenance/bootstrap for bosses/auctions. Each writes an audit. There is no raw editor, credential/IP exposure, automatic abandoned-account pruning, arbitrary forced boss reset or premature auction cancellation.

Account deletion refuses active seller/bidder references so escrow cannot disappear. Inventory is removed before characters to satisfy composite equipment ownership constraints. Closed auction/challenge history retains nullable user references. Sessions are removed. Self-deletion requires current password plus explicit DELETE; administrator deletion requires administrator password plus DELETE and cannot target the acting administrator.

Challenge records are not deleted by report pruning, so cooldowns, kill uniqueness and statistics survive. No automatic retention schedule is asserted: operators choose an explicit cutoff.

## Verification

`tests/Feature/World/WorldTest.php` covers transactional hunt retries, party ownership, map boundaries/unlocks, weighted endpoints, reward batch settlement, private reward-free simulations, public pages, escaped board retention and retry, administrator authorization/audit, hidden boss-resource redaction and safe account deletion/active-auction blocking. Tested on PHP 8.4.26 with SQLite and PostgreSQL 18. Browser visual/interaction QA remains an integration responsibility; route/render tests are not a claim of pixel parity.
