#!/usr/bin/env bash
# 四实例 compose 配置校验（不假定宿主 uid=1000）：
#   1) 每个文件 docker compose config 可解析（用合成运行用户 54321:54321）；
#   2) node 服务的运行用户正确接线为 HOF_RUN_USER（解析后即为 54321:54321），
#      与 prepare_instance 准备目录的属主保持一致的权限模型；
#   3) 发布端口与 lib-docker.sh 的端口唯一事实表一致（防两处漂移）；
#   4) 每个实例只引用自己的 var/<instance> 数据目录（隔离一眼可审计）。
set -euo pipefail
source "$(dirname "$0")/lib-docker.sh"
detect_docker

SYNTHETIC_ENV="$(mktemp)"
trap 'rm -f "$SYNTHETIC_ENV"' EXIT
printf 'HOF_RUN_USER=54321:54321\n' > "$SYNTHETIC_ENV"

for instance in dev test trial recovery; do
  port="$(instance_port "$instance")"
  file="$ROOT/compose/compose.$instance.yml"

  "${DOCKER[@]}" compose --env-file "$SYNTHETIC_ENV" -f "$file" config -q

  # 运行用户接线：合成 uid 必须原样落到每个会写宿主数据的服务上。
  resolved_users="$("${DOCKER[@]}" compose --env-file "$SYNTHETIC_ENV" -f "$file" config | grep -c 'user: 54321:54321' || true)"
  expected_users=1
  [[ "$instance" == "dev" ]] && expected_users=3
  if [[ "$resolved_users" != "$expected_users" ]]; then
    echo "错误：$file 应有 $expected_users 个服务以 HOF_RUN_USER 运行，实际解析到 $resolved_users 个" >&2
    exit 1
  fi

  if ! grep -q "127.0.0.1:${port}:80" "$file"; then
    echo "错误：$file 未发布 127.0.0.1:${port}:80（与端口表不一致）" >&2
    exit 1
  fi

  if ! grep -q "\.\./var/$instance/db" "$file"; then
    echo "错误：$file 未挂载自己的数据目录 ../var/$instance/db" >&2
    exit 1
  fi
  for other in dev test trial recovery; do
    if [[ "$other" != "$instance" ]] && grep -q "\.\./var/$other/" "$file"; then
      echo "错误：$file 引用了其他实例的数据目录 ../var/$other/" >&2
      exit 1
    fi
  done
done

echo "check-instances: OK（四实例配置可解析；运行用户=准备目录属主；端口与数据目录无漂移、无混用）"
