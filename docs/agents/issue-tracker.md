# Issue tracker: GitHub

本项目的需求、规格和决策地图使用 GitHub Issues：`odradekk/hof_redux`。使用 `gh` CLI；命令显式指定仓库，避免在子目录或独立工作区操作错误仓库。

## Conventions

- 创建：`gh issue create --repo odradekk/hof_redux --title "..." --body-file <UTF-8文件>`。
- 阅读：`gh issue view <number> --repo odradekk/hof_redux --comments`；需要结构化信息时使用 `--json` 获取正文、标签和指派人。
- 查询：`gh issue list --repo odradekk/hof_redux --state open --limit 100 --json number,title,labels,assignees`。完整查询须处理分页，不能把默认首屏当成全部。
- 评论或更新：`gh issue comment <number> --repo odradekk/hof_redux --body-file <UTF-8文件>`；`gh issue edit <number> --repo odradekk/hof_redux --body-file <UTF-8文件>`。
- 标签：`gh issue edit <number> --repo odradekk/hof_redux --add-label "..."` 或 `--remove-label "..."`。
- 关闭：先发布结论评论，再运行 `gh issue close <number> --repo odradekk/hof_redux`。
- 多行正文和评论写入 UTF-8 临时文件，通过 `--body-file` 传入；保留真实换行，避免 shell 拼接。
- 用户可读的描述使用带链接的议题标题，不使用裸编号代替名称。
- Skill 要求“publish to the issue tracker”时创建 GitHub Issue；“fetch the relevant ticket”时读取正文、评论和标签。
- 仓库提交、推送、PR、合并和部署另按用户授权执行；创建规划议题不等于获得这些操作的授权。
- `gh` 连接超时时先检查本机已配置代理，可仅对当前进程沿用该代理。必要时使用可用 GitHub 连接器执行等价操作；原生父子关系与依赖仍须核验，不能因临时网络故障改用正文约定。

## Pull requests as a triage surface

**PRs as a request surface: no.**

## Wayfinding operations

- 地图是一个带 `wayfinder:map` 标签的 Issue。使用 Destination、Notes、Decisions so far、Not yet specified、Out of scope 五个章节；地图是索引，决策详情留在对应议题。
- 决策议题使用 `wayfinder:research`、`wayfinder:prototype`、`wayfinder:grilling` 或 `wayfinder:task` 标签，正文明确 Question。
- 使用 GitHub 原生 sub-issue 关系关联地图与子议题：`POST repos/odradekk/hof_redux/issues/<map-number>/sub_issues`，传入子议题数字数据库 ID `sub_issue_id`。
- 使用 GitHub 原生依赖：`POST repos/odradekk/hof_redux/issues/<child-number>/dependencies/blocked_by`，传入阻塞议题数字数据库 ID `issue_id`。数据库 ID 通过 `gh api repos/odradekk/hof_redux/issues/<number> --jq .id` 获取；不能使用议题编号或 node_id。
- 两阶段创建：先取得全部议题 ID，再建立父子关系及依赖，并读取关系核验。
- 查询可领取议题时，分页读取地图子议题，保留 open、未指派、且所有阻塞议题已关闭的子议题，按地图子议题顺序选择。不可用全仓库 open 列表冒充本地图范围。
- 领取先执行 `gh issue edit <number> --repo odradekk/hof_redux --add-assignee @me`。当前地图由 `odradekk` 驱动。
- 解决时发布 resolution comment、关闭议题，并在地图 Decisions so far 中追加带链接标题和一句结论。不把开放议题列表复制进地图正文。
- 仅在确认 GitHub 不支持相应原生能力时使用后备正文约定：子议题写 `Part of #<map>`，地图列出子议题；依赖写 `Blocked by: #<number>`。鉴权、权限或临时失败不能作为降级理由。
- Wayfinder 默认只规划决策。首次建图不顺手解决人工决策议题；后续每个会话最多解决一个非 research 议题，新增可明确的问题并维护尚待明确的范围。

## Current map

继续完整重写规划时，先读取 [HOF Redux：完整旧版重写决策地图](https://github.com/odradekk/hof_redux/issues/1)，再按上述规则查询其可领取子议题。
