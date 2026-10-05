<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdministratorAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_user_cannot_be_promoted(): void
    {
        $this->artisan('admin:access', ['login' => 'missing', '--confirm' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('admin_audits', 0);
    }

    public function test_noninteractive_change_requires_explicit_confirmation(): void
    {
        $user = User::factory()->create();
        $this->artisan('admin:access', ['login' => $user->login, '--no-interaction' => true])->assertFailed();
        $this->assertFalse($user->fresh()->is_admin);
        $this->assertDatabaseCount('admin_audits', 0);
    }

    public function test_declining_interactive_confirmation_does_not_change_access(): void
    {
        $user = User::factory()->create();
        $this->artisan('admin:access', ['login' => $user->login])
            ->expectsConfirmation('Grant administrator access for account '.$user->login.'?', 'no')
            ->assertFailed();
        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_explicit_confirmation_promotes_and_revokes_with_console_audit(): void
    {
        $user = User::factory()->create();
        $this->artisan('admin:access', ['login' => strtoupper($user->login), '--confirm' => true, '--no-interaction' => true])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);
        $audit = DB::table('admin_audits')->first();
        $this->assertNull($audit->admin_id);
        $this->assertSame('console.admin.grant', $audit->action);
        $this->assertSame('operator-console', json_decode($audit->details, true)['actor']);
        $this->artisan('admin:access', ['login' => $user->login, '--confirm' => true])->assertSuccessful();
        $this->assertDatabaseCount('admin_audits', 1);
        $this->artisan('admin:access', ['login' => $user->login, '--revoke' => true, '--confirm' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_admin);
        $this->assertDatabaseHas('admin_audits', ['admin_id' => null, 'action' => 'console.admin.revoke', 'target' => (string) $user->id]);
    }

    public function test_registration_cannot_assign_administrator_access(): void
    {
        $this->post('/register', ['login' => 'normalhero', 'password' => 'valid-password-123', 'password_confirmation' => 'valid-password-123', 'is_admin' => true])->assertRedirect('/setup');
        $this->assertFalse(User::firstOrFail()->is_admin);
    }
}
