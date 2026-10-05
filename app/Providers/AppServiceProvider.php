<?php

namespace App\Providers;

use App\Domain\Content\ContentCatalog;
use App\Http\View\Hud;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContentCatalog::class, fn () => new ContentCatalog(base_path('content')));
    }

    public function boot(): void
    {
        Paginator::defaultView('vendor.pagination.hof');
        Paginator::defaultSimpleView('vendor.pagination.hof');
        View::composer('layouts.app', function ($view) {
            $view->with('hud', Hud::forRequest(request()))
                ->with('assetVersion', config('hof_ui.asset_version') ?: filemtime(public_path('css/hof.css')));
        });
        // Production URLs use the operator-configured origin, never untrusted forwarding headers.
        if ($this->app->environment('production')) {
            $origin = config('app.url');
            $scheme = parse_url($origin, PHP_URL_SCHEME);
            if (! in_array($scheme, ['http', 'https'], true) || ! parse_url($origin, PHP_URL_HOST)) {
                throw new \LogicException('APP_URL must be an absolute HTTP or HTTPS URL.');
            }
            URL::forceRootUrl($origin);
            URL::forceScheme($scheme);
        }
        RateLimiter::for('authentication', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by(strtolower(is_string($request->input('login')) ? $request->input('login') : '').'|'.$request->ip()),
        ]);
    }
}
