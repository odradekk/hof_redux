<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\CharacterFactory;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AccountErrorScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrong_delete_password_only_marks_and_links_the_delete_form(): void
    {
        $user = $this->player();
        $this->from('/account')->post('/account/delete', ['current_password' => 'wrong-password', 'confirm' => 'DELETE'])->assertRedirect('/account');
        $xpath = $this->settings();
        $this->assertSummaryTargets($xpath, ['delete-password']);
        $this->assertInvalid($xpath, 'delete-password', true);
        $this->assertInvalid($xpath, 'current_password', false);
        $this->assertSame(1, $xpath->query('//details[contains(@class,"danger-zone")][@open]')->length);
        $this->assertAccountUnchanged($user);
    }

    public function test_wrong_change_password_only_marks_and_links_the_password_form(): void
    {
        $user = $this->player();
        $this->from('/account')->post('/account/password', ['current_password' => 'wrong-password', 'password' => 'new-valid-password', 'password_confirmation' => 'new-valid-password'])->assertRedirect('/account')->assertSessionHasErrors('current_password');
        $xpath = $this->settings();
        $this->assertSummaryTargets($xpath, ['current_password']);
        $this->assertInvalid($xpath, 'current_password', true);
        $this->assertInvalid($xpath, 'delete-password', false);
        $this->assertSame(0, $xpath->query('//details[contains(@class,"danger-zone")][@open]')->length);
        $this->assertAccountUnchanged($user);
    }

    public function test_wrong_delete_confirmation_only_marks_and_links_its_confirmation_control(): void
    {
        $user = $this->player();
        $this->from('/account')->post('/account/delete', ['current_password' => 'valid-password-123', 'confirm' => 'WRONG'])->assertRedirect('/account');
        $xpath = $this->settings();
        $this->assertSummaryTargets($xpath, ['delete-confirm']);
        $this->assertInvalid($xpath, 'delete-confirm', true);
        $this->assertInvalid($xpath, 'delete-password', false);
        $this->assertInvalid($xpath, 'current_password', false);
        $this->assertAccountUnchanged($user);
    }

    public function test_valid_password_change_and_account_delete_still_work(): void
    {
        $user = $this->player();
        $this->from('/account')->post('/account/password', ['current_password' => 'valid-password-123', 'password' => 'new-valid-password', 'password_confirmation' => 'new-valid-password'])->assertRedirect('/account')->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-valid-password', $user->fresh()->password));
        $xpath = $this->settings();
        $this->assertSame(0, $xpath->query('//*[@aria-invalid="true"]')->length);
        $this->assertSame(0, $xpath->query('//*[@role="alert"]')->length);
        $this->post('/account/delete', ['current_password' => 'new-valid-password', 'confirm' => 'DELETE'])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('characters', ['user_id' => $user->id]);
    }

    public function test_new_password_confirmation_error_stays_in_the_password_form(): void
    {
        $user = $this->player();
        $this->from('/account')->post('/account/password', ['current_password' => 'valid-password-123', 'password' => 'new-valid-password', 'password_confirmation' => 'another-password'])->assertRedirect('/account')->assertSessionHasErrors('password');
        $xpath = $this->settings();
        $this->assertSummaryTargets($xpath, ['password']);
        $this->assertInvalid($xpath, 'password', true);
        $this->assertInvalid($xpath, 'delete-password', false);
        $this->assertAccountUnchanged($user);
    }

    private function player(): User
    {
        $user = User::factory()->create();
        app(CharacterFactory::class)->create($user, 1, 'Hero', 0);
        $this->actingAs($user);

        return $user;
    }

    private function settings(): DOMXPath
    {
        $html = $this->get('/account')->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    private function assertSummaryTargets(DOMXPath $xpath, array $ids): void
    {
        $actual = [];
        foreach ($xpath->query('//*[@role="alert"]//a') as $link) {
            $actual[] = substr($link->getAttribute('href'), 1);
        }
        $this->assertSame($ids, $actual);
        foreach ($ids as $id) {
            $this->assertSame(1, $xpath->query('//*[@id="'.$id.'"]')->length);
        }
    }

    private function assertInvalid(DOMXPath $xpath, string $id, bool $invalid): void
    {
        $control = $xpath->query('//*[@id="'.$id.'"]')->item(0);
        $this->assertNotNull($control);
        $this->assertSame($invalid, $control->getAttribute('aria-invalid') === 'true', $id);
        if ($invalid) {
            $note = $control->getAttribute('aria-describedby');
            $this->assertSame($id.'-note', $note);
            $this->assertSame(1, $xpath->query('//*[@id="'.$note.'"]')->length);
        }
    }

    private function assertAccountUnchanged(User $user): void
    {
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertSame(1, $user->characters()->count());
        $this->assertTrue(Hash::check('valid-password-123', $user->fresh()->password));
    }
}
