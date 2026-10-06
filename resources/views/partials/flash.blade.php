@if(session('status'))<p role="status" class="notice notice-ok">{{ __(session('status')) }}</p>@endif
@if($errorBags)
<div role="alert" class="notice notice-err"><ul>
    @foreach($errorBags as $bag => $errorsForBag)
        @foreach($errorsForBag->messages() as $field => $messages)
            @foreach($messages as $message)
                <li>@if(isset($errorAnchors[$bag][$field]))<a href="#{{ $errorAnchors[$bag][$field] }}">{{ __($message) }}</a>@else{{ __($message) }}@endif</li>
            @endforeach
        @endforeach
    @endforeach
</ul></div>
@endif
