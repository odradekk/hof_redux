# Disposable deployment smoke

The `deployment-smoke` GitHub Actions job builds both production Docker targets and starts the real Nginx / PHP-FPM / PostgreSQL stack on the runner. It generates a temporary key and database password, migrates a fresh volume, bootstraps bosses, runs the scheduler task, checks `/up`, exercises registration, interrupted setup, logout/login and the major pages in Chromium, then verifies a PostgreSQL backup restored into a separate disposable database. Every run attempts teardown, even on failure.

The browser dependency is deliberately isolated here as a pinned development dependency; it is excluded from the application image. There is no JavaScript runtime requirement for the game. Screenshots and container diagnostics are uploaded as the `deployment-smoke-*` Actions artifact with seven-day retention. They contain synthetic test accounts only; `.env`, session state and database backups are never uploaded.

To run against your own **disposable local test stack** (this creates a synthetic account and first character):

```sh
cd tools/browser
npm ci --ignore-scripts
npx playwright install --with-deps chromium
npm test
```

`HOF_BASE_URL` may select another loopback port; remote hosts are rejected. `HOF_SCREENSHOT_DIR` overrides the screenshot directory. Run `bash tools/browser/backup-smoke.sh` from the repository root only against the disposable Compose project after the browser test. It restores to `hof_restore_smoke`, never over `hof`, checks nonzero user/character/migration counts, and drops the restored database on success. The workflow removes all test volumes on completion.

These tests are a smoke gate, not exhaustive gameplay acceptance or visual approval. Merely adding the workflow is not evidence that Docker, browser, scheduler or backup/restore checks passed: inspect the run for the exact commit and its screenshots. The implementation environment had no usable Docker daemon and denied Chromium Unix sockets, so runtime execution must occur on a supported test runner; no production deployment is performed.

References: [Playwright CI](https://playwright.dev/docs/ci), [Compose startup ordering](https://docs.docker.com/compose/how-tos/startup-order/).
