<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireCharacter
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()->name === null || $request->user()->name === '' || ! $request->user()->characters()->exists()) {
            return redirect()->route('setup');
        }

        return $next($request);
    }
}
