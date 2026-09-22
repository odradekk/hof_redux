#!/usr/bin/env bash
# 健康/版本契约检查：check-health.sh <base-url>
set -euo pipefail
cd "$(dirname "$0")/.."
node scripts/check-health.mjs "$1"
echo "check-health: OK"
