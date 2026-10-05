# HOF Redux implementation

## Goal
Complete PHP 8.4.26 rewrite of the legacy HOF game, keeping its recognizable UI and retained gameplay. No old-player save/API compatibility. Restore auction listing/work and complete skill reset. Laravel 13 + Blade, PostgreSQL 18, PHP-FPM/Nginx, Docker Compose.

## Working rules
- KISS: one modular application and database. Do not add speculative frameworks, generic buses, plugin systems or empty abstraction layers.
- Preserve legacy source as historical evidence under `legacy/`; never serve it in production or execute old web entrypoints. Preserve original bytes when relocating.
- Use narrow, explicit module operations. Combat has no HTTP, database, file or global RNG dependencies.
- Money/items/equipment/auction transfers and rewards must be transactional and retry-safe. Never trust client prices or ownership.
- Record intended-rule bug fixes and test them. Do not silently replace unsupported effects with generic attacks or claim partial coverage as complete.
- Each worker owns an isolated worktree and scoped files. Commit coherent changes; integration and upstream publication are coordinator-owned.
- No production deployment, credential disclosure, force push or deletion of unrelated history.

## Verification
Follow `docs/rewrite/feature-inventory.md` and `docs/rewrite/architecture.md`. Run the tests/lint applicable to each change, then aggregate integration, PostgreSQL concurrency and UI tests before release. Report unrun checks and unresolved content explicitly. A scaffold or playable slice is not full completion.

- All new or rewritten code comments must be in English. Keep byte-preserved legacy reference comments unchanged.
