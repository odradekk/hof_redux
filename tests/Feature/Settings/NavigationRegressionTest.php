<?php

namespace Tests\Feature\Settings;

use App\Http\View\Hud;
use App\Models\User;
use App\Services\CharacterFactory;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NavigationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_team_name_from_normal_setup_has_completed_player_navigation(): void
    {
        $this->post('/register', ['login' => 'zeroteam', 'password' => 'valid-password-123', 'password_confirmation' => 'valid-password-123'])->assertRedirect('/setup');
        $this->post('/setup', ['name' => '0', 'character_name' => 'Hero', 'base_type' => 1, 'gender' => 0])->assertRedirect('/');
        $user = User::firstOrFail();
        $this->assertSame('0', $user->name);
        $this->assertSame(1, $user->characters()->count());
        $this->actingAs($user)->get('/setup')->assertRedirect('/');
        $this->assertPlayerNavigation($this->get('/')->assertOk()->getContent());
    }

    public function test_paid_rename_to_zero_and_double_zero_keeps_navigation_and_charges_once(): void
    {
        // The balance fixture prepares the documented rename fee, not the name under test.
        $user = User::factory()->create(['name' => 'NormalTeam', 'money' => 210000]);
        app(CharacterFactory::class)->create($user, 1, 'Hero', 0);
        $this->actingAs($user);
        $this->assertPlayerNavigation($this->get('/')->assertOk()->getContent());
        foreach (['0', '00'] as $index => $name) {
            $data = ['name' => $name, 'operation_id' => (string) Str::uuid()];
            $this->from('/account')->post('/player/team-name', $data)->assertRedirect('/account')->assertSessionHasNoErrors();
            $this->post('/player/team-name', $data)->assertRedirect()->assertSessionHasNoErrors();
            $user->refresh();
            $this->assertSame($name, $user->name);
            $this->assertSame(210000 - 100000 * ($index + 1), $user->money);
            $this->actingAs($user);
            $this->assertPlayerNavigation($this->get('/')->assertOk()->getContent());
        }
    }

    public function test_only_missing_or_empty_team_names_have_setup_navigation(): void
    {
        foreach ([null, '', '0', '00', 'NormalTeam'] as $name) {
            $user = new User(['name' => $name]);
            $request = Request::create('/');
            $request->setUserResolver(fn () => $user);
            $hud = Hud::forRequest($request);
            $setup = $name === null || $name === '';
            $this->assertSame($setup, $hud['setup'], var_export($name, true));
            $this->assertCount($setup ? 0 : 6, $hud['menu']);
        }
    }

    private function assertPlayerNavigation(string $html): void
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);
        $this->assertSame(6, $xpath->query('//nav[@aria-label="主导航"]//a')->length);
        $this->assertSame(1, $xpath->query('//span[@class="status-team"]')->length);
        $this->assertStringNotContainsString('首次登录游戏，感谢您的加入！', $html);
    }
}
