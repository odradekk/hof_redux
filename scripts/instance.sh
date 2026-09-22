#!/usr/bin/env bash
# 统一实例入口：scripts/instance.sh <instance> <compose 参数...>
#   scripts/instance.sh dev up -d            # 开发实例（热更新）
#   scripts/instance.sh test up -d --build   # 构建并启动自动测试实例
#   scripts/instance.sh trial down           # 停止人工试用实例
# 实例名单独决定 compose 文件与 var/<instance> 数据目录，路径不会混用；
# 任何 compose 命令前都先以当前用户准备持久目录并写入 var/instance.env
# （HOF_RUN_USER=uid:gid）：容器运行用户与目录属主一致，不依赖宿主 uid=1000，
# 也避免 sudo docker 以 root 创建 bind 目录导致后端无法建库。
set -euo pipefail
source "$(dirname "$0")/lib-docker.sh"
detect_docker

if [[ $# -lt 2 ]]; then
  echo "用法：scripts/instance.sh <dev|test|trial|recovery> <compose 参数...>" >&2
  exit 2
fi

INSTANCE="$1"
shift
prepare_instance "$INSTANCE"
exec "${DOCKER[@]}" compose --env-file "$ROOT/var/instance.env" -f "$ROOT/compose/compose.$INSTANCE.yml" "$@"
