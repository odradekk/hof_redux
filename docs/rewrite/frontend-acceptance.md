# Frontend redesign implementation and acceptance

Implemented against frontend-redesign.md and ui-baseline at upstream d57a301.
The static prototype and proposed screenshots remain the design reference. This
document is an implementation record, not approval of newly rendered screenshots.

## Adopted design decisions

The request to follow the document and visual baseline adopts its recommended
D-UI-1 through D-UI-7 choices: readable role-based colors, legacy dollar formatting,
216-color message preferences, checkbox-plus-quantity shop forms, folded battle
segments after the fifth, split GET routes with redirects, and CI-only Playwright
plus axe. There is no production Node build, SPA, or JavaScript requirement.

- Shared 780px shell, original title/carpet/button/NPC artwork, six-item player
  menu, team/money HUD with a dungeon-run indicator (stamina is shown per character
  on unit cards since 2026-10-06), tablet fluid layout, and mobile stacked tables
- Array-only unit/item/skill/feed/HUD data, anonymous components, registries,
  Chinese terms and form errors, custom text pagination, and cache-versioned CSS
- Login/setup/home, player details in legacy section order, shared tactics form,
  recruitment, inventory categories, shop buy/sell/work, smithy refine/create,
  unified settings, and preserved transactional/idempotent POST operations
- Dungeon list, party/pack preparation, fog-of-war SVG map and run screen, run logs
  (hunting removed 2026-10-06; see dungeon-design.md); safe BOSS list and detail,
  SVG battle scenes with the legacy geometry, ten-ActorSelected grouping, event
  registration including ActionSkipped, hidden BOSS resources, BOSS/simulation
  retry, and public report categories
- Auction, arena, town/board, catalog/manual/updates and administration
- Local/testing-only /dev/ui exercises real components, including battle states
- Author color is snapshotted when posting. Existing messages retain inherited
  text color. Invalid historical preferences safely fall back to inherited color

## Deliberate accessibility and missing-art handling

- The original archive lacks other/land_aband.gif and other/bg_aband.gif, as already
  recorded in content/validation-report.json. The explicit aband -> build01 alias
  uses existing urban art for both monster cards and battle backgrounds.
  New registered terrain uses markup assets and requires no CSS class.
- The full legacy color palette includes text that is unreadable on the dark
  frame. The selected text color is preserved, with an opaque black or white
  backing chosen by relative luminance to guarantee at least 4.5:1 contrast.
  Empty/default preferences keep the unchanged inherited appearance. Dense selected
  rows/panels use slightly brighter semantic tokens, and recruitment band labels
  use light text, to keep the same hues readable on their lighter backgrounds.
- Data stays server-authoritative. BOSS HP/SP is masked in presentation snapshots,
  headers and results before raw report redaction, not merely hidden with CSS.
- DOM IDs and validation field names may differ. Error messages and aria-invalid
  use input names; the page summary links to actual rendered control IDs.
- Intrinsic image dimensions, accessible row headers, visible focus, Chinese
  labels, no inline CSS/scripts, and zero-JavaScript forms are preserved.

## Automated evidence

Final local implementation verification (2026-10-05):
- PHP 8.4.26 + PostgreSQL 18: 181 tests passed, 14,080 assertions, zero skips
- SQLite: 170 passed, 11 PostgreSQL-specific skips, 14,019 assertions
- Pint, composer validate --strict, Node syntax and offline npm ci passed
- Disposable SQLite and PostgreSQL fixtures/service replays passed the exact-once
  buy/bid/hunt/tactics verifier; this is not browser execution
- Blade-rendered integrity checks cover the main pages, one h1, image alt and
  dimensions, labelled mobile cells, safe markup, pagination and registry routes
- Tests cover battle geometry, event completeness, state reconstruction/resource
  clamps, summons, hidden BOSS snapshots, malicious paths/text, and repeated
  commands. Regression tests cover >100 inventory stacks, null ranking reports,
  ranking slot-to-place mapping and validation anchors.

Run the final tests again after any subsequent changes:
1. composer test and composer lint
2. The same complete suite against PostgreSQL, including concurrency tests
3. composer audit
4. Deployment/browser workflow, including Docker build, translation packaging,
   desktop/tablet/mobile captures, axe, no horizontal overflow, CSP/assets,
   JS-disabled gameplay and retry invariants

## Verification still requiring hosted or human review

- This cloud runtime cannot launch Chromium: ProcessSingleton socket creation
  fails with EPERM. The bounded normal launch attempt was stopped; no restriction
  was bypassed. Post-change screenshots and axe results were not obtained locally.
- Docker is unavailable in this runtime. Translation files are now explicitly
  included in Dockerfile and its allowlist; the hosted deployment job must verify
  the built image, migrations, scheduler, backup and restore.
- Composer audit could not reach Packagist's security-advisory API (curl timeout).
  This is an unverified check, not a clean audit result; hosted CI runs it again.
- CI screenshot artifacts include proposed references and current renders for
  human comparison. Capturing screenshots does not approve visual parity and does
  not automatically overwrite the proposed baseline.
- Real Firefox, Safari/iOS, Android Chrome, 200% zoom and a complete keyboard
  dungeon entry, move and battle flow still require manual acceptance. Browser automation covers
  Chromium and cannot substitute for those checks.

No production deployment or merge is part of this implementation.
