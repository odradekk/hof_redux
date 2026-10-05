# Hall of Fame Redux

A server-rendered Laravel 13 / PHP 8.4.26 rewrite with PostgreSQL 18, Blade, PHP-FPM and Nginx. The first release is intended to include the complete retained game; do not equate a passing platform test with gameplay release acceptance.

## Run locally with Docker Compose

1. Copy `.env.example` to `.env`. Set a unique `DB_PASSWORD` and generate an `APP_KEY` (32 random bytes, base64 encoded with the `base64:` prefix). Never commit `.env`.
2. Run `docker compose build` and `docker compose up -d db`.
3. Run `docker compose run --rm app php artisan migrate --force`.
4. Run `docker compose up -d`. Open http://localhost:8080, register, then name the team and choose the first character.

The scheduler runs the same application image. The database port is not published. The web port binds localhost by default. Production requires a TLS reverse proxy, `APP_ENV=production`, `APP_DEBUG=false`, the correct `APP_URL`, and `SESSION_SECURE_COOKIE=true`. In production, all generated links, assets and form actions use the exact configured `APP_URL` origin and scheme; set it to the public HTTPS origin (for example `https://hof.example.com`). The application does not trust forwarded host/protocol headers. Keep the Compose HTTP listener private behind the TLS proxy; the proxy must route that public origin to localhost:8080. Never expose plain HTTP sessions on the public internet. Do not share the test application key from `phpunit.xml` with production. Back up PostgreSQL before upgrading and run migrations deliberately; startup never silently migrates or deletes accounts.

## Development and tests

Install PHP 8.4.26 with PDO PostgreSQL, mbstring, intl, bcmath, zip and XML extensions, copy `.env.example` to `.env`, then run `composer install`. Run `composer test`, `composer lint`, and `composer audit`. The default feature suite uses in-memory SQLite for speed; set DB_CONNECTION/DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD in the environment to run against PostgreSQL. PostgreSQL is required to verify production check constraints and row/advisory lock behavior; SQLite results alone are insufficient for release.

Money is an integer. One stamina equals 86,400 integer units; regeneration adds exactly 500 units per elapsed second, capped at 8,640,000 units. This avoids floating-point drift and represents the legacy 500 stamina/day rate exactly at one-second resolution. Account login IDs are case-insensitive ASCII identifiers; team names and character names are distinct, escaped Unicode text. First-character choices retain warrior/sorcerer; recruitment can use all four base types.

## Archived source and assets

`legacy/` is an inert byte-preserved archive of the previous tracked source and assets. Its README documents redacted credentials and omitted runtime data. Never execute it or point a web root at it. It is outside `public/`, excluded by the Docker build allowlist, and not loaded by the application. Static GIF/PNG assets are copied verbatim to `public/`; the original CSS declarations are retained with obsolete non-English comments removed. `public/app.css` contains responsive corrections, `public/battle.css` contains CSP-compatible battle presentation, and `public/information.css` styles the manual and game data pages until the planned `public/css/hof.css` replaces these files. No existing player saves are imported.

The repository root must never be an HTTP document root. Nginx serves only `public/` and executes only its `index.php`. Composer autoloads only new `App\` classes, not legacy PHP. All state changes use authenticated POST routes with CSRF protection; session IDs rotate after authentication and logout invalidates the session. The production database is the single transactional state store.

## Administrator access

There is no default administrator or shared administrator password. First register an ordinary account through the application. A trusted server operator can then run `docker compose exec app php artisan admin:access LOGIN` and review the confirmation prompt. For an explicitly approved noninteractive operation, run `docker compose exec -T app php artisan admin:access LOGIN --confirm --no-interaction`. Replace `LOGIN` with that existing account's login ID; this command never creates an account.

To remove access, run `docker compose exec app php artisan admin:access LOGIN --revoke`, or add `--confirm --no-interaction` for an explicitly approved noninteractive revoke. Changes are transactional and recorded in the administrator audit log with a null administrator ID and an `operator-console` actor, rather than impersonating the target account. Restrict shell/container access to trusted operators; this command is not exposed over HTTP.
