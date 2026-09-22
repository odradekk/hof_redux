# S1 工程基线：结构、实例与检查

状态：已实施。对应 [S1：建立可运行工程基线与隔离开发实例](https://github.com/odradekk/hof_redux/issues/22)。本文记录已核验的锁定版本、目录结构、实例隔离方式和自动检查；不表示任何玩法功能已交付。

## 已锁定并核验的版本

| 项 | 锁定值 | 说明 |
| --- | --- | --- |
| Node.js | 24.14.0（`.nvmrc`，`engines` 限定 `>=24.14.0 <25`） | 内置 `node:sqlite`（SQLite 3.51.2），仍为实验 API（运行契约 Q17 已接受的取舍） |
| 包管理器 | pnpm 10.32.1（`packageManager` 字段 + corepack，`pnpm-lock.yaml` 锁定全部依赖） | `.npmrc` 设 `save-exact=true`、`engine-strict=true` |
| Vue / Vite / plugin-vue | 3.5.43 / 7.3.6 / 6.0.9 | Vite 选 7.x 稳定线；plugin-vue 6.0.9 兼容 vite 5–8 |
| Fastify | 5.12.5 | 后端 HTTP 框架 |
| TypeScript | 5.9.3 | 未采用 7.x 原生预览线；vue-tsc 3.3.11 配套 |
| 后端打包 | esbuild 0.28.2（`--packages=external`） | 开发用 tsx 4.23.15 watch |
| 基础镜像 | `node:24.14.0-bookworm-slim@sha256:d8e448a56fc63242f70026718378bd4b00f8c82e78d20eefb199224a4d8e33d8` | 后端构建/运行、开发实例 |
| 入口镜像 | `caddy:2.10.2@sha256:c3d7ee5d2b11f9dc54f947f68a734c84e9c9666c92c88a7f30b9cba5da182adb` | 同域静态 + 反向代理 |

依赖完整锁定以 `pnpm-lock.yaml` 为准。`scripts/verify-baseline.sh` 写入 `var/evidence/` 的证据均来自被验收容器与构建产物：容器内实测的 node/SQLite/caddy 版本、实际运行镜像 ID、`pnpm-lock.yaml` 摘要；不以宿主运行时或硬编码常量充当。

## 目录结构

```
apps/frontend            Vue 3 + Vite 测试首页（S1 范围说明 + 健康/版本状态卡）
apps/backend             Fastify 后端：/api/health、/api/version
apps/backend/migrations  带版本 SQL 迁移（0001 起；schema_migrations 记录版本与摘要）
packages/shared          前后端共享接口契约类型（HealthResponse / VersionResponse）
content/release.json     内容发布清单占位（s1-baseline-empty；首批内容包见 #23）
docker/app               后端/入口 Dockerfile 与 Caddyfile
compose/                 四个隔离实例的 compose 配置
scripts/                 自动检查与整体验证
var/<实例>/              各实例持久数据（db、backup），不入库
```

## 后端启动行为（已验证）

- 打开全进程唯一业务连接并读回校验 `journal_mode=WAL`、`synchronous=FULL`、`foreign_keys=ON`、`busy_timeout=5000`，任一不符拒绝启动。
- 启动互斥：数据库旁的 `*.instance.lock` 由原子 `link` 竞争裁决——候选文件完整写好后以 `link` 安装到锁位，仅当锁位不存在时成功，多个并发启动者恰有一个成功；锁内容含唯一 owner token，释放时先把锁位改名到独有临时名再核对 token，只删除确属自己的锁。任何已存在的锁（含崩溃残留、旧版无 token 的锁）一律拒绝启动；应用内不提供接管（运行契约只要求第二实例不能误启动，任何“先移走旧锁再装新锁”的做法都有锁位空窗）。崩溃残留的人工恢复：确认旧实例确已停止（`scripts/instance.sh <实例> ps` 或 `sudo -n docker ps`）→ 删除 `<数据目录>/hof.sqlite.instance.lock` → 重新启动。行为由 `apps/backend/test/instanceLock.test.ts` 以真实并发子进程回归（恰一赢家、所有权释放、残留拒绝）。
- 启动即按序执行迁移（每条单独 `BEGIN IMMEDIATE` 事务，记录 SHA-256，漂移或乱序拒绝启动）；启动不清库、不补发资产、不重置身份。
- `GET /api/health` 以业务库可读为前提，只返回最小状态；`GET /api/version` 返回应用/内容/数据库三版本；未实现路径返回 404。

## 四个隔离实例

| 实例 | compose 文件 | 入口（仅回环） | 数据目录 | 运行方式 |
| --- | --- | --- | --- | --- |
| 开发 hof-dev | `compose/compose.dev.yml` | http://127.0.0.1:61000 | `var/dev/db` | 源码挂载，`tsx watch` + `vite` HMR，经 Caddy 同域 |
| 自动测试 hof-test | `compose/compose.test.yml` | http://127.0.0.1:62000 | `var/test/db` | 固定镜像 `hof-backend:local` / `hof-web:local` |
| 人工试用 hof-trial | `compose/compose.trial.yml` | http://127.0.0.1:63000 | `var/trial/db` | 同上固定镜像 |
| 恢复演练 hof-recovery | `compose/compose.recovery.yml` | http://127.0.0.1:64000 | `var/recovery/db` | 同上固定镜像；库只从备份恢复填入 |

隔离不只靠项目名：项目名/容器/卷、宿主持久路径（`var/<实例>/`）、对外端口各不相同；旧版参照栈 `hof-legacy`（:60000）与四者均不共享任何卷或端口。后端不发布端口，只经各实例内网由 Caddy 反代（`trustProxy` 仅对内网入口开启）。备份目标 `var/<实例>/backup` 已预留，一致备份能力在后续运行任务实现。远程试用所需 HTTPS/域名是环境准备项，落实前试用入口仅回环。

本机访问 docker 需要 `sudo -n`（用户不在 docker 组）；脚本自动检测（`scripts/lib-docker.sh`）。

本机外网代理由 TUN 透明代理（fake-ip）提供，docker 桥接网络不经过 TUN，因此：镜像构建在各 compose 文件的 `build.network: host` 下使用宿主网络；开发实例先由 `bootstrap`（`network_mode: host`）完成锁定安装并预热 corepack 缓存，backend/frontend 随后在桥接网络离线启动。运行中的容器不需要外网。

## 准备与启动入口（从空工作区可用）

统一使用 `scripts/instance.sh <实例> <compose 参数...>` 启动/停止实例；实例名单独决定 compose 文件与 `var/<实例>` 数据目录，不会混用路径：

```bash
scripts/instance.sh dev up -d            # 开发（热更新）
scripts/instance.sh test up -d --build   # 自动测试实例（构建镜像）
scripts/instance.sh trial down           # 停止人工试用实例
```

任何 compose 命令前都会先以当前用户准备 `var/<实例>/{db,backup}`（dev 另含 `node-home`），并把当前用户 `uid:gid` 写入 `var/instance.env`（`HOF_RUN_USER`），compose 经 `--env-file` 以它覆盖镜像内固定的 `USER node`。权限模型因此是“容器运行用户 = 准备目录属主”：不依赖宿主用户恰为 uid 1000，也避免 sudo docker 以 root 创建缺失的 bind 目录后容器无法写库（不经入口直接 `docker compose` 会因缺少 `HOF_RUN_USER` 拒绝插值，提示改用 `scripts/instance.sh`）。若目录已存在但当前用户不可写，脚本给出 `chown` 修复指引而不是静默失败。`check-instances.sh` 用合成 uid 静态校验运行用户接线，`check-migrate.sh` 以当前用户对全新临时目录实测启动。

发布端口的唯一事实表在 `scripts/lib-docker.sh`（`instance_port`），与 compose 文件的端口映射由 `scripts/check-instances.sh` 双向校验，防止两处漂移。

## 常用命令

```bash
# 启动实例（见上节 instance.sh）
scripts/instance.sh test up -d --build

# 单项检查
bash scripts/check-build.sh                            # 锁定安装 + 构建 + 类型检查 + 单元测试
bash scripts/check-instances.sh                        # 四实例 compose 配置与端口/目录防漂移
bash scripts/check-health.sh http://127.0.0.1:62000    # 健康/版本契约
bash scripts/check-migrate.sh                          # 一次性容器空库迁移验证（不触碰实例数据）
bash scripts/check-restart-persistence.sh test         # 重启后端→迁移登记/表清单/结构版本原样保持

# 整体验证（构建与测试→实例配置→镜像与空库迁移→启动→契约→重启持久性→legacy 未受影响）
# 证据（容器内实测运行时、实际镜像 ID、依赖锁文件摘要）写入 var/evidence/
bash scripts/verify-baseline.sh
```

## 边界（本基线不覆盖）

- 无账号、角色、战斗、战报、管理等玩法模块；内容清单为空占位（#23 起发布真实内容包）。
- 无一致备份/恢复实现（目录与实例已预留）；无异机备份、告警 Webhook、HTTPS/域名（环境准备项）。
- 未做任何负载测试，不支持任何承载结论。
