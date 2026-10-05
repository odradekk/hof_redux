{{--
Props: units (UnitCard arrays), selected (IDs), action (POST URL).
Slots: before, context, actions, controls; default: enemy preview.
Example: <x-sortie :units="$units" :action="$action" />
--}}
@props(['units', 'selected' => [], 'action'])
<section {{ $attributes }}>{{ $before ?? '' }}<form method="post" action="{{ $action }}"><x-op/>{{ $context ?? '' }}<x-unit-picker :units="$units" :selected="$selected"/>
<div class="actions actions-center">@isset($actions){{ $actions }}@else<button type="submit" class="btn btn-lg">战斗!</button><button type="reset" class="btn">重置</button><label class="check"><input type="checkbox" name="remember" value="1">保存此队伍</label>@endisset</div>{{ $controls ?? '' }}</form>{{ $slot }}</section>
