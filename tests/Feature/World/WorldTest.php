<?php

namespace Tests\Feature\World;

use App\Application\Battle\BattleService;
use App\Application\Community\AccountDeletion;
use App\Application\Community\CommunityService;
use App\Application\Dungeon\DungeonService;
use App\Application\Player\PlayerRules;
use App\Application\Player\PlayerService;
use App\Application\World\WorldService;
use App\Domain\Combat\RandomSource;
use App\Models\AuctionListing;
use App\Models\BattleReport;
use App\Models\BoardMessage;
use App\Models\DungeonRun;
use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WorldTest extends TestCase
{
    use RefreshDatabase;

    private function player(): array
    {
        $user = User::factory()->create(['name' => 'Team '.Str::random(8)]);
        $character = app(CharacterFactory::class)->create($user, 1, 'Hero', 0);

        return [$user, $character];
    }

    public function test_simulation_is_reward_free_and_private(): void
    {
        [$user,$char] = $this->player();
        $before = $char->fresh()->getAttributes();
        $money = $user->fresh()->money;
        $result = app(WorldService::class)->simulate($user->id, (string) Str::uuid(), [$char->id], false);
        $this->assertSame($before, $char->fresh()->getAttributes());
        $this->assertSame($money, $user->fresh()->money);
        $this->get('/reports/'.$result['report_id'])->assertNotFound();
        $this->actingAs($user)->get('/reports/'.$result['report_id'])->assertOk();
    }

    public function test_weighted_choice_excludes_zero_and_covers_boundaries(): void
    {
        $service = app(BattleService::class);
        foreach ([1 => 'a', 2 => 'a', 3 => 'b', 5 => 'b'] as $roll => $expected) {
            $random = new class($roll) implements RandomSource
            {
                public function __construct(private int $roll) {}

                public function integer(int $minimum, int $maximum): int
                {
                    return $this->roll;
                }

                public function state(): array
                {
                    return [];
                }
            };
            $this->assertSame($expected, $service->weighted(['zero' => [0, 1], 'a' => [2, 1], 'b' => [3, 1]], $random));
        }
    }

    public function test_board_retention_escaping_and_duplicate_post(): void
    {
        [$user] = $this->player();
        $service = app(CommunityService::class);
        for ($i = 0; $i < 51; $i++) {
            $service->post($user->id, (string) Str::uuid(), 'Message '.$i);
        }
        $key = (string) Str::uuid();
        $first = $service->post($user->id, $key, '<script>alert(1)</script>');
        $this->assertSame($first, $service->post($user->id, $key, '<script>alert(1)</script>'));
        $this->assertDatabaseCount('board_messages', 50);
        $this->assertFalse(BoardMessage::where('body', 'Message 0')->exists());
        $this->actingAs($user)->get('/town')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert', false);
    }

    public function test_admin_routes_require_administrator_and_public_pages_render(): void
    {
        [$user] = $this->player();
        $this->actingAs($user)->get('/admin')->assertForbidden();
        foreach (['/manual', '/manual/advanced', '/manual/tutorial', '/catalog/items', '/catalog/jobs', '/updates', '/reports', '/dungeons', '/simulation'] as $url) {
            $this->get($url)->assertOk();
        }
        $user->forceFill(['is_admin' => true])->save();
        $this->get('/admin')->assertOk();
        $this->get('/admin/users/'.$user->id)->assertOk();
    }

    public function test_boss_report_redacts_hidden_resources(): void
    {
        $report = ['mode' => 'boss', 'boss_hp' => 555, 'boss_sp' => 99, 'damage' => [100, 1], 'seed' => 42, 'teams' => [[], [['id' => 'boss', 'boss' => true, 'name' => 'Secret', 'hp' => 555, 'maxhp' => 1000, 'sp' => 99, 'maxsp' => 100, 'state' => 0]]], 'events' => [['type' => 'DamageApplied', 'target' => 'boss', 'hp' => 555, 'maxhp' => 1000, 'nested' => ['hp' => 555], 'amount' => 20]]];
        $safe = app(BattleService::class)->publicReport($report);
        $this->assertArrayNotHasKey('hp', $safe['teams'][1][0]);
        $this->assertArrayNotHasKey('boss_hp', $safe);
        $this->assertArrayNotHasKey('nested', $safe['events'][0]);
        $this->assertArrayNotHasKey('hp', $safe['events'][0]);
    }

    public function test_self_delete_removes_equipment_before_characters(): void
    {
        [$user] = $this->player();
        app(AccountDeletion::class)->delete($user->id);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseCount('characters', 0);
        $this->assertDatabaseCount('inventory_items', 0);
    }

    public function test_reward_batches_apply_growth_money_and_items_once(): void
    {
        [$user,$char] = $this->player();
        $char->stats = array_merge($char->stats, ['str' => 255, 'int' => 255, 'dex' => 255, 'spd' => 255, 'luk' => 255]);
        app(PlayerService::class)->refreshVitals($char);
        $char->stats = array_merge($char->stats, ['hp' => $char->stats['maxhp']]);
        $char->save();
        app(DungeonService::class)->enter($user->id, (string) Str::uuid(), 'goblin_trail', [$char->id], []);
        $key = (string) Str::uuid();
        $result = app(DungeonService::class)->move($user->id, $key, 'grass');
        $report = BattleReport::findOrFail($result['report_id'])->report;
        $this->assertSame(0, $report['winner']);
        $expected = clone $char;
        $expected->level = 1;
        $expected->xp = 0;
        $expected->stat_points = 0;
        $expected->skill_points = 0;
        foreach ($report['rewards'][0] as $reward) {
            if (in_array('player:'.$char->id, $reward['recipients'], true)) {
                PlayerRules::grantExperience($expected, $reward['experiencePerRecipient']);
            }
        }
        $this->assertSame($expected->xp, $char->fresh()->xp);
        $this->assertSame($expected->level, $char->fresh()->level);
        $this->assertGreaterThan(0, $report['settlement']['money']);
        // Dungeon money is held by the run until the party leaves.
        $this->assertSame(10000, $user->fresh()->money);
        $this->assertSame($report['settlement']['money'], DungeonRun::activeFor($user->id)->loot_money);
        $count = $user->inventory()->count();
        app(DungeonService::class)->move($user->id, $key, 'grass');
        $this->assertSame($count, $user->inventory()->count());
        $this->assertSame($report['settlement']['money'], DungeonRun::activeFor($user->id)->loot_money);
        $this->assertSame($expected->xp, $char->fresh()->xp);
    }

    public function test_administrator_balance_correction_and_moderation_are_audited(): void
    {
        [$user] = $this->player();
        [$admin] = $this->player();
        $admin->forceFill(['is_admin' => true])->save();
        $data = ['operation_id' => (string) Str::uuid(), 'money_delta' => 125, 'reason' => 'Verified correction'];
        $this->actingAs($admin)->post('/admin/users/'.$user->id, $data)->assertRedirect();
        $this->post('/admin/users/'.$user->id, $data)->assertRedirect();
        $this->assertSame(10125, $user->fresh()->money);
        $this->assertDatabaseHas('admin_audits', ['action' => 'account.balance', 'target' => (string) $user->id]);
        $this->post('/admin/announcements', ['operation_id' => (string) Str::uuid(), 'title' => 'Notice', 'body' => 'Welcome'])->assertRedirect();
        $this->get('/updates')->assertOk()->assertSee('Welcome');
        $this->actingAs($user)->post('/admin/announcements', ['operation_id' => (string) Str::uuid(), 'title' => 'Bad', 'body' => 'No'])->assertForbidden();
    }

    public function test_active_auction_blocks_account_deletion(): void
    {
        [$user] = $this->player();
        AuctionListing::create(['seller_id' => $user->id, 'item_snapshot' => ['item_id' => '1000'], 'price' => 100, 'status' => 'active', 'ends_at' => now()->addHour()]);
        try {
            app(AccountDeletion::class)->delete($user->id);
            $this->fail('Expected active-auction block');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_individual_mirror_uses_ten_actions_and_rejects_other_owners(): void
    {
        [$user,$character] = $this->player();
        [$other,$foreign] = $this->player();
        $this->actingAs($user)->post('/characters/'.$character->id.'/simulation', ['operation_id' => (string) Str::uuid()])->assertRedirect();
        $report = BattleReport::latest('id')->firstOrFail()->report;
        $this->assertLessThanOrEqual(10, $report['actions']);
        $this->post('/characters/'.$foreign->id.'/simulation', ['operation_id' => (string) Str::uuid()])->assertSessionHasErrors();
        $this->assertDatabaseCount('battle_reports', 1);
    }
}
