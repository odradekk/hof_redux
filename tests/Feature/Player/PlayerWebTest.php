<?php

namespace Tests\Feature\Player;

use App\Application\Battle\BattleService;
use App\Domain\Combat\BattleEngine;
use App\Domain\Combat\BattleSnapshot;
use App\Domain\Combat\SeededRandom;
use App\Domain\Content\ContentCatalog;
use Illuminate\Support\Str;

final class PlayerWebTest extends PlayerTestCase
{
    public function test_every_player_page_renders_and_escapes_names(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $character->name = '<script>x</script>';
        $character->save();
        $this->item($user, '1000');
        $this->actingAs($user);
        foreach (['/characters', '/characters/'.$character->id, '/inventory', '/shop', '/crafting', '/preferences'] as $path) {
            $response = $this->get($path)->assertOk();
            $response->assertDontSee('<script>x</script>', false);
        }
        $this->get('/characters')->assertSee('&lt;script&gt;x&lt;/script&gt;', false);
    }

    public function test_player_mutations_require_authentication_and_operation_id(): void
    {
        $this->post('/player/work')->assertRedirect('/login');
        $user = $this->player();
        $this->character($user);
        $this->actingAs($user)->post('/player/work')->assertSessionHasErrors('operation_id');
        $this->get('/player/work')->assertStatus(405);
        $key = (string) Str::uuid();
        $this->post('/player/work', ['operation_id' => $key])->assertRedirect('/shop');
        $this->post('/player/work', ['operation_id' => $key])->assertRedirect('/shop');
        $this->assertSame(10500, $user->fresh()->money);
    }

    public function test_other_players_character_is_not_readable_or_mutable(): void
    {
        $user = $this->player();
        $this->character($user);
        $other = $this->character($this->player('other'));
        $this->actingAs($user)->get('/characters/'.$other->id)->assertNotFound();
        $this->post('/player/position', ['operation_id' => (string) Str::uuid(), 'character_id' => $other->id, 'position' => 'back', 'guard' => 'never'])->assertNotFound();
    }

    public function test_buy_form_ignores_unselected_zero_rows_but_not_negative_quantities(): void
    {
        $user = $this->player();
        $this->character($user);
        $before = $user->inventory()->sum('quantity');
        $this->actingAs($user)->post('/player/buy', ['operation_id' => (string) Str::uuid(), 'items' => [['id' => 1700, 'quantity' => '0'], ['id' => 3000, 'quantity' => '1']]])->assertRedirect('/shop');
        $this->assertSame($before + 1, $user->inventory()->sum('quantity'));
        $this->post('/player/buy', ['operation_id' => (string) Str::uuid(), 'items' => [['id' => 1700, 'quantity' => '-1']]])->assertSessionHasErrors();
    }

    public function test_learned_continue_thinking_is_rendered_saved_and_executes_as_and(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $stats = $character->stats;
        $stats['int'] = 20;
        $character->forceFill(['level' => 5, 'skill_points' => 4, 'stats' => $stats])->save();
        $this->actingAs($user)->post('/player/learn', [
            'operation_id' => (string) Str::uuid(), 'character_id' => $character->id, 'skill_id' => 9000,
        ])->assertRedirect('/characters/'.$character->id);
        $this->assertContains(9000, $character->fresh()->skills);
        $this->assertSame(0, $character->fresh()->skill_points);
        $this->get('/characters/'.$character->id)->assertOk()->assertSee('value="9000"', false);

        foreach ([1000 => 1001, 1001 => 1000] as $condition => $expectedSkill) {
            $rows = [
                ['judge' => $condition, 'quantity' => 0, 'action' => 9000],
                ['judge' => 1000, 'quantity' => 0, 'action' => 1001],
                ['judge' => 1000, 'quantity' => 0, 'action' => 1000],
            ];
            $this->post('/player/tactics', [
                'operation_id' => (string) Str::uuid(), 'character_id' => $character->id, 'tactics' => $rows,
            ])->assertRedirect('/characters/'.$character->id)->assertSessionHasNoErrors();
            $this->assertSame($rows, $character->fresh()->tactics);
            $party = app(BattleService::class)->party($user, [$character->id]);
            $party[0]['spd'] = 255;
            $enemy = $party[0];
            $enemy['id'] = 'opponent';
            $enemy['spd'] = 0;
            $enemy['tactics'] = [['condition' => 1000, 'quantity' => 0, 'skill' => 1000]];
            $catalog = app(ContentCatalog::class);
            $outcome = (new BattleEngine($catalog->all('skills')))->simulate(
                new BattleSnapshot([$party, [$enemy]], 'simulation', $catalog->version(), maxActions: 1),
                new SeededRandom(123),
            );
            $used = array_values(array_filter($outcome->events, fn ($event) => $event['type'] === 'SkillUsed'));
            $this->assertSame($party[0]['id'], $used[0]['actor']);
            $this->assertSame($expectedSkill, $used[0]['skill']);
        }
    }

    public function test_unlearned_continue_thinking_and_passive_actions_are_rejected(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $character->forceFill(['skills' => [...$character->skills, 7000]])->save();
        foreach ([9000, 7000] as $skill) {
            $this->actingAs($user)->post('/player/tactics', [
                'operation_id' => (string) Str::uuid(), 'character_id' => $character->id,
                'tactics' => [['judge' => 1000, 'quantity' => 0, 'action' => $skill]],
            ])->assertSessionHasErrors();
        }
    }

    public function test_character_summary_includes_equipment_and_passive_totals(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $character->forceFill(['skills' => [...$character->skills, 7000]])->save();
        $weapon = $character->equipment()->where('slot', 'weapon')->firstOrFail();
        $weapon->enchantments = ['P00'];
        $weapon->save();
        $response = $this->actingAs($user)->get('/characters/'.$character->id)->assertOk();
        $response->assertViewHas('effective', function (array $effective) use ($character) {
            return $effective['maxhp'] === $character->stats['maxhp'] + 30
                && $effective['str'] === $character->stats['str'] + 1
                && $effective['atk'][0] > 0;
        });
        $response->assertSee('装备与被动技能合计')->assertSee('条件全部不满足时跳过本次行动');
        $this->assertSame($character->stats, $character->fresh()->stats);
    }
}
