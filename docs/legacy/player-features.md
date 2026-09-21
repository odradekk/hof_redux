# 旧版玩家功能与规则静态证据清单

## 范围与方法

- 仅静态阅读 `old_hof/class/*.php` 与必要的 `old_hof/data/*.php`；未运行 PHP，未读取 `user/**/*.dat`、`log/**/*.dat`、留言/拍卖/排行运行数据或凭据。
- “正式”表示由 `class.main.php::Order()` 或 `OptionOrder()` 的正常页面路由可达；“公共”表示已登录和未登录分支均允许访问；“隐藏/备用”表示代码仍在但主导航或主流程未采用；“失效”表示页面可达但提交处理不可用。
- 稳定 ID 是本次迁移清单用的语义 ID，并非旧代码已有标识。

本文 `class.*.php` 和 `global.php` 均位于 `old_hof/class/`，`data.*.php` 位于 `old_hof/data/`。验收方向是待决规格输入，不代表本次批准新的行为。

## 路由总览证据

`old_hof/class/class.main.php::Order()` 在 27-287 行集中分派：登录后设置、拍卖、战场、城镇、模拟战、世界 Boss、普通地图战、角色页、背包、精炼、制作、商店、竞技场、招募和主页；未登录分支提供注册和登录。`OptionOrder()` 在 291-318 行定义排行总览、更新、公共留言、手册、教程、战报列表/分类/详情和游戏资料；`Order()` 在完成 `CheckLogin()` 后，分别于已登录分支 54 行和未登录分支 275 行调用它，因此这些入口对两种状态均可达，但并非在登录检查前执行。

## 功能证据清单

| 稳定 ID | 状态 | 入口/触发 | 文件与符号/行号 | 可观察行为（静态事实） | 新模块 | 后续验收方向 |
|---|---|---|---|---|---|---|
| account.register | 正式/登录前 | `?newgame`，提交 `Make` | `class.main.php::Order` 276-284；`MakeNewData` 3357-3417；`NewForm` 3422-3453 | ID、两次密码均要求 4-16 位 ASCII 字母数字且 ID 唯一；建立账号时写初始金钱、体力、默认记录战报，并让浏览器记住会话 ID | 账号 | 边界字符、重复 ID、两次密码不一致、满员；新库默认值一致 |
| account.login_logout | 正式 | 根页登录；任意页 `logout` | `CheckLogin` 3240-3281；`Set_ID_PASS` 3292-3315；`LoginForm` 3459-3480；`SettingShow` 3053-3055 | 密码摘要匹配后建立会话并更新登录时间；设置页可注销 | 账号 | 成功/失败登录、会话延续、注销后受保护页面不可达 |
| account.first_party | 正式/首次登录门槛 | 新账号首次登录，提交 `Done` | `Order` 47-50；`FirstLogin` 3619-3739；`data.base_char.php::BaseCharStatus` | 先创建唯一玩家显示名，再创建首名角色；首角四个选项实为职业 1/2 × 男女；玩家名和角色名按旧实现为 1-16 字节（中文提示称汉字算 3） | 账号；角色与编队 | 未完成前不能进入游戏；名称重复/空白/换行；四种首角组合及默认状态 |
| account.preferences | 正式 | `?setting`，提交 `setting01` / `color` | `SettingProcess` 2973-3032；`SettingShow` 3035-3050 | 可开关保存战报、无 JS 道具列表，设置留言显示色 | 账号；社区与运营 | 各选项持久化；颜色合法性另列疑点 |
| account.rename | 正式 | `?setting`，提交 `NewName` | `SettingProcess` 2973-3010；`class.user.php::ChangeName` 101-109 | 玩家显示名唯一、1-16 字节；扣 `NEW_NAME_COST` 后改名 | 账号 | 余额不足、重名、同名、旧名索引是否释放（见疑点） |
| account.delete | 正式/破坏性玩家操作 | `?setting`，提交 `delete`+`deletepass` | `Order` 56-58；`DeleteMyData` 3196-3208；`class.user.php::DeleteUser` 533-549 | 密码正确则删除账号相关角色/道具/账号目录并从排名移除 | 账号 | 错误密码不变；正确密码完整删除；排名和名称索引清理 |
| account.auto_abandon | 旧正式规则/新版已明确取消 | 登录主页触发周期管理 | `class.main.php::LoginMain` 3107-3112；`global.php::RegularControl` 90-118、`DeleteAbandonAccount` 49-87；`class.user.php::IsAbandoned` 551-562 | 旧版登录主页调用周期管理；在非 19:00-01:59 时段且控制时间到期后，遍历账号，按最后登录时间与 `ABANDONED` 阈值删除弃号并移出排行。项目约定新版取消闲置自动删号 | 账号 | 记录为批准差异，不迁移自动删除 |
| character.roster | 正式 | 主页；角色链接 `?char=<角色文件号>` | `LoginMain` 3107-3112；`ShowMyCharacters` 3130-3147；`class.user.php::CharDataLoadAll` 274-289 | 账号拥有多角色，主页列出并进入单角色管理 | 角色与编队 | 多角色加载、稳定角色 ID、角色链接越权防护 |
| character.recruit | 正式 | `?recruit`，提交 `recruit` | `RecruitProcess` 1968-2025；`RecruitShow` 2030-2104；`data.base_char.php` | 未达 `MAX_CHAR` 时可选 4 类招募模板，价格分别 2000/2000/2500/4000；输入角色名和性别，扣款后创建 | 角色与编队；物品与资产 | 人数上限、模板价格、余额不足、名称/性别校验、创建原子性 |
| character.dismiss | 正式 | `?char=...`，`byebye` 后 `kick` | `CharStatProcess` 739-763；`CharStatShow` 818；`class.char.php::DeleteChar` 1261-1270；`class.user.php::DeleteChar` 565-574 | 二次确认后角色离队/删除；页面没有显示最低留队人数保护 | 角色与编队 | 删除后编队/竞技防守引用处理；最后一名角色是否允许删除列待决 |
| character.rename | 正式/道具门槛 | 角色页 `rename`/`NameChange` | `CharStatProcess` 612-645；`CharStatShow` 807-809；`class.char.php::ChangeName` 294-297 | 持有物品 7500 才显示；改名成功后消耗 1 个 7500 | 角色与编队；物品与资产 | 道具缺失、输入合法性、成功时一次扣除、失败不扣 |
| character.level_growth | 正式/战斗结算 | 战斗获得经验；角色页提交 `stup` | `class.char.php::CalcExpNeed` 616-642、`GetExp` 645-655、`LevelUp` 658-663；`CharStatProcess` 379-423；`CharStatShow` 822-845 | 经验达阈值升一级，经验直接清零，每级获得属性点/技能点；角色页可将属性点分给 STR/INT/DEX/SPD/LUK，并受单项上限约束 | 角色与编队 | 跨级经验是否丢弃（按代码会清零）、等级上限、点数总和与属性上限 |
| character.respec_items | 正式/道具门槛 | 角色页 `showreset`/`resetVarious` | `CharStatProcess` 647-734；`CharStatShow` 810-816 | 7510-7513/7520 提供不同属性下限的重置；6000 可降低 SPD；有返还属性点及全卸装备路径 | 角色与编队；物品与资产 | 各道具精确规则需单独迁移；无有效变化时是否消耗；装备回背包 |
| character.skills | 正式 | 角色页选技能提交 `learnskill` | `CharStatProcess` 577-591；`CharStatShow` 1044-1075；`class.char.php::LearnNewSkill` 1165-1183、`UseSkillPoint` 1191-1198；`data.skilltree.php` | 页面显示已学与当前可学技能；学习受技能树和技能点约束 | 角色与编队 | 前置、职业/等级/点数条件；重复学习；失败不扣点 |
| character.class_change | 正式 | 角色页选择 `job` 提交 `classchange` | `CharStatProcess` 593-610；`CharStatShow` 1078-1098；`class.char.php::ClassChange` 667-676；`data.classchange.php::CanClassChange` 2 起 | 仅显示允许的转职；成功改变职业、重算 HP/SP，并将全部装备卸回背包 | 角色与编队；物品与资产 | 每条转职关系和门槛；转职后属性/技能保留；装备全卸 |
| tactics.patterns | 正式 | 角色页 `ChangePattern`/`PatternMemo`/`AddNewPattern`/`DeletePattern` | `CharStatProcess` 445-515；`CharStatShow` 850-892；`class.char.php::AddPattern` 299-313、`DeletePattern` 315-329、`PatternSave` 1255-1259；`data.judge_setup.php` | 每角色维护多套有序条件-动作规则，可修改数量阈值、切换当前模式、增删模式；可“设置并测试” | 角色与编队；战斗引擎 | 条件与动作全集、优先顺序、数量解析、模式上限/删除当前模式、战斗选择结果 |
| tactics.formation_guard | 正式 | 角色页提交位置/保护选项 | `CharStatProcess` 424-443；`CharStatShow` 894-931 | 前/后排；前排可配置永不保护或按 HP 25/50/75% 或概率 25/50/75% 保护后排 | 角色与编队；战斗引擎 | 前后排目标与保护触发的确定性战斗场景 |
| tactics.party_memory | 正式 | 普通/模拟/Boss 出战表单勾 `memory_party` | `MemorizeParty` 3086-3104；`MonsterShow` 1264-1271；`SimuBattleShow` 1175-1181；`UnionShow` 2883-2891 | 可记住最近一次 1-5 人出战选择，供后续表单默认勾选 | 角色与编队 | 角色删除后的清理、各战斗入口共享默认选择 |
| assets.stamina_recovery | 正式 | 账号载入时 | `class.user.php::DataUpDate` 393-400 | 根据距上次更新时间的秒数按 `TIME_GAIN_DAY / 86400` 比例恢复体力，更新基准时刻，以 `MAX_TIME` 封顶 | 物品与资产 | 离线恢复、重复加载、封顶、小数累计和时间边界；不把 Time 当战斗回合 |
| auction.browse_history | 正式 | 拍卖页及 `sort` | `AuctionItemBiddingForm` 2580-2613；`AuctionFoot` 2520-2527 | 展示商品；会员分支可按 sort 排序并竞价，非会员分支只读展示；页尾显示拍卖记录 | 交易；社区与运营 | 排序和商品字段、权限差异、操作历史、结算后展示一致 |
| inventory.list | 正式 | `?item` | `Order` 170-176；`ItemShow` 1354-1385；`class.user.php::LoadUserItem` 344-362 | 展示持有道具及数量/详情，支持 JS 与无 JS 两种列表方式 | 物品与资产 | 堆叠数量、精炼/附加状态展示、两种视图一致 |
| equipment.manage | 正式 | 角色页 `equip_item` / `remove` / `remove_all` | `CharStatProcess` 517-575；`CharStatShow` 944-1038；`class.char.php::Equip` 680-757 | 职业装备类型过滤；武器/盾/甲/道具四槽；双手武器与盾互斥；替换品回背包；总 handle 超角色承载则回滚 | 物品与资产；角色与编队 | 各类型适配、双手互斥、承载上限、装备替换与背包数量原子性 |
| adventure.map_select | 正式 | `?hunt`→`?common=<map>` | `HuntShow` 1187-1243；`MonsterShow` 1245-1283；`data.land_appear.php::LoadMapAppear`；`data.land_info.php::LandInformation` | 仅列当前账号状态解锁的地图；地图页列遭遇并选择 1-5 角色出战 | 地图冒险 | 地图开放条件、遭遇列表、伪造未开放 map 拒绝 |
| battle.normal | 正式 | `?common=<map>`，提交 `monster_battle` | `MonsterBattle` 1285-1345 | 校验地图已开放、体力足、队伍 1-5；生成敌队并扣 `NORMAL_BATTLE_TIME`，自动战斗，保存角色成长，结算金钱/掉落，按偏好保存战报 | 地图冒险；战斗引擎；物品与资产 | 固定随机输入可复现；体力/奖励/掉落；失败前不扣资源；保存边界 |
| battle.simulation | 正式 | `?simulate`，提交 `simu_battle` | `SimuBattleProcess` 1149-1168；`SimuBattleShow` 1170-1185；`DoppelBattle` 1122-1147 | 选 1-5 名己方角色，与复制出的己方队伍试战；代码未见体力消耗或奖励结算 | 战斗引擎 | 不消耗体力、不发奖励、不持久化战斗伤害；战术测试结果可读 |
| battle.world_boss | 正式 | 战场存活 Boss 链接 `?union=<no>`，提交 `union_battle` | `HuntShow` 1205-1239；`UnionProcess` 2774-2858；`UnionShow` 2860-2894；`class.user.php::CanUnionBattle` 116-123；`class.union.php` | 共享 Boss 只在存活时列出；有挑战冷却、队伍 1-5、总等级上限、体力成本；可带随从，战斗后保存共享 Boss 状态并结算掉落 | 世界 Boss；战斗引擎 | 并发扣血/击杀、冷却与体力、等级上限、随从、复活、重复提交幂等 |
| arena.ranking_public | 正式/公共 | `?rank` | `OptionOrder` 294；`global.php::RankAllShow` 823 起；`class.rank2.php::ShowRanking` 358-413 | 未登录也可看完整竞技排行 | 竞技场；社区与运营 | 隐私字段、同名次显示、分页/范围 |
| arena.team_and_challenge | 正式 | `?menu=rank`，`SetRankTeam` / `ChallengeRank` | `RankProcess` 1816-1888；`RankShow` 1892-1964；`class.rank2.php::Challenge` 57-153、`ProcessByResult` 220-282；`class.user.php::RankParty` 127-146、`CanRankBattle` 218-230 | 防守/参赛队 1-5 人，设置后有重设冷却；显示前五和邻近五名；挑战有冷却并更新双方战绩与名次 | 竞技场；战斗引擎 | 锁队/删角、胜负/平局换位、攻防战绩、挑战并发及冷却 |
| shop.buy | 正式 | `?menu=buy`；另有 `?shop` 旧表单 | `ShopBuyProcess` 1566-1613；`ShopBuyShow` 1615-1668；`ShopProcess` 1408-1473、`ShopShow` 1475-1564 | 可批量选择商品与数量，汇总扣款入背包；`?shop` 还保留单项买卖实现 | 交易；物品与资产 | 数量输入、余额不足整体失败、批量总价、地点开放限制 |
| shop.sell | 正式 | `?menu=sell`；另有 `?shop` 旧表单 | `ShopSellProcess` 1671-1712；`ShopSellShow` 1714-1767；`ShopProcess` 1448-1470 | 可批量卖持有物，按卖价结算；旧表单为单项路径 | 交易；物品与资产 | 超持有数量、不可售物、批量结算、同请求原子性 |
| shop.work | 拆分页失效；备用合并页可用 | 正式导航 `?menu=work`；备用 `?shop` 提交 `partjob` | `WorkProcess` 1771-1788；`WorkShow` 1791-1811；`ShopProcess` 1408-1418；`ShopShow` 1555-1561 | 正式导航指向的拆分页展示每次 100 体力换 500 金钱、可选 1-10 次，但处理代码整段注释，提交无结算。备用合并商店页的 `partjob` 每次固定扣 100 体力、给 500 金钱，处理有效但没有批量次数 | 交易；物品与资产 | 产品确认是否保留打工、采用单次还是批量、是否沿用旧数值；再制定体力扣除与收益验收 |
| smithy.refine | 正式 | `?menu=refine`，提交 `refine` | `SmithyRefineProcess` 2138-2210；`SmithyRefineShow` 2212-2285；`class.smithy.php::CanRefine` 84-93、`ItemRefine` 95-107、`RefineProb` 109-139 | 选择可精炼装备和次数；逐次扣费并逐次概率判定，失败时终止；受精炼上限约束 | 物品与资产 | 0/边界次数、逐次费用、失败停机、物品状态与金钱一致性、概率注入 |
| smithy.create | 正式 | `?menu=create`，提交 `Create` | `SmithyCreateProcess` 2310-2372；`SmithyCreateShow` 2374-2448；`data.create.php::HaveNeeds` 64 起；`class.smithy.php::CreateItem` 37-77 | 仅显示材料足够的配方；校验并消耗配方材料，可选追加材料为制品添加特殊效果；正式代码在 2346 行将制作费固定为 0 | 物品与资产 | 配方需求、追加材料消耗与效果、材料不足不结算、零制作费、结果独立持有状态 |
| auction.membership | 正式/道具门槛 | `?menu=auction`，提交 `JoinMember` | `AuctionJoinMember` 2450-2469；`AuctionEnter` 2471-2476；`AuctionHeader` 2479-2518 | 花费 `round(START_MONEY*1.10)` 获得会员卡物品 9000，之后才可竞拍/上架；功能还受开关控制 | 交易；物品与资产 | 重复入会、余额不足、开关关闭、会员卡丢失后的权限 |
| auction.bid | 正式 | `?menu=auction`，提交 `ArticleNo`/`BidPrice` | `AuctionItemBiddingProcess` 2532-2574；`class.auction.php::ItemBidRight` 97-102、`ItemBid` 123-153 | 不可竞拍自己或连续自抬；先扣全额出价，旧最高价即时退回；最低加价由当前价计算；最后 15 分钟内出价会延长至约 15 分钟；同 IP、特定移动端被拒 | 交易 | 并发出价、扣款与退款原子性、延时规则、同 IP/移动端限制是否保留待决 |
| auction.exhibit | 正式 | 拍卖页 `ExhibitItemForm`→`PutAuction` | `AuctionItemExhibitProcess` 2616-2693；`AuctionItemExhibitForm` 2695-2772；`class.auction.php::ItemAddArticle` 284-331 | 收 500 上架费；受全场上限与会话冷却；仅允许指定类型；数量、时长、起拍价和 40 字节备注；上架时从背包移除 | 交易；物品与资产 | 类型白名单、数量/价格/时长、重复提交、上架费与物品原子性 |
| auction.settlement | 正式/惰性触发 | 每次进入拍卖路由，登录检查之前 | `Order` 31-37；`class.auction.php::ItemCheckSuccess` 24-43、`UserSaveData` 74-96 | 到期有买家则物品给买家、成交价给卖家；无人则退物；随后写各账号并记录日志 | 交易 | 多请求同时结算、崩溃中断、重复结算幂等；新版需事务/调度而非照搬惰性文件写入 |
| town.places | 正式 | `?town` | `TownShow` 2896-2942；`data.town_appear.php` | 按当前地点/开放条件显示商店、招募、铁匠、拍卖、竞技场入口及外部论坛链接 | 社区与运营；地图冒险 | 各地点设施开放矩阵、外链可配置 |
| town.messages | 正式 | `?town`，提交 `message` | `TownBBS` 2944-2971 | 服务端接受少于 121 字节的城镇留言，记录玩家名与颜色，保留最多 50 条；输入框 maxlength 为 60 | 社区与运营 | 空白/长度/转义、并发追加、展示条数、颜色 |
| public.messages | 正式/公共 | `?bbs`，提交 `message` | `OptionOrder` 296；`bbs01` 3745-3780 | 与城镇留言分开的公共底部留言入口；受 BBS_BOTTOM_TOGGLE 控制，未登录名为“无名”，保存最多 150 条 | 社区与运营 | 是否首期保留为正式入口待确认；匿名/未登录发言策略 |
| public.manual_tutorial_update | 正式/公共 | `?manual`、`?manual2`、`?tutorial`、`?update` | `OptionOrder` 295、297-299；`global.php::ShowManual` 719、`ShowManual2` 725、`ShowTutorial` 731、`ShowUpDate` 737 | 登录前后可查看两套手册、教程和更新记录；另有登录主页内 `class.main.php::ShowTutorial` 3114 | 社区与运营 | 确认两套手册哪个是正式/是否合并；将规则陈述与代码差异建待决项 |
| public.game_data | 正式/公共 | `?gamedata=<类别>` | `OptionOrder` 306-308；`global.php::ShowGameData` 775 起；相关 `data.gd_*.php` | 提供职业、物品、怪物、判定等资料页（具体类别由该函数分派） | 社区与运营 | 各资料类别完整性、隐藏内容策略、与版本化 JSON 同源生成 |
| reports.lists_and_detail | 正式/公共 | `?log`、`?clog`、`?ulog`、`?rlog`；`?log=<id>` 等 | `OptionOrder` 300-317；`global.php::ShowLogList` 304、`LogShowCommon` 369、`LogShowUnion` 386、`LogShowRanking` 403、`ShowBattleLog` 463 | 普通、世界 Boss、竞技战报有分类列表和详情；保存普通战报受个人偏好，Boss/竞技另有入口 | 社区与运营；战斗引擎 | 授权/隐私、移动端可读、版本标识、缺失/恶意 ID、分类一致 |
| shop.legacy_combined | 备用/重复实现（含唯一有效打工结算） | `?shop` | `Order` 201-207；`ShopProcess` 1408-1473；`ShopShow` 1475-1564 | 单页合并买/卖/打工；城镇当前导航指向 `?menu=buy/sell/work`。其买卖规则与拆分页并存；其中 `partjob` 分支 1410-1418 可实际完成一次 100 体力换 500 金钱 | 交易 | UI 不必逐个复刻；将有效的单次打工结算列为保真候选，由后续决策确认是否保留及沿用数值，并对拆分页批量意图另作决定 |
| arena.legacy_rank | 备用 | `class.rank.php`，主流程引用已注释 | `RankProcess` 1835-1856；`RankShow` 1937-1947；现用 `class.rank2.php` | 旧竞技实现残留，但主路由显式载入 `class.rank2.php`，旧调用被注释 | 竞技场 | 以 rank2 为正式证据，旧类只用于比对疑点 |

## 确凿差异、失效点与疑似缺陷（不作修复结论）

1. **打工有失效拆分页和有效备用页（确凿）**：正式导航的 `?menu=work` 展示 100 Time→500 金钱和 1-10 次选择，但 `WorkProcess` 1772-1787 被注释，提交无结算；备用 `?shop` 的 `partjob` 分支 1410-1418 可实际执行单次 100 体力→500 金钱。需要确认是否恢复批量或仅保留单次规则。
2. **两套商店买卖入口重复，但不存在“备用购买额外耗体力”的差异（更正）**：1409-1418 是 `partjob` 打工分支，不是 `shop_buy`。备用 `?shop` 的购买从 1419 行开始，扣的是商品价格；先前将 100 体力归到购买是误读。
3. **颜色校验疑似布尔错误**：`SettingProcess` 3024-3028 使用“长度不是 6 **且** 不匹配”才拒绝；长度为 6 的任意字符串或长度异常但恰好前缀匹配的值可能通过。仅为静态推断。
4. **改名名称索引疑似遗留旧名**：`SettingProcess` 成功后仅 `userNameAdd($NewName)`（3005），未见对应删除旧名；`class.user.php::ChangeName` 只改对象名称。旧名可能永久占用，需核查名称索引函数和真实流程（未读运行数据）。
5. **升级经验溢出确凿丢弃**：`GetExp` 加经验后最多调用一次 `LevelUp`，而 `LevelUp` 将经验直接置 0（645-663）；高额经验不会连续升级且余量不保留。是否是设计需待决。
6. **竞技队伍人数报错文案与条件不一致**：条件允许 1-5 人（只拒绝 0 或 >5），错误文案称“大于1人小于5人”（1879-1881）。以条件为代码事实，文案不可作为规则证据。
7. **世界 Boss 使用锁内绝对状态保存，并非无锁快照覆盖（核验后收紧）**：`UnionNumber`→`LoadData` 通过 `FileLock` 对 Boss 文件取得排他锁并保持文件句柄，战斗后 `battle::SaveCharacters` 调 `union::SaveCharData` 将绝对 HP/SP 写回并关闭锁（`class.union.php` 136-163、332-343；`class.battle.php` 238-246）。它没有伤害差额合并；正常取得锁时战斗串行。剩余风险在 `FileLock` 131-156：非阻塞锁重试 5 次后仍返回文件句柄，未明确确认已持锁，因此高争用路径需专项验证，不能笼统称必然覆盖。
8. **拍卖结算由访问触发且跨多个账号分步保存**：`Order` 在进入拍卖时先扫描到期品，`UserSaveData` 分别写钱、道具和角色，崩溃或并发可能产生部分结算/重复结算；这是新版事务验收重点，不等同于已证明线上发生过错误。

## 未在本清单展开

- 内容 ID、职业/技能/物品/地图/怪物的全量数量与公式；管理后台；废弃实验脚本；运行期玩家、战报、留言、拍卖和排名数据。
- 特殊技能效果、战斗公式、掉落概率等需要后续规则迁移议题逐项取证。
