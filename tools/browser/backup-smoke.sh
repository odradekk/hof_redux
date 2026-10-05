#!/usr/bin/env bash
set -euo pipefail
# The CI-only destination is different from the live test database. Never restore over hof.
backup=$(mktemp)
trap 'rm -f "$backup"' EXIT
docker compose exec -T db pg_dump -U hof -d hof --format=custom > "$backup"
test -s "$backup"
docker compose exec -T db createdb -U hof hof_restore_smoke
docker compose exec -T db pg_restore -U hof -d hof_restore_smoke --exit-on-error --no-owner < "$backup"
for table in users characters migrations; do
  source_count=$(docker compose exec -T db psql -U hof -d hof -Atc "SELECT count(*) FROM $table")
  restored_count=$(docker compose exec -T db psql -U hof -d hof_restore_smoke -Atc "SELECT count(*) FROM $table")
  test "$source_count" -gt 0
  test "$source_count" = "$restored_count"
  echo "PASS restored $table: $restored_count rows"
done
docker compose exec -T db dropdb -U hof hof_restore_smoke
