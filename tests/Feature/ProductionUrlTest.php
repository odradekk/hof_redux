<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductionUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_https_proxy_uses_fixed_public_origin_without_trusting_forwarded_host(): void
    {
        config(['app.url' => 'https://hof.example.test']);
        $this->app->instance('env', 'production');
        (new AppServiceProvider($this->app))->boot();
        $this->withHeaders(['Host' => 'internal-nginx', 'X-Forwarded-Host' => 'attacker.invalid', 'X-Forwarded-Proto' => 'http'])
            ->get('/login')
            ->assertOk()
            ->assertSee('action="https://hof.example.test/login"', false)
            ->assertSee('href="https://hof.example.test/app.css"', false)
            ->assertDontSee('attacker.invalid')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
