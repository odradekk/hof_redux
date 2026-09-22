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

依赖完整锁定以 `pnpm-lock.yaml` 为准；镜像构建产物见 `scripts/verify-baseline.sh` 写入的 `var/evidence/`。

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
- 启动互斥：数据库旁的 `*.instance.lock` 记录 pid/主机名；第二实例同库启动被拒绝（退出码 1）；正常停止释放锁；同主机名崩溃残留锁自动回收；异主机名锁需显式 `HOF_LOCK_TAKEOVER=1`。
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

## 常用命令

```bash
# 开发（热更新）
docker compose -f compose/compose.dev.yml up -d        # 或 sudo -n docker compose ...

# 自动测试实例
docker compose -f compose/compose.test.yml up -d --build

# 单项检查
bash scripts/check-build.sh                            # 锁定安装 + 构建 + 类型检查
bash scripts/check-health.sh http://127.0.0.1:62000    # 健康/版本契约
bash scripts/check-restart-persistence.sh test         # 写探针→重启后端→探针保持

# 整体验证（构建→镜像→启动→契约→重启持久性→legacy 未受影响），证据写入 var/evidence/
bash scripts/verify-baseline.sh
```

## 边界（本基线不覆盖）

- 无账号、角色、战斗、战报、管理等玩法模块；内容清单为空占位（#23 起发布真实内容包）。
- 无一致备份/恢复实现（目录与实例已预留）；无异机备份、告警 Webhook、HTTPS/域名（环境准备项）。
- 未做任何负载测试，不支持任何承载结论。
