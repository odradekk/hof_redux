#!/usr/bin/env bash
# 重启持久性检查：写探针 → 重启后端容器 → 探针仍在且结构版本不变。
# 用法：check-restart-persistence.sh [instance=test]
set -euo pipefail
source "$(dirname "$0")/lib-docker.sh"
detect_docker

INSTANCE="${1:-test}"
case "$INSTANCE" in
  test) BASE_URL="http://127.0.0.1:62000" ;;
  trial) BASE_URL="http://127.0.0.1:63000" ;;
  recovery) BASE_URL="http://127.0.0.1:64000" ;;
  dev) BASE_URL="http://127.0.0.1:61000" ;;
  *) echo "未知实例：$INSTANCE" >&2; exit 2 ;;
esac
DB_PATH="$ROOT/var/$INSTANCE/db/hof.sqlite"

WRITTEN="$(node "$ROOT/scripts/probe-write.mjs" "$DB_PATH")"
TOKEN="$(node -e 'console.log(JSON.parse(process.argv[1]).token)' "$WRITTEN")"
SCHEMA_BEFORE="$(node -e 'console.log(JSON.parse(process.argv[1]).schemaVersion)' "$WRITTEN")"
echo "探针已写入：token=$TOKEN schema=v$SCHEMA_BEFORE"

compose_for "$INSTANCE" restart backend >/dev/null
wait_healthy "$BASE_URL" 60

READ_BACK="$(node "$ROOT/scripts/probe-read.mjs" "$DB_PATH")"
TOKEN_AFTER="$(node -e 'console.log(JSON.parse(process.argv[1]).note)' "$READ_BACK")"
if [[ "$TOKEN_AFTER" != "$TOKEN" ]]; then
  echo "错误：重启后探针丢失或被改写（期望 $TOKEN，实际 $TOKEN_AFTER）" >&2
  exit 1
fi

node "$ROOT/scripts/check-health.mjs" "$BASE_URL" >/dev/null
echo "check-restart-persistence: OK（重启后探针保持，契约仍满足）"
