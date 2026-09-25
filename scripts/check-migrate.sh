#!/usr/bin/env bash
# 空库迁移检查（确定性，不触碰任何实例数据）：
# 用一次性容器 + 全新临时目录启动后端镜像 hof-backend:local（须已构建），
# 以当前用户（与临时目录属主一致，不依赖宿主 uid=1000）运行，
# 验证 空库 → 按序迁移 → 健康，并在被验证容器内核对：
#   1) 迁移登记与仓库迁移文件逐条一致（version/name/sha256，无多余记录）；
#   2) 业务表清单为账号基线表集合（#24 起含账号/资产/会话等表，无测试设施表）。
set -euo pipefail
source "$(dirname "$0")/lib-docker.sh"
detect_docker
cd "$ROOT"

NAME="hof-verify-migrate"
"${DOCKER[@]}" rm -f "$NAME" >/dev/null 2>&1 || true

TMP="$(mktemp -d "${TMPDIR:-/tmp}/hof-migrate-check.XXXXXX")"
mkdir -p "$TMP/db"
trap '"${DOCKER[@]}" rm -f "$NAME" >/dev/null 2>&1 || true; rm -rf "$TMP"' EXIT

"${DOCKER[@]}" run -d --name "$NAME" \
  -p 127.0.0.1::3000 \
  --user "$(id -u):$(id -g)" \
  -e HOF_DB_PATH=/var/lib/hof/db/hof.sqlite \
  -v "$TMP/db":/var/lib/hof/db \
  -v "$ROOT/scripts":/scripts:ro \
  hof-backend:local >/dev/null

PORT="$("${DOCKER[@]}" port "$NAME" 3000/tcp | head -1 | sed 's/.*://')"
BASE_URL="http://127.0.0.1:$PORT"
wait_healthy "$BASE_URL" 60
node "$ROOT/scripts/check-health.mjs" "$BASE_URL" >/dev/null

# 事实来自被验证容器内的运行时（挂载只读 scripts，复用同一读取器）。
DB_STATE="$("${DOCKER[@]}" exec "$NAME" node /scripts/db-state.mjs /var/lib/hof/db/hof.sqlite)"

node -e '
  const crypto = require("node:crypto");
  const fs = require("node:fs");
  const path = require("node:path");
  const state = JSON.parse(process.argv[1]);
  const migrationsDir = process.argv[2];

  const expected = fs
    .readdirSync(migrationsDir)
    .filter((f) => /^\d{4}_[a-z0-9_]+\.sql$/.test(f))
    .sort()
    .map((f) => ({
      version: Number(f.slice(0, 4)),
      name: f,
      sha256: crypto.createHash("sha256").update(fs.readFileSync(path.join(migrationsDir, f), "utf8"), "utf8").digest("hex"),
    }));
  const actual = state.migrations.map(({ version, name, sha256 }) => ({ version, name, sha256 }));
  if (JSON.stringify(actual) !== JSON.stringify(expected)) {
    console.error(`迁移登记与仓库文件不一致：\n  容器内：${JSON.stringify(actual)}\n  期望：  ${JSON.stringify(expected)}`);
    process.exit(1);
  }
  if (JSON.stringify(state.tables) !== JSON.stringify(["account_assets","accounts","app_meta","game_change_records","register_requests","schema_migrations","sessions"])) {
    console.error(`空库迁移后业务表清单异常：${JSON.stringify(state.tables)}（应为 #24 账号基线表集合）`);
    process.exit(1);
  }
' "$DB_STATE" "$ROOT/apps/backend/migrations"

echo "check-migrate: OK（空库按序迁移，登记与文件一致，账号基线表集合完整）"
