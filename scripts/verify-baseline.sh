#!/usr/bin/env bash
# S1 工程基线整体验证：
#   1/6 锁定依赖安装 + 全量构建 + 类型检查 + 单元测试（check-build）
#   2/6 四实例 compose 配置与端口/数据目录防漂移校验（check-instances）
#   3/6 构建镜像 + 一次性容器空库迁移验证（隔离临时目录，不触碰实例数据）
#   4/6 启动 hof-test（保留既有数据）→ 健康/版本契约检查
#   5/6 重启持久性（迁移登记/表清单/结构版本前后一致）
#   6/6 旧版参照栈（:60000）仍可访问（失败即失败）
# 运行时与镜像证据取自被验收容器本身，不用宿主运行时或硬编码常量冒充；
# 结果写入 var/evidence/baseline-<时间戳>.json。
set -euo pipefail
source "$(dirname "$0")/lib-docker.sh"
cd "$ROOT"
detect_docker

TEST_BASE_URL="$(instance_base_url test)"
LEGACY_URL="http://127.0.0.1:60000"
EVIDENCE_DIR="$ROOT/var/evidence"
mkdir -p "$EVIDENCE_DIR"

echo "== 1/6 构建与单元测试 =="
bash scripts/check-build.sh

echo "== 2/6 实例配置校验 =="
bash scripts/check-instances.sh

echo "== 3/6 构建镜像并做空库迁移验证 =="
prepare_instance test
compose_for test build
bash scripts/check-migrate.sh

echo "== 4/6 启动 hof-test 并检查健康/版本契约 =="
compose_for test up -d
wait_healthy "$TEST_BASE_URL" 120
bash scripts/check-health.sh "$TEST_BASE_URL"

echo "== 5/6 重启持久性 =="
bash scripts/check-restart-persistence.sh test

echo "== 6/6 旧版参照栈状态 =="
# 单独命令而不是 && 列表：失败时 set -e 生效，验证结论为失败。
curl -sf -o /dev/null --max-time 10 "$LEGACY_URL/"
echo "legacy: OK（$LEGACY_URL 仍可访问）"

echo "== 证据收集（取自被验收容器/构建产物） =="
BACKEND_CID="$(compose_for test ps -q backend)"
WEB_CID="$(compose_for test ps -q web)"
BACKEND_IMAGE="$("${DOCKER[@]}" inspect --format '{{.Image}}' "$BACKEND_CID")"
WEB_IMAGE="$("${DOCKER[@]}" inspect --format '{{.Image}}' "$WEB_CID")"
BACKEND_RUN_USER="$("${DOCKER[@]}" inspect --format '{{.Config.User}}' "$BACKEND_CID")"
BACKEND_RUNTIME="$("${DOCKER[@]}" exec "$BACKEND_CID" node -e 'console.log(JSON.stringify({ node: process.version, sqlite: process.versions.sqlite }))')"
CADDY_VERSION="$("${DOCKER[@]}" exec "$WEB_CID" caddy version)"
VERSION_INFO="$(curl -sf "$TEST_BASE_URL/api/version")"

TS="$(date -u +%Y%m%dT%H%M%SZ)"
EVIDENCE="$EVIDENCE_DIR/baseline-$TS.json"
node -e '
  const crypto = require("node:crypto");
  const fs = require("node:fs");
  const evidence = {
    checkedAt: new Date().toISOString(),
    result: "pass",
    checks: ["build-and-unit-tests", "instances-config", "empty-db-migrate", "health-version-contract", "restart-persistence", "legacy-still-up"],
    // 实际运行的镜像与容器内运行时（非宿主、非硬编码）。
    images: { backend: process.argv[2], web: process.argv[3] },
    runtime: {
      backend: JSON.parse(process.argv[4]),
      caddy: process.argv[5],
      backendRunUser: process.argv[9],
      note: "backend 的 node/sqlite 与 caddy 版本、运行用户均在 hof-test 容器内实测",
    },
    buildTool: {
      pnpm: process.argv[6],
      note: "宿主 corepack 执行（packageManager 锁定），用于本机构建与镜像内构建",
      lockfileSha256: crypto.createHash("sha256").update(fs.readFileSync("pnpm-lock.yaml")).digest("hex"),
    },
    instance: { name: "hof-test", baseUrl: process.argv[7] },
    versionEndpoint: JSON.parse(process.argv[8]),
  };
  fs.writeFileSync(process.argv[10], JSON.stringify(evidence, null, 2) + "\n");
' "$EVIDENCE" "$BACKEND_IMAGE" "$WEB_IMAGE" "$BACKEND_RUNTIME" "$CADDY_VERSION" "$(corepack pnpm --version)" "$TEST_BASE_URL" "$VERSION_INFO" "$BACKEND_RUN_USER" "$EVIDENCE"

echo "verify-baseline: PASS，证据已写入 $EVIDENCE"
