<!doctype html>
<html lang="zh-CN">
<head>@include('partials.head')</head>
<body id="top"><a class="skip-link" href="#contents">跳到正文</a>
<div class="frame"><header class="title"><a href="{{ $hud['authenticated'] ? route('home') : route('login') }}"><img src="{{ asset('image/title03.gif') }}" alt="荣誉圣殿 Hall of Fame" width="218" height="45"></a></header>
@include('partials.menu')
@include('partials.status')
@php
    $errorBags = array_filter($errors->getBags(), fn ($bag) => $bag->any());
    $errorAnchors = [];
    foreach ($errorBags as $bag => $messages) {
        $errorAnchors[$bag] = App\Http\View\FormErrors::anchors($__env->yieldContent('content'), $bag);
    }
@endphp
<main id="contents" class="contents">@include('partials.flash')@yield('content')</main>
@include('partials.foot')
</div></body></html>
