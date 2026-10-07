# 地下城、永久死亡与角色体力

状态：已实现（2026-10-06）。2026-10-07 起，角色属性、体力上限与恢复、疲劳、濒死、陷阱与事件伤害、侦察等规则由 `attribute-design.md` 修改，下文已同步；两者冲突时以 `attribute-design.md` 为准。这是对“保留原有玩法”目标的有意变更：旧版普通狩猎（`feature-inventory.md` F05）被地下城取代，账号体力（F02）改为角色体力。数值均为首版占位，集中在下列常量中，调整时同步规则页即可。

## 1. 规则

### 1.1 地下城

- 手工设计的无向图：节点是房间，边是通道。每次只能移动到相邻房间。入口必须是空房间，所有房间都必须能从入口到达，且至少有一个出口。
- 房间类型：`empty`、`battle`、`chest`、`trap`、`rest`、`event`、`exit`。
- 战争迷雾：地图只画出到过的房间及其相邻房间，未到过的房间不显示名称和类型；绘制范围裁剪到可见房间，不暴露地图全貌。
- 首次进入房间时结算：空房间直接清空；战斗立即开打；陷阱立即触发。宝箱、事件、休息处和出口等待玩家操作。已清空的房间再次经过不会重复触发。
- 随机结果使用该次操作的服务器种子（`GameAction` 每个操作一个种子），结果随操作一起保存，重放同一幂等键得到同一结果。
- 地图道具 8000（古代洞穴）、8009（滴冻山入口）仍放在仓库中作为入场条件，不会被消耗。

| 地下城 | 推荐 | 条件 | 战斗来源 |
| --- | --- | --- | --- |
| 哥布林小径 `goblin_trail` | Lv1-6 | 无 | gb0、gb1、自定义池、固定首领 1008 |
| 古之洞穴 `ancient_cave` | Lv8-20 | 8000 | ac0、ac1、固定首领 1017 |
| 滴冻山 `frosty_mountain` | Lv5-25 | 8009 | snow0、snow1、snow2 |

### 1.2 探索期间

- 每个账号最多一个进行中的探索（数据库部分唯一索引保证）。
- 城镇命令在 Service 层统一拒绝（`GameAction::ensureInTown`）：商店、打工、锻造、招募、离队、改名、重置、转职、装备、拍卖、竞技场、共享首领。
- 仍可使用：属性分配、学习技能、站位与保护、行动模式及镜像测试、显示设置、记住队伍、模拟战、广场留言。
- 未参加探索的角色在城镇中照常恢复。
- 状态栏显示“地下城探索中”链接。

### 1.3 濒死与永久死亡

- 战斗结束时 HP 为 0（或状态为倒下）、陷阱或事件把 HP 降到 0 的角色进入濒死（`attribute-design.md` §2.7）；濒死步数耗尽、重伤后再次倒下或队伍全灭时永久死亡：写入 `died_at`，HP 置 0。
- 死亡角色由 `Character` 的全局作用域隐藏，不再出现在任何队伍、排名阵容、角色上限或页面中；记住的出战队伍同时移除该角色。
- 死亡角色的装备转入本次战利品，幸存者离开或撤离时带回仓库。
- 队伍全灭（没有可行动的成员）：濒死成员一同死亡，探索结束为 `wiped`。所有角色都已阵亡的账号进入 `/setup`，可免费选择一名战士或巫师重新出发，不需要重新命名队伍（避免资金不足时无法继续游戏）。
- 共享首领、竞技场、模拟战不造成伤害，也不会死亡。

### 1.4 角色体力与疲劳

- 每名角色体力上限 `100 + 体质`，沿用原单位制（1 点 = 86400 单位）；城镇中每秒恢复 `5 × 上限` 单位，约 4.8 小时回满。地下城中不自然恢复。
- 疲劳（`App\Domain\Combat\Fatigue`）：按体力占上限的比例分档，在战斗引擎中降低造成的伤害、治疗量和行动速度，不再削减属性，档位见 `attribute-design.md` §2.6。地下城战斗和共享首领使用疲劳；竞技场和模拟战不使用。

| 消耗 | 数值 | 不足时 |
| --- | --- | --- |
| 地下城移动 | 每名可行动成员 2（濒死成员不消耗） | 降到 0，不阻止行动 |
| 地下城战斗 | 战后每名可行动成员 5 | 降到 0 |
| 开宝箱 / 陷阱 / 事件 | 按房间内容 | 降到 0 |
| 打工 | 所选角色 100 | 拒绝 |
| 共享首领 | 每名出战角色 10 | 拒绝，全员都不扣 |

### 1.5 HP / SP

- 当前 HP/SP 继续保存在角色 `stats` 中（原本就有这两个字段，只是每战回满）。地下城战斗从当前值开始，战后写回。
- 城镇中每小时恢复上限的 20%，由 `health_updated_at` 起算，读取时计算，不写库；进入地下城时把恢复结果写入并冻结，离开或撤离时从当时的值重新开始计时。
- 地下城中的恢复：消耗品；休息处（HP 和 SP 各恢复房间规定的百分比，并恢复体力）；部分事件。

### 1.6 仓库、背包与战利品

| 位置 | 含义 |
| --- | --- |
| `warehouse` | 原“背包”，无上限，城镇功能只使用这里的物品 |
| `pack` | 进入地下城时带上的消耗品，只在探索期间存在 |
| `loot` | 地下城中获得的道具和阵亡者的装备，离开或撤离时存入仓库 |
| `equipped` / `auction` | 不变 |

- 背包只能装消耗品（带 `restore` 的道具）。上限为出战成员负重之和，每人 `10 + floor(力量 ÷ 10)`；重量沿用道具的 `handle`。恰好等于上限可以进入。
- 战利品不占背包重量。资金累计在探索记录的 `loot_money` 中。
- 消耗品只能在战斗之外对存活的同伴使用；战斗引擎没有道具行动。
- 带回仓库时，未精炼、无附魔的道具合并到仓库中已有的同种堆叠。

### 1.7 结算

| 结局 | 经验 / 升级 | 资金与战利品 | 背包 | 阵亡者装备 |
| --- | --- | --- | --- | --- |
| 出口离开（`cleared`） | 每战立即生效 | 入账，另加通关奖励 | 回仓库 | 回仓库 |
| 撤离（`retreated`） | 每战立即生效 | 入账 | 回仓库 | 回仓库 |
| 全灭（`wiped`） | 每战立即生效 | 丢失 | 丢失 | 丢失 |

战斗未分胜负（行动上限）或敌方获胜但仍有幸存者时，队伍退回上一个房间，该房间保持未清空，下次进入重新抽取敌人。

### 1.8 消耗品

| ID | 名称 | 效果 | 重量 | 价格 |
| --- | --- | --- | --- | --- |
| 4000 | 干粮 | 体力 +15 | 1 | 150 |
| 4001 | 烤肉 | 体力 +30 | 2 | 400 |
| 4002 | 炖菜便当 | 体力 +60 | 3 | 1,200 |
| 4100 | 草药 | HP +25% | 1 | 300 |
| 4101 | 治疗药 | HP +50% | 1 | 800 |
| 4102 | 高级治疗药 | HP +100% | 2 | 2,500 |
| 4200 | 魔力草 | SP +25% | 1 | 400 |
| 4201 | 魔力药 | SP +50% | 1 | 1,000 |
| 4202 | 高级魔力药 | SP +100% | 2 | 3,000 |

全部在商店出售，部分也出现在宝箱、事件和通关奖励中。

## 2. 内容

手工内容放在 `content/redux/`（`items.json`、`dungeons.json`），记录带 `source.authored = true`，有独立的 `manifest.json`。`ContentCatalog` 按种类合并两层内容：手工记录不得复用提取内容的 ID；目录版本为两层版本的组合哈希；`verifyIntegrity()` 分别校验两层。修改手工内容后运行：

```
python3 tools/content/authored.py
```

房间字段：

| 类型 | 字段 |
| --- | --- |
| 全部 | `name`、`pos: [列, 行]`（网格坐标，不可重复） |
| `battle` | `area`（复用地图的出现率）或 `encounters`（`{怪物: 权重}`）；可选 `fixed`（固定敌人列表）、`count`（`"party"` 或 0–5，默认与出战人数相同）、`land` |
| `chest` | `money: [下限, 上限]`、`loot: {道具: 权重}`、`rolls`（1–5）、`open_stamina` |
| `trap` | `damage: [下限, 上限]`（固定点数）、`stamina_loss`；先按队伍最高敏捷判定拆除，再逐人按敏捷判定闪避 |
| `rest` | `uses`、`heal_percent`、`stamina` |
| `event` | `text`、`choices: [{label, outcomes: [{weight, text, effects}]}]` |

事件效果只能是封闭列表中的一项：`heal_percent`、`damage`（固定点数）、`sp_percent`、`stamina`（可为负）、`money`、`{item, quantity}`。事件结果可标记 `lucky: true`，其权重随队伍最高幸运提高。

`App\Domain\Dungeon\DungeonMap` 在加载时拒绝：重复或悬空的边、自环、不可到达的房间、没有出口、入口不是空房间、坐标重复、越界数值、未知效果。单元测试另外确认每个地下城引用的怪物、道具、地图都存在且可战斗。

## 3. 数据

- `characters`：`stamina_units`、`stamina_updated_at`、`health_updated_at`、`died_at`；PostgreSQL 约束体力范围。
- `users`：删除 `stamina_units`、`stamina_updated_at`。
- `inventory_items.location`：`backpack` 改名为 `warehouse`，新增 `pack`、`loot`。
- `dungeon_runs`：队伍、当前与上一个房间、每个房间的状态、`loot_money`、`status`、`steps`、`ended_at`；每个账号最多一个 `active`；PostgreSQL 约束状态取值、资金非负、`active` 与 `ended_at` 一致。
- `dungeon_run_events`：探索日志，可关联战报。

## 4. 代码

```
app/Domain/Dungeon/DungeonMap.php      图校验、邻接、迷雾、内容引用
app/Domain/Dungeon/RoomRules.php       宝箱、陷阱、事件的随机结果
app/Domain/Combat/Fatigue.php          体力 → 属性惩罚
app/Application/Player/Vitals.php      角色 HP/SP/体力的恢复、消耗、冻结与恢复计时
app/Application/Dungeon/DungeonService.php   enter / move / act（open、choose、rest、use、leave、retreat）
app/Http/Controllers/Game/DungeonController.php
app/Http/View/DungeonMapView.php       迷雾地图投影
resources/views/game/dungeon/*.blade.php
```

`BattleService::fightDungeon()` 以当前 HP/SP 和疲劳开战，经验立即结算，资金交给探索记录、道具写入 `loot`。旧的 `fight()`、`WorldService::hunt()`、`/hunt` 路由与页面已删除。

## 5. 验证

- `tests/Unit/Dungeon/DungeonMapTest.php`、`tests/Unit/Combat/FatigueTest.php`：图校验、迷雾、陷阱闪避与伤害范围、权重边界、疲劳阶梯边界。
- `tests/Feature/World/DungeonTest.php`：背包重量边界与拆分、地图条件、进入时冻结恢复、相邻移动与幂等重放、经验立即生效而资金暂扣、城镇锁定、陷阱致濒死与步数耗尽后死亡并转入装备、全灭与免费重新出发、宝箱/事件/休息/道具只结算一次且不超过上限、出口奖励、迷雾页面与他人记录不可见、回仓库合并堆叠。
- `tests/Feature/World/PostgresDungeonConcurrencyTest.php`：两个独立进程同时进入只产生一个探索、只装一次背包；同时移动按顺序结算。
- `tools/browser`：地下城列表、准备页、进行中探索页在三种宽度下的 CSP、溢出与 axe 检查；无 JavaScript 的进入与撤离表单校验和重放。

濒死、救回、重伤、侦察、拆除与先手的测试见 `attribute-design.md` §5。战斗中被复活、结束时 HP > 0 的濒死成员被救回已有测试；判定仍只看战斗结束时的状态。

未覆盖：战斗平局或敌方获胜但有幸存者时退回上一个房间的路径也没有确定性测试。
