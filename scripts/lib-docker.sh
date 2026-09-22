#!/usr/bin/env bash
# 公共：定位仓库根目录、可用的 docker（本机用户不在 docker 组时回退 sudo -n）、
# 四实例唯一端口表，以及以当前用户准备实例持久目录的入口。
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
# 统一经 var/instance.env 提供容器运行用户（sudo docker 不继承 shell 环境变量，
# 因此用 --env-file 而不是 export）。文件由 prepare_instance 生成。
compose_for() {
  local instance="$1"
  shift
  local env_file="$ROOT/var/instance.env"
  if [[ ! -f "$env_file" ]]; then
    echo "错误：缺少 $env_file。请先经 scripts/instance.sh <实例> <compose 参数> 启动（它会准备目录并生成运行用户配置）。" >&2
    return 1
  fi
  "${DOCKER[@]}" compose --env-file "$env_file" -f "$ROOT/compose/compose.$instance.yml" "$@"
}

# 四实例端口唯一事实表；compose 文件的发布端口必须与此一致
# （scripts/check-instances.sh 校验，防止两处漂移）。
instance_port() {
  case "$1" in
    dev) echo 61000 ;;
    test) echo 62000 ;;
    trial) echo 63000 ;;
    recovery) echo 64000 ;;
    *) echo "错误：未知实例：$1（应为 dev|test|trial|recovery）" >&2; return 2 ;;
  esac
}

instance_base_url() {
  echo "http://127.0.0.1:$(instance_port "$1")"
}

# 权限模型：容器运行用户 = 准备目录的属主（当前用户），不依赖宿主 uid 恰为 1000。
# - 以当前用户创建 var/<instance>/{db,backup}（dev 另含 node-home），否则 sudo docker
#   会以 root 创建缺失的 bind 目录，容器内运行用户无法写入数据库；
# - 把当前用户 uid:gid 写入 var/instance.env（HOF_RUN_USER），compose 用它覆盖镜像的
#   固定 USER node。sudo docker 不继承 shell 环境，所以经 --env-file 传递。
prepare_instance() {
  local instance="$1" dir
  instance_port "$instance" >/dev/null || return 2
  local dirs=("$ROOT/var/$instance/db" "$ROOT/var/$instance/backup")
  [[ "$instance" == "dev" ]] && dirs+=("$ROOT/var/$instance/node-home")
  for dir in "${dirs[@]}"; do
    if [[ -e "$dir" && ! -w "$dir" ]]; then
      echo "错误：$dir 已存在但当前用户不可写（可能曾由 root 或其他用户创建）。" >&2
      echo "修复：sudo chown -R $(id -u):$(id -g) $ROOT/var/$instance 后重试。" >&2
      return 1
    fi
    mkdir -p "$dir"
  done
  mkdir -p "$ROOT/var"
  printf 'HOF_RUN_USER=%s:%s\n' "$(id -u)" "$(id -g)" > "$ROOT/var/instance.env"
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
