<?php

namespace Tests\Feature\World;

use App\Application\Battle\BattleService;
use App\Domain\Combat\SeededRandom;
use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

final class WorldBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function player(int $gender = 0): array
    {
        $user = User::factory()->create(['name' => 'Team '.random_int(10000, 99999)]);
        $character = app(CharacterFactory::class)->create($user, 1, 'Hero', $gender);

        return [$user, $character];
    }

    private function runSkill(int $skill, int $seed = 1, int $gender = 0): array
    {
        [$user,$character] = $this->player($gender);
        $service = app(BattleService::class);
        $unit = $service->party($user, [$character->id])[0];
        $unit['tactics'] = [['condition' => 1000, 'quantity' => 0, 'skill' => $skill]];
        $unit['maxsp'] = $unit['sp'] = 10000;
        $unit['spd'] = 255;
        if ($skill === 2057) {
            $unit['hp'] = (int) floor($unit['maxhp'] / 2);
        }
        $target = $unit;
        $target['id'] = 'opponent';
        $target['tactics'] = [];
        $target['spd'] = 0;
        $target['maxhp'] = $target['hp'] = 10000000;

        return (new \ReflectionMethod($service, 'run'))->invoke($service, [[$unit], [$target]], 'simulation', new SeededRandom($seed), $seed, ['Player', 'Enemy'], 10);
    }

    public function test_summons_without_position_roll_each_instance_and_are_seeded(): void
    {
        $report = $this->runSkill(5801, 42);
        $summoned = array_values(array_filter($report['events'], fn ($event) => $event['type'] === 'Summoned'));
        $positions = array_column(array_column($summoned, 'unit'), 'position');
        $this->assertGreaterThanOrEqual(5, count($positions));
        $this->assertContains('front', $positions);
        $this->assertContains('back', $positions);
        $again = $this->runSkill(5801, 42);
        $repeat = array_values(array_filter($again['events'], fn ($event) => $event['type'] === 'Summoned'));
        $this->assertSame($positions, array_column(array_column($repeat, 'unit'), 'position'));
    }

    public function test_fixed_summon_positions_are_preserved(): void
    {
        foreach ([5068 => 'front', 5069 => 'back'] as $skill => $position) {
            $events = array_filter($this->runSkill($skill)['events'], fn ($event) => $event['type'] === 'Summoned');
            $this->assertNotEmpty($events);
            foreach ($events as $event) {
                $this->assertSame($position, $event['unit']['position']);
            }
        }
    }

    public function test_female_metamorphosis_preserves_gender_in_snapshot(): void
    {
        $report = $this->runSkill(2057, 1, 1);
        $this->assertSame(1, $report['initial_teams'][0][0]['gender']);
        $events = array_values(array_filter($report['events'], fn ($event) => $event['type'] === 'AppearanceChanged'));
        $this->assertNotEmpty($events);
        $this->assertSame('mon_149r.gif', $events[0]['image']);
    }

    public function test_malformed_deletion_passwords_validate_without_deleting_accounts(): void
    {
        [$user] = $this->player();
        foreach ([['x'], str_repeat('x', 73), "bad\0password"] as $password) {
            $this->actingAs($user)->postJson('/account/delete', ['current_password' => $password, 'confirm' => 'DELETE'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
            $this->assertDatabaseHas('users', ['id' => $user->id]);
        }
        [$admin] = $this->player();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->postJson('/admin/users/'.$user->id.'/delete', ['current_password' => ['x'], 'confirm' => 'DELETE'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_catalog_rejects_array_query_and_invalid_page(): void
    {
        $this->getJson('/catalog/items?q[]=x')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/catalog/items?page[]=1')->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson('/catalog/items?page=-1')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_battle_view_uses_external_styles_and_whitelisted_background_classes(): void
    {
        view()->share('errors', new ViewErrorBag);
        $html = view('game.battle.show', ['report' => ['mode' => 'pve', 'background' => 'grass', 'teams' => [[['id' => 'hero', 'name' => 'Hero', 'state' => 1]], []], 'events' => []]])->render();
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringContainsString('battle.css', $html);
        $this->assertStringContainsString('battle-background-grass', $html);
        $this->assertStringContainsString('battle-unit-dead', $html);
        $bad = view('game.battle.show', ['report' => ['background' => 'evil" onmouseover="x', 'teams' => [[], []], 'events' => []]])->render();
        $this->assertStringContainsString('battle-background-grass', $bad);
        $this->assertStringNotContainsString('onmouseover', $bad);
    }
}
