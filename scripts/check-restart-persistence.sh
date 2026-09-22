#!/usr/bin/env bash
# 重启持久性检查：比较重启后端容器前后的数据库可观察状态——
# 迁移登记（含 applied_at，重建库必然变化）与业务表清单完全一致，
# 且 /api/version 的数据库结构版本不变。依据是应用自身的迁移元数据，
# 不需要第二写连接，也不向业务 schema 写探针。
# 用法：check-restart-persistence.sh [instance=test]
set -euo pipefail
source "$(dirname "$0")/lib-docker.sh"
detect_docker

INSTANCE="${1:-test}"
BASE_URL="$(instance_base_url "$INSTANCE")"
DB_PATH="$ROOT/var/$INSTANCE/db/hof.sqlite"

STATE_BEFORE="$(node "$ROOT/scripts/db-state.mjs" "$DB_PATH")"
VERSION_BEFORE="$(curl -sf --max-time 10 "$BASE_URL/api/version")"

compose_for "$INSTANCE" restart backend >/dev/null
wait_healthy "$BASE_URL" 60

STATE_AFTER="$(node "$ROOT/scripts/db-state.mjs" "$DB_PATH")"
VERSION_AFTER="$(curl -sf --max-time 10 "$BASE_URL/api/version")"

if [[ "$STATE_BEFORE" != "$STATE_AFTER" ]]; then
  echo "错误：重启前后数据库状态不一致（迁移登记或表清单变化，意味着数据库被重建/改写）：" >&2
  echo "  before: $STATE_BEFORE" >&2
  echo "  after:  $STATE_AFTER" >&2
  exit 1
fi

node -e '
  const before = JSON.parse(process.argv[1]).database.schemaVersion;
  const after = JSON.parse(process.argv[2]).database.schemaVersion;
  if (before !== after) {
    console.error(`数据库结构版本重启前后不一致：${before} → ${after}`);
    process.exit(1);
  }
' "$VERSION_BEFORE" "$VERSION_AFTER"

node "$ROOT/scripts/check-health.mjs" "$BASE_URL" >/dev/null
echo "check-restart-persistence: OK（重启后迁移登记、表清单与结构版本原样保持）"
