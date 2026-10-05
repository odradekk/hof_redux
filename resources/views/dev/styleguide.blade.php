@extends('layouts.app')
@section('title','界面样式指南')
@section('content')
<x-sec title="界面样式指南" as="h1" help="#components"><x-slot:aside>仅开发和测试环境</x-slot:aside></x-sec>
<p class="notice notice-ok" role="status">操作成功</p><p class="notice notice-err" role="alert">请检查输入</p>
<x-sec title="角色卡与选择卡" id="components"/><div class="carpets"><x-carpet :unit="$unit" href="#components"/><x-carpet :unit="$monster"/></div>
<x-unit-picker :units="[$unit,$monster]" :selected="[1]" name="demo"/><x-unit-picker :units="[$unit,$monster]" :selected="[2]" name="radio_demo" type="radio"/>
<x-sec title="道具与技能"/><p><x-item :line="$item"/></p><p><x-skill :line="['icon'=>'image/icon/skill_042.png','name'=>'攻击','sp'=>0,'learn'=>0]"/></p>
<x-sec title="表单控件"/><form class="form-grid" action="{{ route('dev.ui') }}" method="get"><x-op/><x-field label="角色名字" for="demo_name" hint="输入角色名字"><input class="input input-md" name="demo_name"></x-field><x-field label="选择" for="demo_select"><select class="select" name="demo_select"><option>前卫</option><option>后卫</option></select></x-field><x-field label="留言" for="demo_text"><textarea class="textarea" name="demo_text"></textarea></x-field><div class="full actions"><button type="button" class="btn">确定</button><button type="button" class="btn btn-lg">战斗!</button><button type="button" class="btn" disabled>不可用</button><button type="button" class="btn btn-danger">危险操作</button></div><div class="full"><x-confirm word="DELETE"/></div></form>
<x-sec title="键值、金额、时间与记录"/><x-kv :rows="[['label'=>'经验','value'=>'12/30'],['label'=>'生命','value'=>330,'plus'=>12]]"/><p><x-money :amount="123456"/> · <x-time at="2026-10-05T12:15:00Z"/> · <x-time at="2026-10-05T12:15:00Z" mode="full"/> · <x-time :at="now()->addHours(2)->toIso8601String()" mode="relative"/></p><x-feed :entries="[['who'=>'银翼骑士团','text'=>'冒险开始！','at'=>'2026-10-05T12:15:00Z','tone'=>'support']]"/>
<x-sec title="设施和出战"/><x-facility :npc="['img'=>'image/char/ori_002.gif','text'=>'欢迎光临ー']" :tabs="[['label'=>'买','href'=>'#components','active'=>true],['label'=>'卖','href'=>'#components']]">设施内容</x-facility>
<x-sortie :units="[$unit]" :selected="[1]" :action="route('dev.ui')"><x-slot:actions><button class="btn btn-lg" type="button">战斗!</button><button class="btn" type="reset">重置</button></x-slot:actions><p class="hint">这是外观示例，不发起战斗</p></x-sortie>
<x-sec title="表格与分页"/><table class="tbl tbl-stack"><thead><tr><th scope="col">道具</th><th scope="col">价格</th><th scope="col">数量</th></tr></thead><tbody><tr><td class="primary"><x-item :line="$item"/></td><td data-label="价格"><x-money :amount="500"/></td><td data-label="数量">2</td></tr></tbody></table>
{{ (new Illuminate\Pagination\LengthAwarePaginator([], 100, 10, 2, ['path'=>route('dev.ui')]))->links() }}
<details class="more"><summary>展开详情</summary><p>更多信息</p></details><details class="danger-zone"><summary>危险区域</summary><p>需要确认的操作</p></details>
<x-sec title="战场、状态、行动与结果"/><x-battle.segment :segment="$battle['segments'][0]" :total="1"/><x-battle.result :result="$battle['result']"/>
<ul class="feed"><x-battle.record :record="['at'=>'2026-10-05T12:15:00Z','href'=>'#components','actions'=>10,'result'=>'胜','participants'=>[['tone'=>'recover','name'=>'银翼骑士团','count'=>2,'average_level'=>2],['tone'=>'dmg','name'=>'哥布林','count'=>3,'average_level'=>1]]]"/></ul>
@endsection
