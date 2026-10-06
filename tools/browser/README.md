# Disposable browser acceptance

The `deployment-smoke` GitHub Actions job builds both production Docker targets and starts the real Nginx / PHP-FPM / PostgreSQL 18 stack. It generates a temporary key and database password, migrates a fresh volume, bootstraps bosses, mounts test-only fixtures, checks scheduler operation, and runs Chromium acceptance. It then checks mutation invariants and verifies backup/restore into a separate disposable database. Teardown runs even after failure.

## Automated coverage

- Registration, interrupted setup/reload with radio choices, logout, and login
- Screenshots at **1280, 768, and 390 pixels** for login, registration, setup, five-character home, dungeon list and party/pack selection, an active dungeon run, roster, character tactics/equipment, inventory, buy/sell/work shops, refine/create smithies, bosses, populated auction and sort order, populated ranking, fixed-seed battle, report index, town, account, manual, updates, catalog, simulation, administrator index/pagination/user, and `/dev/ui`
- Every captured page: HTTP/asset failures, application console exceptions, strict CSP response and violation events, no inline scripts/styles/handlers, actual document `scrollWidth <= innerWidth`, image `alt`/dimensions, SVG image decoding, one `h1`, stacked-table labels, no raw item/slot translation keys, and no pagination SVG
- The battle's CSP-safe inline SVG stage is supported and its image resources are checked; it is not confused with forbidden pagination icons
- Pinned axe-core injected through Playwright's test-only evaluation, with **zero serious/critical WCAG A/AA findings** required on every page/width; detailed violations and incomplete checks are retained
- Expected 302 redirects `/crafting` to `/smithy/refine` and `/preferences` to `/account`, character anchor Back/Forward, and native simulation submission
- A separate **JavaScript-disabled** Chromium context logs in and completes buy, bid, dungeon entry and retreat, and tactics changes. Each submits invalid input to server validation, then a valid native HTML form, then replays the identical captured POST with the same operation token. Replays must return the same successful destination (including the dungeon run record)
- The subsequent PHP verifier checks exactly one persisted operation per flow, one purchase charge and delivery, one escrow charge, one bid, two retreated dungeon runs with no stamina spent or battle fought, and the requested saved tactics value. Client validation is deliberately bypassed only for negative server-validation cases; no application JavaScript is enabled

The fixture creates a clearly synthetic admin account with five characters, 30 synthetic opponents/ranking entries, three active auctions, inventory/materials/membership, announcement/chat/audit data, and a fixed-seed battle. The scripts require CLI, `HOF_BROWSER_TESTS=1`, and `APP_ENV=local` or `testing`. Seeding additionally refuses any database with existing users and never resets or deletes accounts. Scripts and npm dependencies are mounted into CI only and are excluded from production images. Fixture passwords are synthetic test values, never real credentials.

## Run on a disposable local stack

From the repository root, after configuring an empty local/test Compose database and running migrations:

```sh
docker compose run --rm -e HOF_BROWSER_TESTS=1 -e HOF_APP_ROOT=/var/www/html \
  -v "$PWD/tools/browser:/tmp/hof-browser:ro" app php /tmp/hof-browser/seed.php > tools/browser/fixtures.json
cd tools/browser
npm ci --ignore-scripts
npx playwright install --with-deps chromium
# Install Noto CJK fonts through the operating system's official packages if absent.
npm test
cd ../..
docker compose run --rm -e HOF_BROWSER_TESTS=1 -e HOF_APP_ROOT=/var/www/html \
  -v "$PWD/tools/browser:/tmp/hof-browser:ro" app php /tmp/hof-browser/verify.php
bash tools/browser/backup-smoke.sh
```

Run once per fresh database. A subsequent test run requires a separately recreated disposable stack; do not reset a real database. `HOF_BASE_URL` may select another loopback origin; remote hosts, credential-bearing URLs, and URL paths are rejected. `HOF_FIXTURE_FILE` overrides the generated manifest path and `HOF_SCREENSHOT_DIR` overrides the artifact directory. To run the PHP tools outside Docker, set the same environment opt-in and local/test database, then execute `php tools/browser/seed.php` and `php tools/browser/verify.php`; `HOF_APP_ROOT` is optional in the checkout.

## Evidence and visual approval

Actions uploads `deployment-smoke-*` for seven days, containing:

- `actual/`: full-viewport screenshots plus `.frame` crops for comparison with the cropped prototype references; validation/repeat-flow screenshots
- `proposed/`: the checked-in proposed reference images, copied unchanged
- `visual-review.html`: proposed/actual frame-crop pairs, with links to original images and full viewports
- `acceptance.json`, `axe/*.json`, database verification output, and container diagnostics

Open `visual-review.html` from the downloaded artifact. Proposed images contain different sample content and are **not an automatically approved baseline**. Some pages/widths have no proposed reference. Screenshots, a passing axe run, or a passing workflow do not grant pixel/layout approval. The suite has no snapshot-update or auto-accept path. Inspect the exact commit's artifact and record human approval separately; until then visual comparison remains **pending**.

Remaining manual acceptance from frontend-redesign §13: Chrome, Firefox, Safari/iOS, Android Chrome; actual browser 200% zoom; keyboard-only hunt through battle; and visual comparison/approval of spacing, typography, sprites, wrapping, and responsive layout. Automated no-JS flows complement this checklist.

No `.env`, session state, full request bodies, passwords, or database backups are uploaded. Backup verification restores to `hof_restore_smoke`, never over `hof`, compares nonzero users/characters/migrations counts, and removes the restored database on success. CI removes all disposable volumes at the end. There is no production JavaScript runtime or build requirement.

Adding this suite is not evidence of a passing runtime check. The implementation environment denied Chromium Unix sockets (`EPERM`); local launch was not retried or bypassed. Browser, real-container, PostgreSQL browser-mutation, and visual results must come from a supported hosted runner.

References: [Playwright CI](https://playwright.dev/docs/ci), [axe-core](https://github.com/dequelabs/axe-core), [Compose startup ordering](https://docs.docker.com/compose/how-tos/startup-order/).
