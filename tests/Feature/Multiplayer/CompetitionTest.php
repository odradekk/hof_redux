<?php

namespace Tests\Feature\Multiplayer;

use App\Application\Multiplayer\BossService;
use App\Application\Multiplayer\RankingService;
use App\Models\BossChallenge;
use App\Models\BossInstance;
use App\Models\RankingEntry;
use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CompetitionTest extends TestCase
{
    use RefreshDatabase;

    private function player(string $login): array
    {
        $user = User::create(['login' => $login, 'name' => $login, 'password' => 'password']);

        return [$user, app(CharacterFactory::class)->create($user, 1, $login.' hero', 0)];
    }

    public function test_rank_registration_boundary_first_entry_walkover_and_cooldown(): void
    {
        $this->freezeTime();
        [$a,$ca] = $this->player('one');
        [$b,$cb] = $this->player('two');
        $service = app(RankingService::class);
        $service->register($a->id, (string) Str::uuid(), [$ca->id]);
        $service->challenge($a->id, (string) Str::uuid());
        self::assertSame(1, RankingEntry::where('user_id', $a->id)->firstOrFail()->position);
        $service->register($b->id, (string) Str::uuid(), [$cb->id]);
        // A vanished defense character is a source-defined walkover, not a fake battle.
        $ca->equipment()->delete();
        $ca->delete();
        $result = $service->challenge($b->id, (string) Str::uuid());
        self::assertSame('defender_no_party', $result['result']);
        self::assertSame(1, $result['place']);
        self::assertSame(0, RankingEntry::where('user_id', $b->id)->firstOrFail()->wins);
        self::assertSame(1, RankingEntry::where('user_id', $a->id)->firstOrFail()->losses);
        try {
            $service->register($b->id, (string) Str::uuid(), [$cb->id]);
            self::fail('Early team reset allowed');
        } catch (ValidationException) {
        }
        $this->travel(48)->hours();
        $service->register($b->id, (string) Str::uuid(), [$cb->id]);
        self::assertSame(now()->getTimestamp(), RankingEntry::where('user_id', $b->id)->firstOrFail()->party_set_at->getTimestamp());
    }

    public function test_real_pvp_does_not_persist_injuries_or_award_assets(): void
    {
        [$a,$ca] = $this->player('one');
        [$b,$cb] = $this->player('two');
        $service = app(RankingService::class);
        $before = [$ca->fresh()->toArray(), $cb->fresh()->toArray()];
        $service->register($a->id, (string) Str::uuid(), [$ca->id]);
        $service->challenge($a->id, (string) Str::uuid());
        $service->register($b->id, (string) Str::uuid(), [$cb->id]);
        $key = (string) Str::uuid();
        $result = $service->challenge($b->id, $key);
        self::assertContains($result['result'], ['challenger_win', 'defender_win', 'draw']);
        self::assertSame($result, $service->challenge($b->id, $key));
        self::assertSame($before, [$ca->fresh()->toArray(), $cb->fresh()->toArray()]);
        self::assertSame(10000, $a->fresh()->money);
        self::assertSame(10000, $b->fresh()->money);
    }

    public function test_boss_bootstrap_and_exact_respawn_are_idempotent(): void
    {
        $this->freezeTime();
        $service = app(BossService::class);
        self::assertSame(12, $service->bootstrap());
        self::assertSame(0, $service->bootstrap());
        $boss = BossInstance::where('monster_id', '2000')->firstOrFail();
        $boss->hp = 0;
        $boss->sp = 0;
        $boss->defeated_at = now();
        $boss->respawns_at = now()->addHour();
        $boss->save();
        self::assertSame(0, $service->respawnDue());
        $this->travel(1)->hours();
        self::assertSame(1, $service->respawnDue());
        self::assertSame(0, $service->respawnDue());
        self::assertSame(2, $boss->fresh()->generation);
        self::assertSame($boss->fresh()->definition['maxhp'], $boss->fresh()->hp);
    }

    public function test_real_boss_challenge_is_retry_safe_and_enforces_cooldown(): void
    {
        [$user,$character] = $this->player('hero');
        $service = app(BossService::class);
        $service->bootstrap();
        $boss = BossInstance::where('monster_id', '2004')->firstOrFail();
        $key = (string) Str::uuid();
        $result = $service->challenge($user->id, $key, $boss->id, [$character->id]);
        self::assertSame($result, $service->challenge($user->id, $key, $boss->id, [$character->id]));
        self::assertSame(1, BossChallenge::count());
        self::assertSame(7776000, $character->fresh()->stamina_units);
        self::assertGreaterThanOrEqual(0, $boss->fresh()->hp);
        $this->expectException(ValidationException::class);
        $service->challenge($user->id, (string) Str::uuid(), $boss->id, [$character->id]);
    }

    public function test_boss_challenge_charges_every_member_or_nobody(): void
    {
        $this->freezeTime();
        [$user,$first] = $this->player('pair');
        $second = app(CharacterFactory::class)->create($user, 2, 'Second', 0);
        $service = app(BossService::class);
        $service->bootstrap();
        $boss = BossInstance::where('monster_id', '2004')->firstOrFail();
        $second->forceFill(['stamina_units' => 9 * 86400 + 86399, 'stamina_updated_at' => now()])->save();
        try {
            $service->challenge($user->id, (string) Str::uuid(), $boss->id, [$first->id, $second->id]);
            self::fail('A member below 10 stamina must block the challenge.');
        } catch (ValidationException) {
        }
        self::assertSame(8640000, $first->fresh()->stamina_units);
        self::assertSame(0, BossChallenge::count());
        $this->travel(1)->seconds();
        $service->challenge($user->id, (string) Str::uuid(), $boss->id, [$first->id, $second->id]);
        self::assertSame(90 * 86400, $first->fresh()->stamina_units);
        self::assertSame(499, $second->fresh()->stamina_units);
    }

    public function test_multiplayer_pages_render_and_hide_boss_resources(): void
    {
        [$user,$character] = $this->player('viewer');
        app(BossService::class)->bootstrap();
        $this->actingAs($user)->get('/auction')->assertOk()->assertSee('Auction');
        $this->actingAs($user)->get('/ranking')->assertOk()->assertSee('Ranking');
        $boss = BossInstance::where('monster_id', '2000')->firstOrFail();
        $this->actingAs($user)->get('/bosses')->assertOk()->assertSee('共享首领')->assertDontSee((string) $boss->hp);
    }
}
