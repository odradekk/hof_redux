@if(session('status'))<p role="status" class="notice notice-ok">{{ __(session('status')) }}</p>@endif
@if($errors->any())<div role="alert" class="notice notice-err"><ul>@foreach($errors->messages() as $field => $messages)@foreach($messages as $message)<li>@if(isset($errorAnchors[$field]))<a href="#{{ $errorAnchors[$field] }}">{{ __($message) }}</a>@else{{ __($message) }}@endif</li>@endforeach
@endforeach</ul></div>@endif
