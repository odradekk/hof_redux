# Domain Docs

本项目采用 **single-context**：根目录 `CONTEXT.md` 保存统一领域词汇，`docs/adr/` 保存需要长期解释的架构决定。前后端分别构建不等于需要多个领域上下文。

## Before exploring

- 阅读根目录 `CONTEXT.md`，然后按当前任务阅读 `docs/adr/` 中相关决定。
- 文件不存在时继续工作，不仅因缺少文件而要求初始化；有已确定术语或符合条件的决定时，由 domain-modeling 按需创建。
- 使用 `CONTEXT.md` 的术语撰写代码概念、议题、测试和说明；新概念先检查是否已有定义。

## Ownership

- `CONTEXT.md` 只保存领域术语、含义和容易混淆的同义词，不保存技术方案、任务进度或实现细节。
- 架构决定仅在难以反转、有真实取舍且未来读者需要解释时记录 ADR；采用 `docs/adr/0001-short-title.md` 递增编号。
- 新建议与现有 ADR 冲突时，明确指出冲突和重新讨论理由，不能静默覆盖。
- 已确定的项目约束见根目录 `AGENTS.md`；Wayfinder 决策详情在 GitHub 对应议题，地图只保存索引，避免维护多份决策正文。
