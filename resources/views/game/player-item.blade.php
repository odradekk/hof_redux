<strong>{{ $data['name'] }}</strong> <span>{{ $data['type'] }}</span>
@if(!empty($data['img']))<img src="{{ asset('image/icon/'.$data['img']) }}" alt="" width="24" height="24">
@endif

<details @if($noJs ?? false) open 
@endif
><summary>道具详情</summary>
<dl>
@if(isset($data['atk']))<dt>Atk / Matk</dt><dd>{{ implode(' / ', $data['atk']) }}</dd>
@endif

@if(isset($data['def']))<dt>Def / Mdef</dt><dd>{{ implode(' / ', $data['def']) }}</dd>
@endif

@if(isset($data['handle']))<dt>负重</dt><dd>{{ $data['handle'] }}</dd>
@endif

@foreach(['P_MAXHP','M_MAXHP','P_MAXSP','M_MAXSP','P_STR','P_INT','P_DEX','P_SPD','P_LUK','P_SUMMON','P_PIERCE'] as $field)
@if(isset($data[$field]))<dt>{{ $field }}</dt><dd>{{ is_array($data[$field]) ? implode(' / ', $data[$field]) : $data[$field] }}</dd>
@endif


@endforeach

@if(!empty($data['option']))<dt>附加能力</dt><dd>{{ $data['option'] }}</dd>
@endif

@if(!empty($data['exp']))<dt>说明</dt><dd>{{ $data['exp'] }}</dd>
@endif

<dt>卖价</dt><dd>{{ number_format($data['sell_price']) }}</dd>
</dl></details>
