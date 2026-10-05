{{--
Props: None.
Slots: None.
Example: <form method="post"><x-op /></form>
--}}
@csrf
<input type="hidden" name="operation_id" value="{{ Illuminate\Support\Str::uuid() }}">
