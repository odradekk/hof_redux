@extends('layouts.app')
@section('title', '规则和手册')
@section('content')
@include('game.information.manual.head')
<div class="doc">
@php($c = $constants)
@php($text = $data->text())
{{-- Section order and anchors follow legacy data/data.manual0.php; other pages link to these anchors. --}}
<h2 class="sec" id="content">目录</h2>
<ul class="toc">
<li><a href="#rule">规则</a></li>
<li><a href="#menus">菜单</a></li>
<li><a href="#btl">战斗流程</a></li>
<li><a href="#char">人物设定</a></li>
<li><a href="#charstat">人物的基础能力值</a></li>
<li><a href="#statup">能力值上升</a></li>
<li><a href="#jdg">人物在战斗中的命令</a></li>
<li><a href="#posi">人物的位置关系及后卫保护</a></li>
<li><a href="#equip">人物装备</a></li>
<li><a href="#skill">人物技能</a></li>
<li><a href="#elem">攻击属性</a></li>
<li><a href="#state">人物状态</a></li>
<li><a href="#jobchange">转职(职业转换)</a></li>
<li><a href="#sacrier">狂战士(Sacrier)的攻击方式</a></li>
<li><a href="#time">体力(Time)</a></li>
<li><a href="#dungeon">地下城(Dungeon)</a></li>
<li><a href="#town">城镇(Town)</a></li>
<li><a href="#union">共享首领(Union)</a></li>
<li><a href="#ranking">排行</a></li>
<li><a href="{{ route('manual', 'advanced') }}">高级指南</a></li>
<li><a href="#cr">使用的图像</a></li>
</ul>

<h2 class="sec" id="rule">规则(Rule) <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent prose">
<p>本游戏的目的，就是爬到排行榜的第一名，并持续维持住排名。<br>并没有什么剧情式的冒险要素。</p>
<p>自己组建 1–{{ $c['party_max'] }} 人的队伍进行战斗，让它登上排行榜。</p>
<p>为了登上榜首，要持续与敌人（怪物）战斗来锻炼角色，<br>从敌人手中夺取更强力的道具——<br>这就是这个游戏有趣的地方。<br>排行榜上的对手则是其他玩家。</p>
<p>每个角色都可以按技能的使用条件进行详细的设定。<br>要调整出无懈可击的战术配置，不是一件简单的事情。</p>
</div>

<h2 class="sec" id="menus">菜单(Menu) <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<p class="indent"><span class="u">登录后会显示如下菜单</span></p>
@include('game.information.manual.menu-map')
<p class="indent">另外，<span class="u">菜单的下面</span>显示：</p>
<ul class="indent">
<li><b>队伍名称</b> - 名称。</li>
<li><b>资金(Gold)</b> - 所拥有的金钱。</li>
<li><b>退出</b> - 注销登录。</li>
</ul>

<h2 class="sec" id="btl">战斗流程 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<p>战斗过程完全由电脑处理，战斗中不能下达指令。</p>
<ol class="flow">
<li>1. 按照人物的速度顺序行动。</li>
<li>2. 人物根据事先设定的行动模式行动。</li>
<li>3. 重复 1 和 2。</li>
<li>4. 满足以下条件时战斗结束。</li>
</ol>
<p><span class="u">结束条件</span><br>
1. 我方或敌方全员战斗不能。<br>
2. 累计行动 {{ $c['actions'] }} 次时判为平局（模拟战为 {{ $c['simulation_actions'] }} 次；竞技场改为比较存活人数，见 <a href="#ranking">排行</a>）。</p>
<p>每场战斗开始时，所有角色的生命和魔力都是满的；战斗中受到的伤害不会带到下一场。</p>
</div>

<h2 class="sec" id="char">人物设定 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<p>登录后，点击主页中的人物形象，会显示该人物的角色页。</p>
<figure><img src="{{ asset('image/manual/001.gif') }}" width="153" height="143" alt="原版示意图：点击角色形象"><figcaption>原版示意图：点击图中箭头所指的角色形象。</figcaption></figure>
<p>角色页的各项内容说明如下。</p>
</div>

<h2 class="sec" id="charstat">人物的基础能力值 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<figure><img src="{{ asset('image/manual/002.gif') }}" width="325" height="196" alt="原版示意图：角色能力值面板"><figcaption>原版示意图：角色能力值面板。</figcaption></figure>
<ul>
<li><b>经验(Exp)</b>：当前经验 / 升级所需经验。</li>
<li><b>最大生命(MaxHP)</b>：降到 0 则战斗不能。由职业、等级和体质决定。</li>
<li><b>最大魔力(MaxSP)</b>：使用技能时消耗。由职业、等级和智慧决定。</li>
<li><b>力量(Str)</b>：影响物理攻击力和地下城背包负重。</li>
<li><b>智慧(Int)</b>：影响魔力、魔法攻击力、回复量、行动模式的行数，以及在地下城中每次移动恢复的魔力。</li>
<li><b>敏捷(Dex)</b>：提高装备负重上限（可以装更重的装备）；猎人系部分攻击按敏捷计算；强化召唤物；在地下城中躲开和拆除陷阱。</li>
<li><b>速度(Spd)</b>：越高行动越频繁，行动间隔越短；在地下城中决定能否抢得先机或被伏击。</li>
<li><b>幸运(Luk)</b>：强化召唤物；在地下城中侦察未探索的房间、从宝箱多找到道具、让事件更容易有好结果。</li>
<li><b>体质(Vit)</b>：影响最大生命、体力上限与恢复速度，以及濒死时能坚持的步数。</li>
</ul>
<p class="meta">计算公式见 <a href="{{ route('catalog', 'rules') }}#vitals">数值规则</a>。</p>
</div>

<h2 class="sec" id="statup">能力值上升 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent prose">
<p>角色持续战斗获得经验值，<span class="u">升级</span>时会得到 {{ $c['stat_points'] }} 个属性点和 1 个技能点。<br>属性点可以在角色页自由分配到各项能力值上，单项能力值没有上限。</p>
<p>最高等级为 {{ $c['max_level'] }}。一次获得的经验最多升 1 级，多出的部分不保留。各等级所需经验见 <a href="{{ route('catalog', 'rules') }}#growth">经验表</a>。</p>
</div>

<h2 class="sec" id="jdg">人物在战斗中的命令 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<p>基本上，人物都是依据玩家设定的行动模式逐步行动的。</p>
<table class="tbl sample">
<thead><tr><th>No</th><th>判定</th><th>使用技能</th></tr></thead>
<tbody>
<tr><td>1</td><td>{{ $text->condition(1902, 1) }}</td><td><x-skill :line="array_replace($data->skillLine(3110), ['parts' => [], 'exp' => ''])" /></td></tr>
<tr><td>2</td><td>{{ $text->condition(1200, 50) }}</td><td><x-skill :line="array_replace($data->skillLine(1001), ['parts' => [], 'exp' => ''])" /></td></tr>
<tr><td>3</td><td>{{ $text->condition(1000) }}</td><td><x-skill :line="array_replace($data->skillLine(1000), ['parts' => [], 'exp' => ''])" /></td></tr>
</tbody></table>
<p>这是一个战士的设定例子。<br>
轮到角色行动时，从 <b>No</b> 1 开始依次检查<b>判定</b>，<br>
符合判定条件的话就执行该行的<b>技能</b>，不再看后面的行。</p>
<p>按上面的设定：<br>
第一次行动时使用「{{ $text->name('skills', 3110) }}」；<br>
第二次以后，魔力在 50% 以上的回合使用「{{ $text->name('skills', 1001) }}」；<br>
魔力降到 50% 以下则使用「{{ $text->name('skills', 1000) }}」。</p>
<p>判定中的 N（数值）在判定右侧填写。可以设定的行数根据<b>智慧</b>而增加（No 增加），等级 30 以上再加 1 行：</p>
<table class="tbl tbl-stack">
<thead><tr><th>智慧</th><th>行数</th><th>等级 30 以上</th></tr></thead>
<tbody>@foreach($rules->patternTable() as $row)<tr><td class="primary">{{ $row['from'] }}{{ $row['to'] === null ? ' 以上' : '–'.$row['to'] }}</td><td class="num" data-label="行数">{{ $row['rows'] }}</td><td class="num" data-label="Lv30+">{{ $row['rows30'] }}</td></tr>@endforeach</tbody>
</table>
<p>所有判定的种类见 <a href="{{ route('catalog', 'conditions') }}">游戏资料 · 行动条件</a>。只有已经学会的、可以主动使用的技能才能设为行动。没有任何一行满足时，这次行动什么也不做，所以最后一行通常设为“必定”。</p>
</div>

<h2 class="sec" id="posi">人物的位置关系及后卫保护 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<dl class="kv">
<dt>配置</dt><dd>前卫(Front) / 后卫(Back)</dd>
<dt>后卫保护方式</dt><dd>@foreach(__('hof.guards') as $label)<span class="badge">{{ $label }}</span>@endforeach</dd>
</dl>
<p>在角色页决定人物战斗时站在前卫还是后卫。<br>
设为前卫的角色，在敌方攻击我方后卫时，<br>
如果符合自己设定的保护方式，<br>
就会替后卫承受这次攻击。</p>
<p>生命只剩 1 的前卫不会保护。部分技能（标注“前卫无法保护”）可以直接攻击后卫；辅助技能不受保护影响。详细的判定方法见 <a href="{{ route('catalog', 'rules') }}#battle">数值规则 · 后卫保护</a>。</p>
</div>

<h2 class="sec" id="equip">人物装备 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<p>在角色页会显示当前装备及可以装备的物品。装备位置有<b>武器</b>、<b>盾</b>、<b>甲</b>、<b>道具</b>四个，能装备哪些种类由职业决定。</p>
<p>各装备都有 <span class="charge">重量</span>，角色有 <span class="charge">负重上限</span>，<br>
装备的重量合计不能超过负重上限。这是装备的限制设定。<br>
等级和敏捷上升的话，负重上限也会随之上升（5 + 等级 ÷ 10 + 敏捷 ÷ 5，均舍去小数）。地下城背包的负重另按力量计算。</p>
<ul class="item-list">
@foreach([1000, 1700, 5000] as $sample)<li><x-item :line="$data->itemLine($sample)" /></li>@endforeach
</ul>
<ul>
<li><span class="dmg">物理攻击</span> - 物理攻击力</li>
<li><span class="spdmg">魔法攻击</span> - 魔法攻击力（也影响回复量）</li>
<li><span class="recover">物理防御 a+b</span> - 物理伤害先减少 a%，再减去 b</li>
<li><span class="support">魔法防御 c+d</span> - 魔法伤害先减少 c%，再减去 d</li>
<li><span class="charge">重量</span> - 装备重量</li>
</ul>
<p>双手武器不能与盾同时装备。全部道具的数据见 <a href="{{ route('catalog', 'items') }}">游戏资料 · 道具</a>。</p>
</div>

<h2 class="sec" id="skill">人物技能 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<ul class="item-list">
@foreach([1000, 1001, 1002, 2300, 3000, 3110] as $sample)<li><x-skill :line="$data->skillLine($sample)" /></li>@endforeach
</ul>
<p class="meta">（图标）技能名称 / 对象 - 范围 / 消耗魔力 / 威力%×次数 / 属性 / …… /（准备 : 僵直）/ 说明</p>
<ul>
<li><b>对象</b> - 技能能影响到的对象。<br><span class="dmg">敌方</span> - 敌人；<span class="recover">友方</span> - 同伴；<span class="support">自己</span> - 使用者自身；<span class="charge">战场全体</span> - 敌人和同伴全体。</li>
<li><b>范围</b> - 从<span class="u">对象中</span>选择谁。<br><span class="recover">单体</span> - 选择一人；<span class="spdmg">随机多次</span> - 每次随机选择（可能重复）；<span class="charge">全体</span> - 对象全部人员。</li>
<li><b>消耗魔力</b> - 使用技能时消耗的魔力。不足的话会失败。</li>
<li><b>威力</b> - 技能的强弱。</li>
<li><b>次数</b> - 技能的执行次数。100% × 2 的话，总计有 200% 的威力。</li>
<li><b>（准备 : 僵直）</b><br>准备：发动技能前需要的时间（战报中显示为“开始准备”）。<br>僵直：发动技能后到下次行动前的额外时间。<br>数字越大时间越长。</li>
<li><b>其他</b><br><span class="spdmg">魔法</span> - 使用魔法的技能，威力和效果受智慧影响；<span class="charge">前卫无法保护</span> - 对方的前卫不能替后卫承受；<span class="support">优先选择后卫</span> - 后卫的人物优先成为对象。</li>
</ul>
<p>另外，升级之后可以获得技能点，<br>消耗一定的技能点就能习得新的技能。可以学习的技能由职业和已经学会的技能决定，见各职业的 <a href="{{ route('catalog', 'jobs') }}">技能树</a>。</p>
</div>

<h2 class="sec" id="elem">攻击属性 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<p>没有“火怕水”之类的属性相克设定。</p>
<p>只有<b>物理</b>和<b>魔法</b>两种属性：物理技能用物理攻击力对抗物理防御，魔法技能用魔法攻击力对抗魔法防御。</p>
</div>

<h2 class="sec" id="state">人物状态 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<ul class="indent">
<li><span class="recover">生存</span> - 生命在 1 以上的状态。</li>
<li><span class="dmg">战斗不能</span> - 生命为 0 的状态。可以被复活技能救起。</li>
<li><span class="spdmg">中毒</span> - 每次轮到自己行动时，按<span class="u">最大生命和等级</span>受到伤害，不会因此倒下。</li>
<li><span class="charge">蓄力 / 咏唱</span> - 正在准备需要“准备”时间的技能（物理为蓄力，魔法为咏唱）。</li>
</ul>

<h2 class="sec" id="jobchange">转职(职业转换) <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<p>满足转职条件后，会显示在角色页的最下面。转职时会卸下全部装备，已学会的技能保留。</p>
<table class="tbl tbl-stack">
<thead><tr><th>高级职业</th><th>转职前</th><th>等级</th></tr></thead>
<tbody>@foreach($data->jobs() as $job)@if($job['from'])<tr><td class="primary"><a href="{{ $job['href'] }}">{{ $job['name'] }}</a></td><td data-label="转职前">{{ $job['from']['job']['name'] }}</td><td class="num" data-label="等级">{{ $job['from']['level'] }} 以上</td></tr>@endif @endforeach</tbody>
</table>
</div>

<h2 class="sec" id="sacrier">狂战士(Sacrier)的攻击方式 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<div class="carpets"><x-carpet :unit="['name' => '狂战士（男）', 'img' => 'image/char/mon_100r.gif']" /><x-carpet :unit="['name' => '狂战士（女）', 'img' => 'image/char/mon_012.gif']" /></div>
<p>狂战士的大部分技能要消耗生命（最大生命的一定百分比）。<br>
人物处在<b class="u">后卫</b>时，生命消耗为平常的 <b class="u">2 倍</b>。</p>
</div>

<h2 class="sec" id="time">体力(Time) <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent prose">
<p>每名角色都有自己的体力。出战和打工会消耗参加角色的体力；角色在城镇中会随时间恢复体力，离线时也一样。体力越低，角色在战斗中造成的伤害、治疗量和行动速度越低。</p>
<p>体力上限为 {{ $c['stamina_base'] }} + 体质，在城镇中约 {{ $c['stamina_hours'] }} 小时恢复满（体质越高，每小时恢复越多）。在地下城中每次移动消耗 {{ $c['dungeon_move'] }}，每场战斗再消耗 {{ $c['dungeon_battle'] }}；共享首领每次消耗 {{ $c['boss_stamina'] }}。在商店打工可以用 {{ $c['work_stamina'] }} 体力换 {{ \App\Application\World\GameText::money($c['work_pay']) }}。模拟战和竞技场不消耗体力。</p>
</div>

<h2 class="sec" id="dungeon">地下城(Dungeon) <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent prose">
<p>冒险就是探索地下城。选择 1–{{ $c['party_max'] }} 名角色，从仓库挑选消耗品装入背包后进入。地下城由房间和通道组成，每次只能走到相邻的房间；没去过的房间只知道位置，不知道里面有什么。</p>
<p>房间可能是战斗、宝箱、陷阱、事件、休息处或出口。走到出口并选择离开即为通关，可以得到通关奖励；也可以随时撤离，但没有通关奖励。离开或撤离之前无法使用城镇的设施，但仍可以分配属性点、学习技能和调整行动模式。</p>
<p>战斗伤害会一直保留。角色 HP 归零会陷入<b>濒死</b>：之后每移动一步，坚持步数减 1（{{ $c['dying_steps'] }} + 体质 ÷ {{ $c['dying_vit'] }}），归零即<b>永久死亡</b>，其装备留在战利品中，由幸存的同伴带回。用恢复生命的道具、休息处、事件或复活技能可以把濒死的同伴救回，但本次探索中会处于重伤，再次倒下就会直接死亡。撤离或离开时，濒死的同伴会被带回城镇。没有能行动的同伴时队伍全灭，濒死的同伴也会死去，背包、战利品和这次找到的资金全部遗失；如果所有角色都已阵亡，可以免费招募一名新同伴重新出发。</p>
<p>经验和升级在每场战斗后立即生效；资金和道具先算作战利品，离开或撤离后才存入仓库。背包上限为出战成员负重之和，每人 {{ $c['carry_base'] }} + 力量 ÷ {{ $c['carry_str'] }}。食物恢复体力，治疗药恢复 HP，魔力药恢复 SP，战斗中不能使用道具。各项能力在地下城中的作用见 <a href="{{ route('catalog', 'rules') }}#attributes">数值规则</a>。</p>
</div>

<h2 class="sec" id="town">城镇(Town) <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<ul class="indent prose">
<li><b>商店(Shop)</b> - 买(Buy)、卖(Sell)道具；打工(Work)消耗体力换取金钱。</li>
<li><b>人才介绍所(Recruit)</b> - 雇佣新的同伴，队伍最多 {{ $c['party_max'] }} 人。初期可以雇佣的人物见 <a href="{{ route('manual', 'tutorial') }}#first">教学</a>。</li>
<li><b>锻冶屋(Smithy)</b> - 制作：用材料做出新装备，并随机获得附加能力。精炼：强化武器和防具，最高 +10，失败时装备损毁。</li>
<li><b>拍卖会场(Auction)</b> - 持有会员卡（{{ \App\Application\World\GameText::money($c['auction_card']) }}）后可以出品和出价。</li>
<li><b>竞技场(Colosseum)</b> - 见 <a href="#ranking">排行</a>。</li>
<li><b>广场</b> - 留言板，保留最新 {{ $c['board'] }} 条留言。</li>
</ul>
<p class="meta indent">费用和成功率见 <a href="{{ route('catalog', 'rules') }}#economy">数值规则</a>。</p>

<h2 class="sec" id="union">共享首领(Union) <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent prose">
<p>强大的首领与随从一起出现，所有玩家共同削减它的生命。首领的生命和魔力不公开。</p>
<p>每次挑战消耗 {{ $c['boss_stamina'] }} 体力，两次挑战至少间隔 {{ $c['boss_cooldown'] }} 分钟，出战角色的等级合计不能超过首领的限制。按造成的伤害获得经验，击倒首领的队伍还能得到击倒奖励和掉落。首领被击倒后，经过一段时间会重新出现。</p>
</div>

<h2 class="sec" id="ranking">排行 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<p>排行榜上：<br>
<img class="vcent" src="{{ asset('image/icon/crown01.png') }}" alt="" width="24" height="24">第一名 1 人<br>
<img class="vcent" src="{{ asset('image/icon/crown02.png') }}" alt="" width="24" height="24">第二名 2 人<br>
<img class="vcent" src="{{ asset('image/icon/crown03.png') }}" alt="" width="24" height="24">第三名 3 人<br>
第四名以下也是每阶 3 人<br>
……<br>
同一名次上可能有多个队伍。<br>
挑战时，会从比自己高一阶的队伍中随机选出一个进行对决，<br>
胜利的话就和对方互换位置。</p>
<p>竞技场的战斗不消耗体力，也没有奖励。行动达到 {{ $c['actions'] }} 次时比较存活的角色人数，多的一方获胜。获胜后 {{ $c['rank_win'] }} 秒可以再次挑战，失败或平局要等 {{ \App\Application\World\GameText::duration($c['rank_other']) }}；登记的队伍每 {{ $c['rank_team_hours'] }} 小时可以更换一次。</p>
</div>

<h2 class="sec" id="cr">使用的图像 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<p class="indent">Whitecat 様 - 武器与技能图标<br>
R ド 様 - 人物<br>
<span class="meta">沿用原版 Hall of Fame 的素材。</span></p>
</div>
@endsection
