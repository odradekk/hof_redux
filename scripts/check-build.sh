#!/usr/bin/env bash
# 构建检查：锁定依赖安装 → 全量构建 → 类型检查。任何一步失败即非零退出。
set -euo pipefail
cd "$(dirname "$0")/.."

corepack pnpm install --frozen-lockfile
corepack pnpm -r run build
corepack pnpm -r run typecheck

echo "check-build: OK"
