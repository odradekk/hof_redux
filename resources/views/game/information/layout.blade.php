{{-- Shell for the manual and game data pages: loads the interim stylesheet in <head> and wraps content in .info. --}}
@extends('layouts.app')
@push('styles')<link rel="stylesheet" href="{{ asset('information.css') }}">@endpush
@section('content')<div class="info">@yield('info')</div>@endsection
