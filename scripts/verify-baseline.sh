#!/usr/bin/env bash
# S1 工程基线整体验证：
#   1. 锁定依赖安装 + 全量构建 + 类型检查
#   2. 构建固定镜像并启动 hof-test（空库 → 迁移 → 健康）
#   3. 健康/版本契约检查（经 Caddy 同域入口）
#   4. 重启持久性检查
#   5. 确认旧版参照栈（:60000）未受影响
# 结果写入 var/evidence/baseline-<时间戳>.json。
set -euo pipefail
source "$(dirname "$0")/lib-docker.sh"
cd "$ROOT"
detect_docker

BASE_URL="http://127.0.0.1:62000"
LEGACY_URL="http://127.0.0.1:60000"
EVIDENCE_DIR="$ROOT/var/evidence"
mkdir -p "$EVIDENCE_DIR" "$ROOT/var/test/db" "$ROOT/var/test/backup"

echo "== 1/5 构建检查 =="
bash scripts/check-build.sh

echo "== 2/5 构建镜像并启动 hof-test =="
compose_for test up -d --build
wait_healthy "$BASE_URL" 120

echo "== 3/5 健康/版本契约 =="
bash scripts/check-health.sh "$BASE_URL"

echo "== 4/5 重启持久性 =="
bash scripts/check-restart-persistence.sh test

echo "== 5/5 旧版参照栈状态 =="
curl -sf -o /dev/null --max-time 10 "$LEGACY_URL/" && echo "legacy: OK（$LEGACY_URL 仍可访问）"

TS="$(date -u +%Y%m%dT%H%M%SZ)"
EVIDENCE="$EVIDENCE_DIR/baseline-$TS.json"
BACKEND_IMAGE="$("${DOCKER[@]}" inspect --format='{{.Id}}' hof-backend:local)"
WEB_IMAGE="$("${DOCKER[@]}" inspect --format='{{.Id}}' hof-web:local)"
VERSION_INFO="$(curl -sf "$BASE_URL/api/version")"
node -e '
  const evidence = {
    checkedAt: new Date().toISOString(),
    node: process.version,
    sqlite: process.versions.sqlite,
    pnpm: process.argv[1],
    baseImages: {
      node: "node:24.14.0-bookworm-slim@sha256:d8e448a56fc63242f70026718378bd4b00f8c82e78d20eefb199224a4d8e33d8",
      caddy: "caddy:2.10.2@sha256:c3d7ee5d2b11f9dc54f947f68a734c84e9c9666c92c88a7f30b9cba5da182adb",
    },
    builtImages: { backend: process.argv[2], web: process.argv[3] },
    instance: { name: "hof-test", baseUrl: process.argv[4] },
    versionEndpoint: JSON.parse(process.argv[5]),
    checks: ["build", "migrate", "health-version-contract", "restart-persistence", "legacy-still-up"],
    result: "pass",
  };
  require("node:fs").writeFileSync(process.argv[6], JSON.stringify(evidence, null, 2) + "\n");
' "$(corepack pnpm --version)" "$BACKEND_IMAGE" "$WEB_IMAGE" "$BASE_URL" "$VERSION_INFO" "$EVIDENCE"

echo "verify-baseline: PASS，证据已写入 $EVIDENCE"
