#!/usr/bin/env bash
# 公共：定位仓库根目录与可用的 docker（本机用户不在 docker 组时回退 sudo -n）。
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

detect_docker() {
  if docker info >/dev/null 2>&1; then
    DOCKER=(docker)
  elif sudo -n docker info >/dev/null 2>&1; then
    DOCKER=(sudo -n docker)
  else
    echo "错误：无法访问 docker（直接访问与 sudo -n 均失败）" >&2
    exit 1
  fi
}

# 用法：compose_for <instance> <compose 子命令...>
compose_for() {
  local instance="$1"
  shift
  "${DOCKER[@]}" compose -f "$ROOT/compose/compose.$instance.yml" "$@"
}

# 等待实例健康（通过 web 同域入口），超时返回非零。
wait_healthy() {
  local base_url="$1"
  local tries="${2:-60}"
  for ((i = 1; i <= tries; i++)); do
    if curl -sf --max-time 5 "$base_url/api/health" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  echo "错误：$base_url 在 ${tries}s 内未变为健康" >&2
  return 1
}
