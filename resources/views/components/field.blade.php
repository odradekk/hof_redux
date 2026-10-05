{{--
Props: label, for (DOM ID), hint (optional), name (optional validation key).
Slots: Default: one labelled control.
Example: <x-field label="Name" for="name"><input name="name"></x-field>
--}}
@props(['label', 'for', 'hint' => null, 'name' => null])
@php
    preg_match('/<(?:input|select|textarea)\b[^>]*\bname=["\']([^"\']+)["\']/i', (string) $slot, $named);
    $errorKey = $name ?? (isset($named[1]) ? str_replace(['[', ']'], ['.', ''], $named[1]) : $for);
    $error = $errors->first($errorKey);
    $note = $error ?: $hint;
    $control = preg_replace_callback('/<(input|select|textarea)\b[^>]*>/i', function ($match) use ($for, $note, $error) {
        $tag = $match[0];
        if (!preg_match('/\bid\s*=/', $tag)) $tag = substr($tag, 0, -1).' id="'.e($for).'">';
        if ($note && !str_contains($tag, 'aria-describedby')) $tag = substr($tag, 0, -1).' aria-describedby="'.e($for).'-note">';
        if ($error && !str_contains($tag, 'aria-invalid')) $tag = substr($tag, 0, -1).' aria-invalid="true">';
        return $tag;
    }, (string) $slot, 1);
@endphp
<label for="{{ $for }}">{{ $label }}</label><div {{ $attributes }}>{!! $control !!}</div>
@if($note)<p id="{{ $for }}-note" @class(['field-note', 'error' => (bool) $error])>{{ __($note) }}</p>@endif
