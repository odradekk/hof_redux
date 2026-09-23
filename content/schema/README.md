# S1 内容 Schema 说明

`content/schema/*.schema.json`（draft 2020-12）是构建与发布检查实际执行的
单文件结构规则：字段、类型、边界、未知字段拒绝、判别联合
（装备–材料、通用伤害–具名处理器）以及旧 9000 排除。

执行入口：`packages/content/src/schemas.ts` 用 ajv 加载并编译，
`validateContent` 对每个内容文件先做重复键扫描与 JSON 解析，
再跑对应 Schema；结构失败即拒绝发布。

Schema 表达不了的规则由语义检查执行（同文件 `validate.ts`）：
跨文件引用存在性与类别、ID 全局唯一、处理器登记与作用域、
战术数量区间、掉落/遭遇分布语义、素材完整性、穿戴合法性、S1 范围。
