# Node.js、SQLite 驱动与单机备份的官方运行约束

调查日期：2026-09-22。本文只使用 Node.js、SQLite、Docker 和库维护者的一手资料；网页显示的未来计划不作为已经发布的能力。项目已确认生产环境为 Linux + Docker Compose，SQLite WAL 位于单机本地持久磁盘；整机损失的数据进度目标为 15 分钟，从开始人工处理起的恢复目标为 2 小时，不承诺 24 小时值守。

## 结论

首期最简单的驱动候选是：锁定最新的 **Node.js 24 LTS 补丁版**，使用内置 `node:sqlite`；按已确认决定使用 WAL + `synchronous=FULL`，显式开启外键和有限 busy timeout，所有写业务使用短 `BEGIN IMMEDIATE` 事务。是否把同步数据库调用移到长期 worker、是否只开一个连接，仍需由目标负载与故障演练裁决，当前不是已确认架构。Node 24.21.0 内置 SQLite 3.53.4，已包含 2026 年 WAL-reset 数据损坏缺陷的修复。这个选择少一个原生依赖，且内置驱动已经提供同步连接、BigInt 读取和在线 Backup API；代价是截至本次调查 `node:sqlite` 仍标为 Stability 1.2（release candidate），必须用项目行为测试锁定其接口和错误语义。

`better-sqlite3` 是可接受的退路，而不是首选。当前 v13.0.3 同样捆绑 SQLite 3.53.4，具有成熟的同步 API、BigInt 和在线备份支持，但增加原生扩展供应链与镜像构建面。其 v13.0.2 刚修复了“活跃调用期间终止 worker 可能令整个进程 abort”的问题，说明无论选哪个驱动，都不应把强制终止数据库 worker 当作常规取消机制。

以下内容分为官方事实、项目建议和实施时必须验证的事项。版本结论只代表 2026-09-22 检索到的发布状态；镜像升级必须重新核对实际运行版本。

## 运行时和驱动

### 官方事实

- Node.js 官方要求生产应用只使用 Active LTS 或 Maintenance LTS。官方计划表显示 v24 于 2025-10-28 进入 LTS，计划在 2026-10-20 转入 Maintenance，计划支持至 2028-04-30；v22 已处于 Maintenance，计划支持至 2027-04-30；v26 在 2026-09-22 仍是 Current，计划 2026-10-28 才进入 LTS。[Node.js 发布说明](https://nodejs.org/en/about/previous-releases)、[官方发布计划](https://raw.githubusercontent.com/nodejs/Release/main/schedule.json)
- Node 官方下载索引列出 v24.21.0，日期为 2026-09-07、`lts` 为 `Krypton`，对应 `SHASUMS256.txt` 可下载；GitHub 正式发布标签记录为 2026-09-08 21:51:09 UTC，且不是 draft 或 prerelease。其 `node:sqlite` 自 v24.15.0 起从 experimental 升为 Stability 1.2（release candidate），尚未达到 Stable；`DatabaseSync` 的所有数据库 API 都同步执行。构造器从 v24.0.0 起支持 `timeout`，默认 0 ms；从 v24.14.0 起默认开启 defensive 模式。[Node 下载索引](https://nodejs.org/dist/index.json)、[v24.21.0 校验和](https://nodejs.org/dist/v24.21.0/SHASUMS256.txt)、[v24.21.0 发布标签](https://github.com/nodejs/node/releases/tag/v24.21.0)、[Node 24.21.0 SQLite 文档](https://nodejs.org/download/release/v24.21.0/docs/api/sqlite.html)
- Node v24.21.0 标签源码中的 `SQLITE_VERSION` 为 3.53.4，`SQLITE_SOURCE_ID` 为 `2026-07-24 19:02:57 bf7c7f…`。Node 官方把 SQLite 源码编入 Node 二进制，因此不能假定容器系统包的 `sqlite3` 版本就是应用实际使用版本。[Node 24.21.0 sqlite3.h](https://raw.githubusercontent.com/nodejs/node/v24.21.0/deps/sqlite/sqlite3.h)、[Node 依赖维护说明](https://github.com/nodejs/node/blob/main/doc/contributing/maintaining/maintaining-dependencies.md)
- `node:sqlite` 默认把 SQLite `INTEGER` 读为 JavaScript `number`；超出安全整数范围时会抛 `ERR_OUT_OF_RANGE`，而不是静默损失精度。连接可用 `readBigInts: true` 统一读为 `bigint`；写入超出有符号 64 位范围的 `bigint` 会报错。[Node 24.21.0 SQLite 文档](https://nodejs.org/download/release/v24.21.0/docs/api/sqlite.html)
- better-sqlite3 的正式 v13.0.3 标签发布于 2026-08-05 03:26:05 UTC，不是 prerelease；标签内 `package.json` 声明版本 13.0.3、Node >=22，捆绑头文件声明 SQLite 3.53.4。维护者文档称其支持同步 API、事务、worker threads 和 64 位整数；v13.0.2 更新到 SQLite 3.53.4 并修复 worker termination 导致进程 abort 的问题。其默认 busy timeout 为 5000 ms，BigInt 读取需要 `defaultSafeIntegers(true)` 或逐语句开启。[v13.0.3 发布标签](https://github.com/WiseLibs/better-sqlite3/releases/tag/v13.0.3)、[v13.0.3 package.json](https://raw.githubusercontent.com/WiseLibs/better-sqlite3/v13.0.3/package.json)、[v13.0.3 sqlite3.h](https://raw.githubusercontent.com/WiseLibs/better-sqlite3/v13.0.3/deps/sqlite3/sqlite3.h)、[API](https://github.com/WiseLibs/better-sqlite3/blob/master/docs/api.md)、[整数说明](https://github.com/WiseLibs/better-sqlite3/blob/master/docs/integer.md)

### 项目建议

1. 生产基线固定 Node 24 的完整补丁版本和镜像 digest，不用 `node:latest`、`node:lts` 或可漂移的范围。v26 在调查日还不是 LTS，不能把计划日期当作已发生。
2. 首期使用 `node:sqlite`，但把驱动包在很薄的存储适配层内，仅封装连接初始化、事务、语句和备份。release-candidate 状态意味着每次 Node 补丁升级都要跑迁移、事务、BigInt、busy、备份恢复契约测试，并查 Node 与 SQLite 发布记录。若实测出现接口缺口或不可接受回归，再切到锁定版本的 better-sqlite3；不同时支持两套驱动。
3. 对身份、版本、金钱、流水号、毫秒时间等可能增长的 SQLite `INTEGER`，连接统一 `readBigInts: true`，在存储边界显式转换。JSON/HTTP 不能直接序列化 BigInt，API DTO 应按字段契约输出十进制字符串或经已验证范围检查后转 `number`，不能在任意调用点隐式转换。
4. 把数据库同步调用放在 Fastify 主事件循环，或移到一个长期数据库 worker（或单独数据库进程），是两个待压测候选。先测真实请求的 SQL 尾延迟和事件循环延迟；只有前者不能满足响应目标时，才承担 worker/RPC、崩溃恢复和可观测性的复杂度。单连接最简单，多连接可改善并行读但会增加 busy、快照、checkpoint 和事务归属组合，连接数也应实测后决定。已修复的 WAL-reset 历史缺陷不作为强制单连接的理由；战斗演算仍应与数据库写事务分离。

### 实施验证

- 启动时记录并校验 `process.version`、`process.versions.sqlite`，执行 `SELECT sqlite_version(), sqlite_source_id()` 和 `PRAGMA compile_options`；拒绝未包含 WAL-reset 修复的 SQLite（见下节）。
- 运行一组真实 DTO 往返测试：`2^53-1`、`2^53`、`2^63-1`、负值、`lastInsertRowid`，确认没有 JSON 序列化异常或隐式精度损失。
- 基于目标 Linux 镜像和 CPU 架构构建、启动并恢复备份。若改用 better-sqlite3，验证预编译二进制来源、无预编译件时的失败方式，以及镜像内实际捆绑的 SQLite 版本。

## WAL、事务和连接设置

### 官方事实

- SQLite WAL 允许 reader 与 writer 并行，但同一时刻仍只有一个 writer；WAL 依赖同机共享内存，不适用于网络文件系统。默认 WAL 达到 1000 页后，由触发阈值的提交线程执行 PASSIVE 自动 checkpoint；长读事务会阻止 checkpoint 完成并使 WAL 增长。[SQLite WAL](https://www.sqlite.org/wal.html)、[事务](https://www.sqlite.org/lang_transaction.html)
- SQLite 于 2026-03-03 发现 WAL-reset 数据损坏缺陷：在同一 WAL 文件存在至少两个跨线程或进程的连接，并发写入与 checkpoint 恰好竞争时，极少数情况下可能损坏数据库。官方称 3.7.0 至 3.51.2 可能受影响；3.51.3、3.50.7、3.44.6 及之后版本已修复，并明确建议升级。3.52.0 后来因另一兼容问题撤回，不能仅以“版本更高”判断可用。[SQLite WAL §11](https://www.sqlite.org/wal.html#the_wal_reset_bug)、[SQLite 发布记录](https://www.sqlite.org/changes.html)、[SQLite 新闻](https://www.sqlite.org/news.html)
- `BEGIN IMMEDIATE` 会立即开始写事务；如果已有 writer，会在开始处得到 `SQLITE_BUSY`，避免先读旧快照后再升级写事务。连接的 busy timeout 只是在锁释放前等待，不能代替业务冲突检测或幂等处理。[SQLite 事务](https://www.sqlite.org/lang_transaction.html)、[PRAGMA busy_timeout](https://www.sqlite.org/pragma.html#pragma_busy_timeout)
- `PRAGMA foreign_keys` 是逐连接设置，且在事务内修改是 no-op。SQLite 默认值可能由编译选项改变，因此应用不能依赖默认值。[SQLite PRAGMA foreign_keys](https://www.sqlite.org/pragma.html#pragma_foreign_keys)
- WAL 下 `synchronous=FULL` 每次提交会额外同步 WAL，官方矩阵将其列为 ACID；`NORMAL` 在应用进程崩溃时仍安全，但断电或操作系统崩溃后已提交事务可能回滚。SQLite 称 NORMAL 对多数 WAL 应用是性能与安全的平衡，但这不是项目必须接受的数据丢失政策。[SQLite PRAGMA synchronous](https://www.sqlite.org/pragma.html#pragma_synchronous)、[SQLite WAL 性能说明](https://www.sqlite.org/wal.html#performance_considerations)
- WAL 文件是数据库持久状态的一部分；数据库文件与 `-wal` 分离可能丢失已提交事务或导致损坏。因此运行时直接复制主 `.db` 文件不是一致性备份。[SQLite WAL 文件](https://www.sqlite.org/wal.html#the_wal_file)

### 项目建议

连接建立后、准备任何语句前执行并读回校验：

```sql
PRAGMA journal_mode = WAL;
PRAGMA synchronous = FULL;
PRAGMA foreign_keys = ON;
PRAGMA busy_timeout = 5000;
```

`FULL` 已由用户确认为首期设置，因为游戏资产提交已向用户宣告成功后不应仅因主机掉电回滚。15 分钟整机备份 RPO 不能当作允许日常掉电丢失提交。FULL 的耐久性仍依赖 Linux 文件系统、虚拟化/云盘和硬件正确实现并报告持久化屏障；SQLite 无法补救忽略或虚报 sync 的存储栈，目标宿主必须记录存储配置并做崩溃/掉电演练。[SQLite 如何损坏：不遵守 sync 的磁盘](https://www.sqlite.org/howtocorrupt.html#_disk_drives_that_do_not_honor_sync_requests)

每个业务写操作都在数据库执行上下文中以 `BEGIN IMMEDIATE` 开始，取得 writer 后重新读取并校验依赖状态，随后一次提交。busy timeout 设为有限值，超时后只对已证明可安全重放的整个事务做少量重试；不从失败事务中间继续。密码哈希、战斗演算、网络、对象存储上传和等待不进入事务。

首期保留默认 1000 页 PASSIVE 自动 checkpoint，并监控 WAL 大小、checkpoint 返回值和提交尾延迟。只有负载证据显示偶发 checkpoint 延迟不可接受时，才改为由同一数据库执行上下文调度 checkpoint；不要先增加第二个 checkpoint 连接。停止接收请求并排空操作后可尝试 `PRAGMA wal_checkpoint(TRUNCATE)`，但它是清理和缩小 WAL，不是备份成功条件。

### 实施验证

- 并发测试 `BEGIN IMMEDIATE`、busy 超时、全事务重试、进程崩溃和容器 `SIGKILL`，确认不重复扣款/发奖，未提交写入回滚。
- 每次连接创建都断言 `journal_mode=wal`、`synchronous=2`、`foreign_keys=1` 和预期 timeout；迁移或备份辅助连接也不能漏掉连接级设置。
- 制造长读事务，观察 checkpoint 无法完成和 WAL 增长；告警必须在磁盘耗尽前触发，并能定位长期占用者。

## 在线备份、RPO 和恢复

### 官方事实

- SQLite Online Backup API 可分批复制在线数据库，只在读取源页面的短时间持有源锁，完成后得到一致快照。其他连接在备份期间写源库会让备份重启；频繁写入可能令它一直无法完成。[SQLite Online Backup API](https://www.sqlite.org/backup.html)
- Node 24 的 `sqlite.backup(sourceDb, destination, { rate, progress })` 包装该 API并返回 Promise；同一个 `DatabaseSync` 对象的变更会立即反映到备份，其他连接的变更会使备份重启。目标路径已存在时会覆盖，因此不能直接把“正式可恢复文件”当工作路径。[Node 24.21.0 `sqlite.backup`](https://nodejs.org/download/release/v24.21.0/docs/api/sqlite.html#sqlitebackupsource-db-path-options)
- `VACUUM INTO` 也生成一致快照并压缩空闲页，但不能增量执行，CPU 开销更高；目标必须不存在或为空。意外中止时输出可能不完整或损坏。普通 `VACUUM` 可能改变没有显式 `INTEGER PRIMARY KEY` 的 ROWID，并需最多约两倍数据库空间。[SQLite VACUUM](https://www.sqlite.org/lang_vacuum.html)
- `PRAGMA integrity_check` 不检查外键错误，外键另用 `PRAGMA foreign_key_check`；`quick_check` 更快但检查范围较小。[SQLite PRAGMA](https://www.sqlite.org/pragma.html#pragma_integrity_check)

### 项目建议

每 15 分钟上限内启动一次备份并不足以证明 15 分钟 RPO。RPO 以**最新已上传、已验证、能实际取回的对象内嵌数据标记**与当前时间之差计算，不能用任务开始、任务结束或上传完成时间冒充数据时刻。该时间是应用在事务内写入的墙钟值，不是 SQLite 提供的可信提交时间；只有主机持续校时、监控时钟偏移与回拨时，才能用它计算分钟年龄。另存数据库内单调递增的恢复点序号/代次，用来识别倒退和排序，但序号本身不能换算为分钟。

推荐流程：

1. 通过当前写连接在短事务中写入一个备份标记（随机备份 ID、单调递增序号/恢复代次、由已校时主机取得的应用墙钟、内容/迁移版本）并提交，提交后立即启动备份。
2. 从同一打开连接调用 Online Backup API，写到新的临时文件；不要直接覆盖上一份成功备份。整个备份设置总截止时间，对 `BUSY`/`LOCKED` 只做有界等待与重试；Promise 仍未结束或进度回调仍在变化都不算成功，超时后本轮失败且不发布 manifest。
3. 用独立只读连接打开产物，运行 `quick_check`（周期性或每日运行完整 `integrity_check`）与 `foreign_key_check`，并确认备份标记存在。标记中的应用墙钟是这个产物数据新鲜度的保守下界；备份过程中同连接后续写入可能也被纳入，但不据此声称更晚的 RPO。
4. 计算文件摘要，在服务器上先加密产物，再上传到独立故障域；上传后读取对象元数据/摘要或实际下载抽样验证。只有完成这些步骤才发布不可变 manifest，并把该标记计为“可恢复点”。失败时保留上一份成功备份并告警。
5. 恢复时先下载并验证摘要，作为新文件打开，执行 `integrity_check`、`foreign_key_check`、迁移/内容版本检查，再原子切换并启动应用。恢复进入新的恢复代次：全部旧会话和旧请求身份失效，隔离可能迟到的旧执行者，管理员在服务器本机重新授权后才能执行管理操作。玩家密码、恢复码、停用和删号状态按备份回退；不建独立安全日志。旧会话失效不表示备份时有效的旧密码不能重新登录，因此恢复公告须公开恢复点并提示玩家改密。不得把备份文件直接覆盖正在打开的生产数据库。

整机损失要求意味着备份只放在同一主机、同一磁盘或同一个 Compose volume 不能满足目标。远端目的地、加密密钥服务器外保管方式和保留期已经确认；具体对象存储商不属于本研究。

`VACUUM INTO` 不作为首期定时备份路径。它可用于离线压缩或特殊导出，但 Online Backup API 更适合在线、分批复制和限流；每轮仍生成完整数据库副本，分批不等于只备份自上次以来的变化。备份调度必须防止重叠；若一个周期尚未完成，不启动第二份并把延迟计入新鲜度告警。

高频把每份完整 SQLite 快照作为独立对象上传，会重复传输和保存大量相同页面，数据库增长后可能提高带宽、请求和存储成本。一个待验证候选是：先用 Online Backup 生成并校验一致快照，再让 restic 把该文件写入 S3 兼容仓库。restic 会把内容分块、跨快照去重并在上传前加密认证；退出码 0 表示完整备份成功；退出码 3 仍会创建缺少未读文件的不完整快照，不能仅凭存在快照就更新项目的可恢复点。仓库可用 `check` 做结构检查，验证存储数据还须启用相应数据读取检查。[restic 备份与去重](https://restic.readthedocs.io/en/stable/040_backup.html)、[restic 仓库检查](https://restic.readthedocs.io/en/stable/045_working_with_repos.html#checking-integrity-and-consistency)、[restic 加密格式](https://restic.readthedocs.io/en/stable/100_references.html)、[S3 兼容仓库](https://restic.readthedocs.io/en/stable/030_preparing_a_new_repo.html#s3-compatible-storage)

这只是传输/保留层候选，不取代 SQLite 一致性备份、内嵌恢复点标记或恢复演练。去重仍需扫描文件，SQLite 页面变化对实际上传量的影响未知，仓库还需要密码外置、锁、保留/清理、完整性检查和恢复工具版本管理。实施时用真实增长率测量每轮扫描 CPU/I/O、上传字节、对象请求、仓库占用、`check` 和完整恢复时间，再比较“独立完整对象”与“restic 仓库”；本研究不选定工具或调度频率。

### 实施验证

- 在持续写入、`BUSY`/`LOCKED`、备份重启、总截止时间、磁盘满、进程中止、上传中断和损坏对象下演练；确认不会把部分文件、超时任务或未验证对象发布为成功备份。
- 每日自动下载、解密并校验一份真实异机备份。上线前、此后每月及备份恢复流程修改后，在与生产隔离的环境做完整恢复演练，测量从人工开始到服务可用是否不超过 2 小时；记录下载、校验、迁移、启动和业务抽查各阶段时间。
- 监控已校时墙钟与“最新可恢复点标记年龄”，在接近 15 分钟前预警；超过 15 分钟时按已确认政策自动暂停全部业务写入，保留只读查询与恢复管理入口。调度间隔必须小于 15 分钟，为备份、上传、验证、重试和自动暂停留余量；最终调度频率需结合实测持续时间确定。

## worker、取消和停机边界

### 官方事实

- `node:sqlite` 的连接和语句调用同步执行，会阻塞所在 JavaScript 线程。Node worker threads 适合 CPU 密集型 JavaScript；官方建议需要 worker 时复用池而非每个任务新建，并说明 `worker.terminate()` 只保证“尽快”停止 JavaScript，返回 Promise 等待 exit。[Node SQLite](https://nodejs.org/download/release/v24.21.0/docs/api/sqlite.html)、[Node worker_threads](https://nodejs.org/download/release/v24.21.0/docs/api/worker_threads.html)
- SQLite 连接关闭时若仍有事务，底层 `sqlite3_close_v2()` 最终会回滚；但 Node `node:sqlite` 没有公开 `sqlite3_interrupt()` 包装。不能把请求的 AbortSignal 等同于底层语句已中止。[Node `database.close`](https://nodejs.org/download/release/v24.21.0/docs/api/sqlite.html#databaseclose)、[SQLite close](https://www.sqlite.org/c3ref/close.html)

### 项目建议

- 若压测后选择数据库 worker，执行上下文应使用有界队列和逐任务截止时间。任务尚未开始时可取消并从队列移除；同步 SQL 已开始后不宣称可即时取消，等待该语句/事务结束，再丢弃响应或按业务幂等结果查询。所有查询必须有索引和可测的上限，管理导出分页，禁止任意在线 SQL。
- 若选择 worker/RPC，单个事务必须固定在同一连接和 worker，RPC 接口传业务命令，不传“BEGIN/若干 SQL/COMMIT”碎片。worker 崩溃后，调用方把结果视为未知并按操作身份查询，不能假定失败后重放新效果。若实测证明短 SQL 可留在主线程，则仍要执行相同的事务、超时和查询上限约束。
- 正常停机顺序：收到 SIGTERM 后停止接收新请求；等待 HTTP、数据库队列和已受理操作到安全边界；完成/中止备份临时产物；关闭连接；退出。不要在活跃数据库原生调用中调用 `worker.terminate()`。超过宽限期由容器强制结束时依靠 SQLite 事务恢复，但重启后仍按持久操作身份恢复业务。

## Docker Compose 和秘密

### 官方事实

- Compose 默认以 SIGTERM 停止容器；`stop_grace_period` 是发 SIGKILL 前的等待时间，默认只有 10 秒，可显式设置。`stop_signal` 可覆盖信号。[Compose services](https://docs.docker.com/reference/compose-file/services/#stop_grace_period)
- Compose volume 是容器引擎管理的持久数据；bind mount 可绑定明确的宿主机绝对路径。SQLite WAL 要求同机文件系统，因此数据库目录不能放 NFS 等网络文件系统。[Compose volumes](https://docs.docker.com/reference/compose-file/volumes/)、[SQLite WAL](https://www.sqlite.org/wal.html)
- Compose secrets 按服务授权并以 `/run/secrets/<name>` 文件挂载，避免把秘密放进镜像或普通环境变量；官方提醒环境变量更容易被进程和日志暴露。[Docker Compose secrets](https://docs.docker.com/compose/how-tos/use-secrets/)

### 项目建议

- 使用单独的本地持久 volume 或明确宿主机目录挂载整个数据库目录，而不只挂载 `.db` 文件；限制只有应用服务写入。备份临时目录和生产数据库目录分开，并监控两者空间。
- 镜像使用 exec-form ENTRYPOINT/CMD 让 Node 收到 SIGTERM；Compose 明确 `stop_signal: SIGTERM`。`stop_grace_period` 先设为 2 分钟作为验证起点，再按最长受控数据库任务和停机演练调整；默认 10 秒不足以表达项目恢复语义。
- 会话/恢复令牌的服务端摘要不需要额外秘密；Cookie 签名密钥、备份加密密钥等可用 Compose secrets 文件注入容器。Compose secrets 只解决容器内注入和按服务授权，其来源在本地 Compose 中仍是宿主机文件 bind mount，不提供异机密钥托管、轮换或灾难恢复。备份恢复密钥必须由独立方案在服务器外另存，否则整机损失时无法恢复；不要把秘密写入 Compose 文件、镜像层、Git 或日志。

## Argon2 的最小核对

Node 24 从 v24.7.0 提供内置异步 `crypto.argon2()`，但它与 `node:sqlite` 类似属于新增 API；`node-argon2` 维护者当前说明只测试受支持的 Node（README 在调查日写 Node >=22）并为常见 Linux/glibc、Alpine 等提供预编译件。[Node crypto](https://nodejs.org/download/release/v24.21.0/docs/api/crypto.html)、[node-argon2 README](https://github.com/ranisalt/node-argon2/blob/master/README.md)

账号契约已经固定 Argon2id 参数下限；运行方案无需扩成全依赖选型。实施时在目标镜像对内置异步 Argon2 与维护中的 `node-argon2` 做一项小型兼容/吞吐验证，选择一个并锁版本。无论选择哪一个，都在 SQLite 写事务外计算，使用独立的有界并发门，测量 19 MiB、2 次、并行度 1 下的登录峰值内存和延迟；验证期间凭据版本变化时，取得短事务后必须复核，不能把旧哈希结果用于签发会话。

## 已确认的运行输入与待实施取舍

1. **异地备份政策已确认**：上传前加密至独立 S3 兼容对象存储，恢复密钥在服务器外另存；供应商和采购留到实施。
2. **保留期限已确认，具体频率待定**：15 分钟目标对应的频繁恢复点保留 24 小时，另保留每日备份 30 天。监控依据仍是已验证远端产物中的数据库标记时间。
3. **远端恢复点过旧时的写入门禁已确认**：最新可用远端恢复点落后超过 15 分钟时自动暂停全部业务写入；保留只读查询、备份和诊断；新的异机备份完成并校验通过后才恢复业务写入，具体检查机制由运行方案落实。
4. **灾难恢复代次已确认**：恢复后进入新代次，全部旧会话和旧请求身份失效，隔离旧执行者，管理员在服务器本机重新授权。玩家密码、恢复码、停用和删号随备份回退，不建独立安全日志；公开恢复点并提示改密，不能声称旧密码也已失效。
5. **恢复演练已确认**：每日自动下载、解密和校验一份真实异机备份；上线前、以后每月及备份恢复流程修改后做完整隔离恢复演练，验证从人工开始后 2 小时内恢复。
6. **掉电耐久性已确认**：采用 WAL + `synchronous=FULL`；仍须验证目标存储栈正确实现持久化屏障。
7. **驱动与执行拓扑待验证**：本文建议 Node 24 LTS + `node:sqlite`。如果 release-candidate API 的契约测试或目标负载失败，降级到锁定版本 better-sqlite3，而不是建立双驱动长期兼容层。数据库调用所在的线程和连接数由事件循环、SQL 尾延迟及并发压测裁决；Q10 已另行确认单应用进程与不访问数据库的战斗工作线程池，两者不能混同。

## 发布前运行门槛

- 实际 SQLite 版本含 WAL-reset 修复，且升级未使用撤回版本；启动自检留痕。
- 约 100 人真实操作组合下，主事件循环延迟、数据库队列、busy、事务时长、checkpoint 和 Argon2 并发均有数据。
- SIGTERM、SIGKILL、进程崩溃、主机重启后无部分事务，待处理业务按操作身份恢复。
- 最新已验证远端恢复点年龄满足 15 分钟目标，超过时业务写入已自动暂停；恢复演练从人工开始不超过 2 小时。
- 从旧备份恢复后，旧会话、旧请求和迟到执行者不能进入新代次；备份内密码/恢复码可按已确认政策重新认证，管理员必须在本机重新授权。
- 备份实际下载后通过摘要、SQLite 完整性、外键和业务抽查；只复制运行中 `.db` 文件的方案不得验收。
