# HOF Redux retained-function inventory

Reference: `hof_redux`, commit `c3920ac`. Inspection date: 2026-10-05. Read-only static analysis: no PHP entrypoint executed, no reference files changed. Line references refer to this commit.

## Confirmed execution decisions

On 2026-10-05 the owner approved implementation in this repository, worktree delegation, review, upstream synchronization, and reasonable issue/PR use. First release is complete; no old-player compatibility; original UI reused. Auction and work are fully restored, skill reset completed. Linux/Docker preferred. Approved stack: Laravel 13, Blade, PostgreSQL 18, PHP 8.4.26-FPM, Nginx and Compose. KISS governs implementation: no unnecessary abstractions or redundant code. Remaining ambiguous gameplay details are resolved with source evidence and regression tests; consequential unsupported changes are escalated.

## Scope and evidence standard

The approved first release is a complete rewrite of **retained gameplay** on PHP 8.4.26 with the existing UI/layout/assets initially reused. There are no remaining players; no old save migration, old serialization compatibility, or old URL/API compatibility is required. Source preservation does not make every source file a required feature.

“Reachable” below means a dispatch branch or call chain exists in the reference, not that the old program successfully runs on modern PHP. The clone intentionally lacks credentials and runtime state. Four categories prevent accidental scope inflation:

- **R: retained candidate, connected**: normal navigation and a handler are present
- **D: directly dispatched or conditionally connected**: route exists but may be unlinked, configuration-disabled, state-dependent, or partially implemented
- **O: obsolete candidate**: no current application include/navigation evidence found; not a proof against arbitrary direct web access
- **P: product decision**: contradictory intent, missing bootstrap data, or defect means the rewrite needs an explicit rule rather than blind copying

All positive inventories below are proposed release acceptance scope subject to the P decisions. Defects and insecure mechanics are evidence, never requirements to preserve.

## Entrypoint and route map

`index.php:2–5` includes settings and `CLASS_MAIN`, then constructs `main`. `class/class.main.php:10–23` establishes login/session context, dispatches, and renders the shared shell. `Order():27–285` first performs auction settlement for `menu=auction` or constructs ranking for `menu=rank`, **before** login checking. After successful login it requires first-login setup and dispatches the following:

| Route / request | Handler | Status |
|---|---|---|
| default | `LoginMain` | R |
| `?setting`, POST delete | `SettingProcess/Show`, `DeleteMyData` | R |
| `?hunt` | `HuntShow` | R |
| `?common=<land>` | `MonsterBattle/Show` | R, map-gated |
| `?union=<id>` | `UnionProcess/Show` | R/P, needs fresh boss instances |
| `?char=<id>` | `CharStatProcess/Show` | R |
| `?item` | `ItemShow`; `ItemProcess` call commented out | R listing only |
| `?town` | `TownShow`, `TownBBS` | R |
| `?menu=buy`, `sell` | `ShopBuyProcess/Show`, `ShopSellProcess/Show` | R |
| `?menu=work` | `WorkProcess/Show` | D/P, process body commented out |
| `?shop` | `ShopProcess/Show` | D/P, separate active buy/sell/part-job handler |
| `?recruit` | `RecruitProcess/Show` | R |
| `?menu=refine`, `create` | `SmithyRefineProcess/Show`, `SmithyCreateProcess/Show` | R |
| `?menu=auction` | auction membership, listing, bidding, settlement | R/D/P, listing disabled |
| `?menu=rank` | `RankProcess/Show` + configured `class.rank2.php` | R |
| `?simulate` | `SimuBattleProcess/Show` | D, real dispatched simulation |
| unauthenticated POST Make / `?newgame` | `MakeNewData/NewForm` | R |

`OptionOrder():291–318` is called in both logged-in and logged-out paths: `?rank` (public ranking), `?update`, `?bbs` (disabled flag), `?manual`, `?manual2`, `?tutorial`, `?log`, `?clog`, `?ulog`, `?rlog`, `?gamedata=<type>`, and individual normal/boss/rank log IDs. Account first-login interception occurs before these for incomplete authenticated accounts. `MyMenu():3523–3567` establishes the main visible navigation; `TownShow():2896–2941` establishes facilities.

## Functional acceptance inventory

### F01 — Accounts, first character, and sessions (R)

Registration validates account IDs, duplicate IDs, matching passwords, and account cap; initializes 10,000 money and 100 stamina. First login requires a unique team name, character name, and one of male/female Warrior/Sorcerer choices. Login, logout, remembered session behavior, invalid credentials, account self-deletion, and first-login interruption are actual flows. No email verification, password-recovery flow, social login, guild system, or multiplayer chat service was found; do not invent these as parity requirements.

Sources: `class.main.php:3196–3211,3240–3420,3619–3741`; `class.user.php:36–87,421–429,533–552`; `data.base_char.php`.

Acceptance: new account completes setup exactly once; duplicate/invalid input never creates partial accounts; refresh, retry, logout, and interrupted setup are safe; unauthorized requests cannot access another user's data; fresh credential hashing/session/CSRF design replaces old mechanisms. Character/team Unicode length rules must be agreed rather than reproduce contradictory byte-count text. Account deletion removes dependent active references under the chosen retention policy.

### F02 — Team economy, stamina, preferences, and home (R/P)

> Redux change (2026-10-06): stamina is per character, regenerates only while resting, and drives a fatigue penalty; work and shared bosses charge the chosen characters. See `dungeon-design.md`. The legacy description below remains as evidence.

Home shows tutorial prompts and characters. Stamina regenerates continuously from elapsed time, at 500/day capped at 100, not one midnight grant. Team money, party-memory selection, team-name change costing 100,000, battle-log preference, non-JavaScript inventory option, and user color exist. `MAX_CHAR=5`, `MAX_LEVEL=50`, `MAX_STATUS=255`, level-up grants 3 status points and 1 skill point. Existing money/stamina constants are rule evidence, not new tuning permission.

Sources: `setting.reference.php:3–45`; `class.user.php:235–272,393–404`; `class.main.php:2973–3105,3114–3146`.

Acceptance: injected-clock tests for partial-day regeneration, cap, and exact boundary; balance never becomes negative; spending and resulting mutation commit together; all preferences survive reload; party memory includes only owned characters; rename uniqueness and cost are atomic. Fresh launch starts with no player data.

### F03 — Roster, growth, jobs, skills, equipment (R/P)

Recruit four base character types with names/gender and costs 2,000/2,000/2,500/4,000, capped at five. Character detail supports distributing STR/INT/DEX/SPD/LUK, front/back placement, guard policies (always/never, health thresholds, probabilities), equipment by job and weight, unequip one/all, skill learning, class change and returning equipment, renaming via item 7500, stat-reset consumables, and dismissal.

Sources: `class.main.php:379–769,770–1112,1968–2113`; `class.char.php:514–560,616–759,886–987,1139–1198,1255–1269`; `data.job.php`, `data.skilltree.php`, `data.classchange.php`, `data.item.php`, `data.enchant.php`.

Acceptance: recruitment cap server-side including concurrent requests; XP thresholds and level cap tested; points cannot be forged or made negative; only eligible skills/jobs learned; slot/weight/two-handed interactions and all equipment modifiers verified; inventory conservation across equip/unequip/change-job/reset/dismiss; class-change prerequisites validated on server. Skill reset 7520 is incomplete, and hidden stone item 6000 SPD-reset behavior requires decision (see P03). Define last-character dismissal behavior explicitly.

### F04 — Conditional battle AI and simulation (R/D)

Players edit ordered condition/quantity/action patterns, insert/delete rows, swap a pattern memo, save, and perform a character doppelganger test. Separate party simulation selects 1–5 owned characters and runs a mirror fight. Conditions and actions are data-driven, not merely a fixed attack button.

Sources: `class.main.php:444–519,1114–1185`; `class.char.php:299–367,514–560,1244–1259`; `data.judge.php`, `data.judge_setup.php`, `data.skill.php`; `class.battle.php:427–520,1421+`.

Acceptance: every retained condition/action identifier resolves; order and quantities round-trip; invalid/unknown IDs rejected; row limits and fallback behavior tested; target choice, condition counters, and memo swapping deterministic with seeded randomness. Simulations must not grant money/items/XP or persist combat damage; saving patterns/party memory is separate and intentional. Expose simulation navigation if retained; old URL spelling is not required.

### F05 — Ordinary exploration and battles (R)

> Redux change (2026-10-06): ordinary hunting is replaced by hand-authored dungeons with persistent injuries and permanent death; the hunt routes and service no longer exist. Map items 8000/8009 gate dungeons, and the areas remain as encounter pools. Acceptance for this section is superseded by `dungeon-design.md` §5. The legacy description below remains as evidence.

Hunting lists available maps, previews visible enemies, selects party and remembers selection. Three maps (`gb0/gb1/gb2`) are unconditional; `ac0–ac4` and `snow0–snow2` are gated by map items; `horh` opens at server time 02:50–02:59. Several additional land entries are explicitly commented out in the availability list. Ordinary fights cost one stamina, select weighted encounters, run battle, save growth, award money and dropped items, and optionally record a report. `ENEMY_INCREASE=0` makes random enemy count equal selected party count, up to five, for this configuration.

Sources: `class.main.php:323–377,1187–1347`; `data.land_appear.php`; `data.land_info.php`; `data.monster.php`; `class.monster.php`.

Acceptance: all exposed maps accessible only under correct conditions, including direct crafted requests; select 1–5 owned characters; stamina charged once; all encounter/drop references valid; weighted-choice boundary behavior documented and tested; victory/defeat/draw rewards and persistence verified. Timed map has an explicit release timezone and fake-clock tests. Content not exposed by any retained unlock path is not automatically a release requirement merely because its definition exists.

### F06 — Battle engine and skill effects (R)

Shared engine covers ordinary combat, boss fights, simulations, and PvP. Retained mechanic families include delay/speed ordering, casting and charging, SP use, conditional skill selection, multi-target selection, front/back guarding, physical/magic damage and defense, poison/resistance, healing and resurrection, buffs/debuffs, HP/SP changes, summons and magic circles, passives/regeneration, item/enchantment effects, death, bounded battles, and result reporting. The configured delay algorithm is type 1 (`NextActerNew`); alternate type 0 is not automatically required. Full retained content must be checked against effect dispatch, not just migrated as inert rows.

Sources: `class.battle.php:57–149,208–321,427–1086,1298–1420`; `class.skill_effect.php`; `class.char.php:85–138,183–286,370–608,761–987`; `data.skill.php`, `data.judge.php`, `data.enchant.php`.

Acceptance: seeded scenario suite per retained effect/condition family and representative combinations; ordering ties, no-valid-target, insufficient SP, dead targets, poison kills, summon limits, guard, cast interruption, and repeated state changes; ordinary/PvP/boss result modes tested separately. Battle terminates within defined bounds. Snapshot logs cannot be the only oracle; assert state and rewards. Fix known undefined/mistranslated variable defects under explicit rule decisions rather than preserve them as parity.

### F07 — Shared bosses / union monsters (R/P)

“Union” is a shared persistent boss mechanic, not a player guild feature. Hunting enumerates existing boss runtime files; battle stores shared boss HP/SP and defeat time. Rules include a per-user 20-minute cooldown, 10 stamina, party total-level ceiling, minions, damage-related XP, and content-defined respawn cycle. Runtime state is intentionally excluded from the repository; no initial-instance creator was found in the inspected application call graph.

Sources: `class.main.php:1204–1241,2774–2893`; `class.union.php:136–164,224–353`; `class.user.php:111–125`; boss entries in `data.monster.php`.

Acceptance: fresh install seeds an approved boss roster without old save import; two users see one shared HP pool; parallel hits cannot lose updates or double-award a kill; cooldown, stamina, level limit, minions, reward, death and respawn boundaries tested. Need the intended initial boss IDs/instances and cadence confirmed from content or user, not reconstructed from absent player state.

### F08 — Inventory, shops, and work (R/D/P)

Inventory shows quantities and rich item details with a JavaScript/non-JavaScript presentation choice. Current town exposes separate buy and sell pages, including multi-item selection; stock comes from `ShopList`. Item sale values use explicit sell values or fallback buy-price ratio 1/5. The old combined `?shop` page remains dispatched and has active buy/sell and `partjob` (100 stamina for 500 money). In contrast, the town-linked work page renders a 1–10 work selector but `WorkProcess` is entirely commented out.

Sources: `class.main.php:1349–1814`; `class.JS_itemlist.php`; `class.user.php:310–391`; `global.php:4–16,297–302`.

Acceptance: list/quantity/detail correctness for base and generated items; buy and sell validate stock, ownership, positive bounded quantities and funds; repeated/concurrent submissions cannot duplicate inventory; total and rounding displayed match mutation. Decide whether work is removed or implemented once consistently. Do not create two redundant shop implementations just to preserve old dispatch.

### F09 — Crafting and refining (R)

Craft from material recipes with randomized item attributes and optional special material; current craft fee is zero. Refining supports repeated attempts, caps at +10, costs half base buy price per attempted step, and destroys the item on failure; early insufficient funds returns its current state. Generated items carry refinement and up to three enchantment slots in the old representation, which need not remain the new storage representation.

Sources: `class.main.php:2115–2448`; `class.smithy.php:11–146`; `data.create.php`; `data.item.php`; `data.enchant.php`; `global.php:38–46`.

Acceptance: every retained recipe and enchantment resolves; precisely consume ingredients/optional material once; deterministic RNG fixtures for attribute generation and success/failure; each refinement probability and limit boundary; insufficient funds at beginning/mid-chain; item conservation on failure and invalid requests; resulting stats, name, and sale price correct. Reject ineligible additional materials even if old validation is weak.

### F10 — Auction economy (R/D/P)

Member card 9000 costs 110% of starting money (11,000). Browse/filter, bid, refund/outbid handling, expiry settlement, unsold handling and history are implemented. Listing requires membership, allowed item type, 500 listing fee, 1/3/6/12/18/24 hour duration, global count limit 100, and a 30-second resubmission interval. However `AUCTION_EXHIBIT_TOGGLE=0` disables new listings in this reference. `AUCTION_TOGGLE` also affects navigation/display, so it must not be mistaken for a universal server-side gate. Settlement is request-driven before login.

Sources: `class.main.php:30–36,2450–2771`; `class.auction.php`; `global.php:18–36`; `setting.reference.php:39–44`.

Acceptance if retained: fresh users can create sufficient market supply under approved listing policy; membership, listing and bid eligibility; forbid self-bidding where specified; minimum bid and deadline boundaries; two concurrent bidders/listing requests; outbid refund, sold payment/item transfer, unsold return, and cancellation behavior all conservation-tested and exactly-once. Expiry completes without relying on someone visiting one page. Confirm enablement, fees, duration and unsold policy rather than silently reviving frozen listings.

### F11 — PvP ranking / colosseum (R)

Ranking team selection (1–5), 48-hour reset interval, entering an empty ladder, joining at bottom, challenging a random member of the immediately higher place, first-place prohibition, defender edge cases, rank exchange, win/loss/draw/defense history, cooldowns (60 seconds after win, 24 hours loss/draw), reports, and public ladder are connected. Config explicitly selects `class.rank2.php`; `class.rank.php` is not the current implementation.

Sources: `class.main.php:236–254,1816–1966`; `class.rank2.php:32–482`; `class.user.php:127–233`; `global.php:823–831`.

Acceptance: two fresh accounts can establish and contest ladder; empty/missing defense team and deleted opponent handled; team ownership and reselection boundary; parallel challenges preserve unique consistent ranks; challenge/defense statistics and cooldowns correct; PvP does not accidentally award ordinary-battle loot or persist fight injuries. Result-type 1 currently uses undefined `$team1存活/$team0存活` variables in `BattleResult`; adopt an approved survivor/tiebreak rule, not accidental PHP coercion.

### F12 — Public information, reports, and community (R/D/P)

Public manual, advanced manual, tutorial, update announcements, job/item/judge data and directly dispatched monster encyclopedia, public ranking, normal/boss/ranking report lists and individual report viewers exist. Town plaza is a 50-message one-line board for logged-in users. Separate 150-message bottom board is globally disabled. External BBS link is `http://localhost/bbs/`, with no forum implementation in this repository.

Sources: `class.main.php:291–318,2944–2971,3745–3784`; `global.php:304–492,719–794`; `data.manual0.php`, `data.manual1.php`, `data.tutorial.php`, `data.gd_*.php`; `setting.reference.php:37–39`.

Acceptance: retained manuals reflect new rules; data pages derive from the same catalog as play; logs identify correct participants/outcome and respect retention; invalid log IDs cannot read arbitrary paths; message text safely escaped, length consistently enforced, ordering/retention and concurrent posts correct. Decide bottom board and external forum link, and do not claim the external forum is implemented gameplay.

### F13 — Operator console and maintenance (connected admin; product-scoped replacement)

`admin.reference.php` includes authenticated `admin/admin.php`. Its menu exposes user enumeration/detail/edit/delete; aggregate account/money/character/job/item statistics; IP listing/duplicate IP summary; suspiciously-small-save detection; bulk normal/boss/PvP report deletion; raw auction/ranking/plaza/register/name-index/update/management-log editing. “Other” explicitly links item/enchantment/job/judge/monster/skill lists and `set_action2.php` pattern-authoring tool. Those separate tools should be inventoried as linked utilities; old password/cookie and raw-file editing must not be preserved.

Sources: `admin/admin.php:1–19,62–493`; `admin/list_item.php`, `list_enchant.php`, `list_job.php`, `list_judge.php`, `list_monster.php`, `list_skill3.php`, `set_action2.php`.

`LoginMain` also calls `RegularControl`; periodic maintenance deletes abandoned accounts after 14 days, no more often than 12 hours, suppressing execution at 19:00–01:59. It cleans ladder references and logs management activity. This is active logic, not a detached cron file. Sources: `class.main.php:3107–3112`; `global.php:49–119`; `class.user.php:533–564`.

Acceptance: separate authenticated administrator authorization for every operation, audit trail, safe typed editors, confirmations for destructive actions, aggregate empty-state handling; no user-supplied paths, executable source editing or raw credential cookies. Minimum release operations should support fresh-state initialization, user support, announcements/moderation, boss/market recovery and reports. Confirm which legacy analytical/authoring conveniences are required in release one. Storage-specific “small save file” repair has no parity value in a new database. Automatic 14-day destructive account pruning and IP retention need explicit policy approval.

### F14 — Presentation and asset reuse (R)

Preserve recognizable page structure, menus, character cards, battle rows/status, backgrounds, item icons, colors and typography from `style.css`, `basis.css`, images, and renderer output. Current `BTL_IMG_TYPE=2` selects CSS-based battle rendering. GD endpoint `image.php` and other image modes are alternatives, not automatically three separate engines to rewrite. Manual and catalog content includes mixed Chinese/Japanese/English and mojibake; visual reuse does not authorize broad translation.

Acceptance: visual reference fixtures for login/setup, home, hunt, character AI/equipment, inventory, shop, smithy, boss, auction, PvP and reports; catalog asset existence verified; output encoded UTF-8 without further corrupting source; original assets copied through whitelist/provenance checks; no reference PHP or runtime data shipped with the application. Correctness/security fixes may change markup without redesigning the overall UI.

## Explicit obsolete / conditional candidate ledger

| Candidate | Evidence | Proposed disposition |
|---|---|---|
| `class.rank.php` | `CLASS_RANKING` points to `class.rank2.php`; no current include found | O: retain as historical evidence only |
| `admin/gomi/*` | Admin Other menu explicitly lists root admin tools, no gomi links found | O: diagnostics/prototypes, not release requirements; direct web access is not a product mandate |
| `?shop` combined screen | Dispatch still present, current town links separate buy/sell/work | D/P: consolidate behavior; decide work, no URL compatibility |
| `WorkProcess` | Entire mutation body commented, visible form remains | D/P: repair or remove by decision |
| `ItemProcess` | Empty handler and call commented | O: no generic item-use workflow implied; specific consumables exist on character route |
| Bottom BBS | `BBS_BOTTOM_TOGGLE=0`; handler immediately returns | D/P: optional feature rather than presumed release requirement |
| Auction new listings | `AUCTION_EXHIBIT_TOGGLE=0` checked by listing handler | D/P: explicit enable/retain-disabled decision |
| Additional maps in `data.land_appear.php` comment | Definitions may exist but unlock routes commented | O/P: not playable under current exposure; decide content expansion separately |
| GD `image.php`, old delay mode | Config selects CSS type 2 and delay type 1 | D/O: preserve assets/observable rules, not all alternate implementations |
| Skill-reset 7520 | Sets `$skillReset=true`; no consuming branch in `CharStatProcess` | P: incomplete advertised feature |
| Stone 6000 SPD reset | Handler accepts it but UI list does not offer it | D/P: hidden behavior, not normal feature |
| External BBS | localhost external href, no local forum route | P: replace link/remove; no forum rewrite implied |
| Runtime `.dat` stores | README explicitly excludes players, reports, bosses, auction, ranking, boards, admin runtime data | Exclude migration; create clean state and seed only approved content |

## Decisions that must be resolved before “complete retained gameplay” can be signed off

1. **Boss bootstrap:** which content-defined bosses/instances launch on a fresh installation? Respawn code needs an existing instance; absent old runtime data is not an approved seed source.
2. **Work and auction:** retain work with 100→500 exchange or remove it; enable auction listing or keep the freeze? A fresh economy with no listings cannot exercise full auction play.
3. **Incomplete/hidden consumables:** implement skill reset 7520, retain stat resets 7510–7513 despite missing shop stock, and/or remove stone 6000 hidden SPD reset. Resolve acquisition paths as well as handlers.
4. **Battle defect versus intended rules:** PvP survivor comparison/tiebreak, duplicate skill-effect cases (e.g. 3103), probability interval endpoints, and any formula discrepancy need signed decisions backed by fixtures. “Copy exactly” is not meaningful where old behavior depends on bugs or unsupported PHP coercions.
5. **Operations policy:** automatic 14-day account deletion, IP recording/retention, log retention, player self-delete and last-character dismissal; which admin tools are must-have versus offline maintenance tooling.
6. **Content exposure:** keep current visible/unlockable areas versus activate commented maps; explicit timezone for the 02:50 timed area; do not silently rebalance constants.
7. **Community and naming:** town board retained; bottom board/forum destination; consistent Unicode naming limits and initial editorial cleanup scope.

## Release gate and limitations

Use this as a feature/acceptance ledger, not a line-by-line port plan. For each retained group, link the new implementation, content IDs, approved rule decisions, tests, and review evidence. Content reachability requires a later exhaustive static closure from base characters/shop stock/recipes/encounters/drops/job changes/summons/boss seeds to each item, monster, skill, condition, and enchantment; this inventory establishes group-level and entrypoint evidence, not that full identifier-by-identifier closure. PHP 8.4.26 execution, browser QA, concurrency tests, and security checks remain **not run** at this planning stage.
