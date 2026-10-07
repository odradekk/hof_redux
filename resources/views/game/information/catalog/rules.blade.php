@extends('layouts.app')
@section('title', '游戏资料 · 数值规则')
@section('content')
@include('game.information.catalog.head')
<div class="doc">
@php($c = $constants)
@php($money = fn ($amount) => \App\Application\World\GameText::money($amount))
<h2 class="sec">数值规则</h2>
<ul class="toc">
<li><a href="#growth">等级与经验</a></li><li><a href="#vitals">生命与魔力</a></li><li><a href="#attributes">能力值与地下城</a></li><li><a href="#patterns">行动模式行数</a></li>
<li><a href="#capacity">负重</a></li><li><a href="#battle">战斗计算</a></li><li><a href="#stamina">体力</a></li>
<li><a href="#economy">商店、雇佣与打工</a></li><li><a href="#refine">精炼</a></li><li><a href="#auction">拍卖</a></li>
<li><a href="#arena">竞技场</a></li><li><a href="#boss">共享首领</a></li><li><a href="#other">其他</a></li>
</ul>

<h2 class="sec" id="growth">等级与经验</h2>
<ul class="indent prose">
<li>最高等级 {{ $c['max_level'] }}；单项能力值没有上限。</li>
<li>每升 1 级获得 {{ $c['stat_points'] }} 个属性点和 1 个技能点。</li>
<li>经验达到下表数值时升级，经验归零；一次获得的经验最多升 1 级，多出的部分不保留。</li>
<li>击倒怪物的经验由当时存活的角色平分（向上取整），倒下的角色得不到经验。</li>
</ul>
<ol class="exp-grid indent" aria-label="各等级升级所需经验">
@foreach($rules->experienceTable() as $row)<li><b class="meta">Lv.{{ $row['level'] }}</b><span class="num">{{ number_format($row['next']) }}</span></li>@endforeach
</ol>

<h2 class="sec" id="vitals">生命与魔力</h2>
<p class="indent">最大生命与最大魔力由职业系数、等级和能力值决定，加点、升级、转职和重置时重新计算：</p>
<pre class="formula">最大生命 = round(100 × 生命系数 × (1 + (等级 − 1) ÷ 49) × (100 + 体质) ÷ 100)
最大魔力 = round(100 × 魔力系数 × (1 + (等级 − 1) ÷ 49) × (100 + 智慧) ÷ 100)</pre>
<p class="indent">战斗时再加上装备与被动技能的加成：先乘以（1 + 百分比加成），再加固定加成。地下城战斗从当前的生命、魔力开始，损伤会保留；竞技场、模拟战和共享首领每场开始时全满。各职业系数见 <a href="{{ route('catalog', 'jobs') }}">职业</a>。</p>
<table class="tbl tbl-stack">
<thead><tr><th>雇佣</th><th>力量</th><th>智慧</th><th>敏捷</th><th>速度</th><th>幸运</th><th>体质</th><th>Lv.1 生命</th><th>Lv.1 魔力</th></tr></thead>
<tbody>@foreach($rules->startingAttributes() as $row)<tr><td class="primary"><a href="{{ route('catalog.entry', ['jobs', $row['job']]) }}">{{ $row['name'] }}</a></td>@foreach(['str' => '力量', 'int' => '智慧', 'dex' => '敏捷', 'spd' => '速度', 'luk' => '幸运', 'vit' => '体质'] as $stat => $label)<td class="num" data-label="{{ $label }}">{{ $row['stats'][$stat] }}</td>@endforeach<td class="num" data-label="Lv.1 生命">{{ $row['hp'] }}</td><td class="num" data-label="Lv.1 魔力">{{ $row['sp'] }}</td></tr>@endforeach</tbody>
<caption>各基础角色的初始能力值。</caption>
</table>

<h2 class="sec" id="attributes">能力值与地下城</h2>
<p class="indent">“队伍最高”只计算能行动的成员（不含濒死的同伴）。概率判定都在操作时一次决定，不会重新掷。</p>
<dl class="kv indent">
<dt>体力上限</dt><dd>{{ $c['stamina_base'] }} + 体质</dd>
<dt>濒死</dt><dd>坚持 {{ $c['dying_steps'] }} + floor(体质 ÷ {{ $c['dying_vit'] }}) 步，每移动一步减 1</dd>
<dt>移动恢复魔力</dt><dd>每次移动恢复 floor(floor(√智慧) ÷ 2)% 最大魔力</dd>
<dt>陷阱闪避</dt><dd>每人 floor(敏捷 ÷ {{ $c['dodge_dex'] }})%，最多 {{ $c['dodge_max'] }}%</dd>
<dt>拆除陷阱</dt><dd>队伍最高敏捷 floor(敏捷 ÷ {{ $c['disarm_dex'] }})%，最多 {{ $c['disarm_max'] }}%；拆除后无人受伤、也不消耗陷阱的体力</dd>
<dt>先手 / 伏击</dt><dd>进入战斗房间时比较双方的平均行动速度（√速度 + 5，计入疲劳）；一方达到另一方的 {{ $c['initiative_ratio'] }} 倍时，该方以行动值 {{ $c['initiative_progress'] }} 开战</dd>
<dt>侦察</dt><dd>房间第一次出现在迷雾边缘时，按队伍最高幸运 {{ $c['scout_base'] }} + floor(幸运 ÷ {{ $c['scout_luk'] }})%（最多 {{ $c['scout_max'] }}%）看出它的名称和类型</dd>
<dt>宝箱</dt><dd>队伍最高幸运 floor(幸运 ÷ {{ $c['chest_luk'] }})%（最多 {{ $c['chest_max'] }}%）多抽一件道具</dd>
<dt>事件</dt><dd>标记为好结果的选项结果，权重乘以 (100 + 队伍最高幸运) ÷ 100</dd>
</dl>

<h2 class="sec" id="patterns">行动模式行数</h2>
<p class="indent">可设定的行动模式行数由智慧决定，等级 30 以上再加 1 行。</p>
<table class="tbl tbl-stack">
<thead><tr><th>智慧</th><th>行数</th><th>等级 30 以上</th></tr></thead>
<tbody>@foreach($rules->patternTable() as $row)<tr><td class="primary">{{ $row['from'] }}{{ $row['to'] === null ? ' 以上' : '–'.$row['to'] }}</td><td class="num" data-label="行数">{{ $row['rows'] }}</td><td class="num" data-label="Lv30+">{{ $row['rows30'] }}</td></tr>@endforeach</tbody>
</table>

<h2 class="sec" id="capacity">负重</h2>
<pre class="formula">装备负重上限 = 5 + floor(等级 ÷ 10) + floor(敏捷 ÷ 5)
背包负重     = 每名出战成员 {{ $c['carry_base'] }} + floor(力量 ÷ {{ $c['carry_str'] }}) 之和</pre>
<p class="indent">已装备道具的重量合计不能超过装备负重上限。装备双手武器时会卸下盾。背包负重只限制带进地下城的消耗品。</p>

<h2 class="sec" id="battle">战斗计算</h2>
<h3 class="bold u">行动顺序</h3>
<p class="indent">所有存活者同时积累行动值，速度越快积累越快（每单位时间 √速度 + 5），先到 100 的人行动，同时到达时随机。行动后行动值归零，技能的“僵直”再从中扣除；“准备”大于 0 的技能先进入蓄力 / 咏唱，行动值再积累“准备”的量后才发动（发动前倒下则中断）。共享首领受到的延迟效果只有三分之一。</p>
<h3 class="bold u">伤害</h3>
<pre class="formula">基础 = (√能力 × 10 + 攻击力) × 威力% × 倍率
       能力：物理技能用力量（标注“敏捷”的用敏捷），魔法用智慧；攻击力用对应的物理 / 魔法攻击
伤害 = ceil(max(基础 × 10%, 基础 × (1 − 防御a ÷ 100) − 防御b + 无视防御伤害 × 威力%))
回复 = ceil((√智慧 × 10 + 魔法攻击) × 威力%)</pre>
<p class="indent">疲劳时“基础”与“回复”再乘以 (1 − 疲劳的伤害减少%)，行动值的积累速度乘以 (1 − 疲劳的速度减少%)，见 <a href="#stamina">体力</a>。“无视防御”的技能跳过防御一步。处于屏障状态的目标，下一次攻击的基础伤害变为 0，屏障消失；追加的无视防御伤害仍按公式计算。每次攻击至少造成经过屏障处理后的基础伤害的 10%。</p>
<h3 class="bold u">中毒</h3>
<p class="indent">中毒的角色每次轮到自己行动时受到 round(最大生命 × 10%) + ceil(等级 ÷ 2) 点伤害，不会因此倒下（最少剩 1）。共享首领改为 min(200, 当前生命的 1% × 50–150% 随机)。毒耐性按百分比降低中毒几率；没有毒耐性时必定中毒。</p>
<h3 class="bold u">后卫保护</h3>
<p class="indent">敌人对后卫使用单体或随机目标的攻击时，生命大于 1 的前卫按随机顺序检查自己的保护方式，第一个满足的人代替后卫承受。“概率”方式掷 1–100，小于 25 / 50 / 75 时保护；“生命”方式要求自己的生命比例高于 25% / 50% / 75%。标注“前卫无法保护”的技能和辅助技能不会被保护。</p>
<h3 class="bold u">结束与奖励</h3>
<ul class="indent prose">
<li>一方全员倒下时结束；行动次数达到 {{ $c['actions'] }} 次时也结束（模拟战 {{ $c['simulation_actions'] }} 次）。普通战斗判为平局；竞技场比较存活的角色人数（不含召唤物），多的一方获胜，相同为平局。</li>
<li>击倒怪物时，经验、金钱和掉落归对方队伍。复活后再次被击倒的怪物只给一半经验，不再给金钱和道具。</li>
<li>每只怪物最多掉落 1 件道具，各道具的掉落率见怪物页。</li>
<li>模拟战和竞技场不获得任何奖励，也不改变角色。</li>
</ul>

<h2 class="sec" id="stamina">体力</h2>
<dl class="kv indent">
<dt>上限</dt><dd>{{ $c['stamina_base'] }} + 体质</dd>
<dt>恢复</dt><dd>在城镇中每天恢复上限的 {{ $c['stamina_refills'] }} 倍（约 {{ $c['stamina_hours'] }} 小时回满），离线时也会恢复；在地下城中不会自然恢复</dd>
<dt>HP / SP</dt><dd>地下城中的损伤会保留；在城镇中每小时恢复上限的 {{ $c['health_hour'] }}%，在地下城中不会自然恢复（每次移动按智慧恢复少量 SP）</dd>
<dt>疲劳</dt><dd>按体力占上限的比例：@foreach($rules->fatigueTable() as $tier)体力 {{ $tier['label'] }}：伤害与治疗 -{{ $tier['output'] }}%，行动速度 -{{ $tier['speed'] }}%@if(! $loop->last)；@endif @endforeach。只影响地下城战斗和共享首领</dd>
<dt>地下城</dt><dd>每次移动每名能行动的成员 {{ $c['dungeon_move'] }}，每场战斗再 {{ $c['dungeon_battle'] }}；宝箱、陷阱和事件按房间而定。体力不足时降到 0 为止，不会阻止行动；濒死的成员不消耗体力</dd>
<dt>共享首领</dt><dd>每次 {{ $c['boss_stamina'] }}</dd>
<dt>打工</dt><dd>{{ $c['work_stamina'] }} 体力换 {{ $money($c['work_pay']) }}</dd>
<dt>模拟战、竞技场</dt><dd>不消耗</dd>
</dl>

<h2 class="sec" id="economy">商店、雇佣与打工</h2>
<ul class="indent prose">
<li>商店出售 {{ count($data->shop()) }} 种道具，价格为道具的买价；卖出价格为买价的 1/5（材料等另有设定的除外）。精炼过或带附魔的道具也按基础卖价收购。</li>
<li>队伍最多 {{ $c['party_max'] }} 名角色，每次出战选择 1–{{ $c['party_max'] }} 名。解雇角色时，其装备回到仓库。</li>
</ul>
<table class="tbl tbl-stack">
<thead><tr><th>雇佣</th><th>费用</th></tr></thead>
<tbody>@foreach($rules->recruits() as $recruit)<tr><td class="primary"><a href="{{ route('catalog.entry', ['jobs', $recruit['job']]) }}">{{ $recruit['name'] }}</a>@if($recruit['name_female'] !== $recruit['name']) / {{ $recruit['name_female'] }}@endif</td><td class="num" data-label="费用">{{ $money($recruit['price']) }}</td></tr>@endforeach</tbody>
</table>

<h2 class="sec" id="refine">精炼</h2>
<p class="indent">可精炼的类型：{{ implode('、', $c['refinable']) }}。每次尝试收取道具买价的一半，最高 +10。失败时道具损毁。一次最多连续尝试 10 次，资金不足时停止。</p>
<table class="tbl tbl-stack">
<thead><tr><th>精炼</th><th>成功率</th><th>攻击力</th><th>防御</th></tr></thead>
<tbody>@foreach($rules->refineTable() as $row)<tr><td class="primary">+{{ $row['from'] }} → +{{ $row['to'] }}</td><td class="num" data-label="成功率">{{ $row['chance'] }}%</td><td class="num" data-label="攻击力">+{{ $row['atk'] }}</td><td class="num" data-label="防御">+{{ $row['def'] }}</td></tr>@endforeach</tbody>
<caption>攻击力、防御为精炼到该等级后相对原值的提升，向上取整。</caption>
</table>
<p class="indent">制作的配方、费用与附魔规则见各道具页和 <a href="{{ route('catalog', 'enchants') }}">附魔</a>。</p>

<h2 class="sec" id="auction">拍卖</h2>
<dl class="kv indent">
<dt>会员卡</dt><dd>{{ $money($c['auction_card']) }}，持有后才能出品和出价</dd>
<dt>出品费</dt><dd>每次 {{ $money($c['auction_fee']) }}；同一人两次出品至少间隔 {{ $c['auction_interval'] }} 秒</dd>
<dt>出品时间</dt><dd>{{ implode(' / ', $c['auction_hours']) }} 小时</dd>
<dt>同时出品</dt><dd>全服最多 {{ $c['auction_max'] }} 件</dd>
<dt>最低出价</dt><dd>当前价 + max(100, 当前价 ÷ 10)</dd>
<dt>延长</dt><dd>结束前 {{ $c['auction_extend'] }} 分钟内有人出价时，结束时间延长到出价后 {{ $c['auction_extend'] }} 分钟</dd>
<dt>托管</dt><dd>出价金额立即扣除并托管；被超过时全额退还。结束时成交金额交给出品者，无人出价则道具退回。</dd>
<dt>可出品类型</dt><dd>{{ implode('、', $c['auction_types']) }}</dd>
</dl>

<h2 class="sec" id="arena">竞技场</h2>
<ul class="indent prose">
<li>登记 1–{{ $c['party_max'] }} 名角色作为竞技场队伍；更换登记阵容需间隔 {{ $c['rank_team_hours'] }} 小时。</li>
<li>名次分阶：@foreach($rules->rankTiers() as $tier => $positions)第 {{ $tier }} 阶 {{ implode('、', $positions) }} 位；@endforeach之后每 3 个名次一阶。</li>
<li>挑战时从上一阶中随机选出一名对手。获胜则互换名次，{{ $c['rank_win'] }} 秒后可以再次挑战；失败或平局需等待 {{ \App\Application\World\GameText::duration($c['rank_other']) }}。</li>
<li>首次挑战时排在最后一名之后；没有人登记时，第一个挑战者直接成为第 1 名。</li>
<li>第 1 名不能挑战，防守成功会记录在案。</li>
</ul>

<h2 class="sec" id="boss">共享首领</h2>
<ul class="indent prose">
<li>每次挑战消耗 {{ $c['boss_stamina'] }} 体力，两次挑战至少间隔 {{ $c['boss_cooldown'] }} 分钟；出战角色的等级合计不能超过该首领的限制。</li>
<li>首领的生命由所有玩家共同削减，生命与魔力不公开。首领身边会随机出现随从。</li>
<li>每次挑战按造成的伤害获得经验；击倒首领的队伍再获得击倒奖励与掉落。被击倒后经过复活时间重新出现。</li>
<li>各首领的等级限制、随从与复活时间见 <a href="{{ route('catalog', 'monsters') }}">怪物</a> 的“共享首领”一节。</li>
</ul>

<h2 class="sec" id="other">其他</h2>
<dl class="kv indent">
<dt>队伍改名</dt><dd>{{ $money($c['rename_team']) }}</dd>
<dt>角色改名</dt><dd>消耗道具 <a href="{{ route('catalog.entry', ['items', 7500]) }}">{{ $data->text()->name('items', 7500) }}</a></dd>
@foreach($rules->resetItems() as $reset)<dt><a href="{{ route('catalog.entry', ['items', $reset['id']]) }}">{{ $data->text()->name('items', $reset['id']) }}</a></dt><dd>重置{{ $reset['effect'] }}</dd>@endforeach
<dt>广场留言</dt><dd>保留最新 {{ $c['board'] }} 条，每条 1–200 字</dd>
</dl>
</div>
@endsection
