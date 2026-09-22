#!/usr/bin/env bash
# 构建检查：锁定依赖安装 → 全量构建 → 类型检查 → 单元测试。
# 任何一步失败即非零退出。CI=true / 关闭 corepack 下载确认，保证非 TTY 可运行。
set -euo pipefail
cd "$(dirname "$0")/.."
export CI=true COREPACK_ENABLE_DOWNLOAD_PROMPT=0

corepack pnpm install --frozen-lockfile
corepack pnpm -r run build
corepack pnpm -r run typecheck
corepack pnpm -r run test

echo "check-build: OK"
