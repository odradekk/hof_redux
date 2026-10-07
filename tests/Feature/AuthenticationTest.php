<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_and_login_page_has_security_headers(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertHeader('X-Frame-Options', 'DENY')->assertSee('name="_token"', false);
    }

    public function test_registration_hashes_password_and_separates_team_setup(): void
    {
        $this->post('/register', ['login' => 'Hero123', 'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password'])->assertRedirect('/setup');
        $user = User::firstOrFail();
        $this->assertSame('hero123', $user->login);
        $this->assertNull($user->name);
        $this->assertTrue(Hash::check('a-secure-password', $user->password));
        $this->assertSame(10000, $user->money);
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertRedirect('/setup');
    }

    public function test_login_uses_generic_error_and_successfully_authenticates(): void
    {
        $user = User::factory()->create(['login' => 'herotest']);
        $this->post('/login', ['login' => 'herotest', 'password' => 'incorrect'])->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->post('/login', ['login' => 'HEROTEST', 'password' => 'valid-password-123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_invalid_account_and_weak_password_are_rejected(): void
    {
        $this->post('/register', ['login' => '../x', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors(['login', 'password']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_rate_limit_is_enforced(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => 'missing', 'password' => 'incorrect']);
        }
        $this->post('/login', ['login' => 'missing', 'password' => 'incorrect'])->assertStatus(429);
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/account/password', ['current_password' => 'bad', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('valid-password-123', $user->fresh()->password));
        $this->post('/account/password', ['current_password' => 'valid-password-123', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_user_output_is_escaped(): void
    {
        $user = User::factory()->create(['name' => '<script>alert(1)</script>']);
        $this->actingAs($user)->get('/account')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_csrf_token_is_required_for_state_change(): void
    {
        // Laravel normally disables CSRF checks during tests; switch only the environment used by that bypass.
        $this->app->instance('env', 'production');
        $this->post('/login', ['login' => 'missing', 'password' => 'incorrect'])->assertStatus(419);
    }

    public function test_array_login_is_rejected_without_a_server_error(): void
    {
        $this->post('/login', ['login' => ['hero'], 'password' => 'invalid-password'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_nul_password_is_rejected_before_hashing(): void
    {
        $password = 'valid-password-'.chr(0);
        $this->post('/register', ['login' => 'heronull', 'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 0);
        $user = User::factory()->create();
        $this->actingAs($user)->post('/account/password', ['current_password' => 'valid-password-123', 'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('valid-password-123', $user->fresh()->password));
    }
}
