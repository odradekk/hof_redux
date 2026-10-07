<?php

namespace Tests\Feature\World;

use App\Application\Dungeon\DungeonService;
use App\Application\Player\PlayerService;
use App\Models\Character;
use App\Models\DungeonRun;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PostgresDungeonConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    /** Start both workers while holding the global gameplay lock, then release it. */
    private function race(array $first, array $second): array
    {
        $connection = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $workers = array_map(fn (array $call) => new Process([PHP_BINARY, __DIR__.'/fixtures/dungeon.php', base_path(), json_encode($call, JSON_THROW_ON_ERROR)], base_path(), $env, timeout: 30), [$first, $second]);
        DB::beginTransaction();
        DB::select('select pg_advisory_xact_lock(72641001)');
        foreach ($workers as $worker) {
            $worker->start();
        }
        usleep(150000);
        DB::commit();
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }
        $this->assertNotSame($results[0]['pid'], $results[1]['pid']);

        return $results;
    }

    public function test_parallel_entries_create_one_run_and_pack_items_once(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and independent PHP processes.');
        }
        $user = User::factory()->create();
        $hero = app(CharacterFactory::class)->create($user, 1, 'Hero', 0);
        $herbs = InventoryItem::create(['user_id' => $user->id, 'item_id' => '4100', 'quantity' => 6, 'location' => 'warehouse']);
        $call = fn () => ['enter', [$user->id, (string) Str::uuid(), 'goblin_trail', [$hero->id], [['id' => $herbs->id, 'quantity' => 4]]]];
        $statuses = array_column($this->race($call(), $call()), 'status');
        sort($statuses);
        $this->assertSame(['ok', 'rejected'], $statuses);
        $this->assertSame(1, DungeonRun::count());
        $this->assertSame(2, $herbs->fresh()->quantity);
        $this->assertSame(4, (int) InventoryItem::where('location', 'pack')->sum('quantity'));
    }

    public function test_parallel_moves_with_different_keys_resolve_one_after_the_other(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and independent PHP processes.');
        }
        $user = User::factory()->create();
        $hero = app(CharacterFactory::class)->create($user, 1, 'Hero', 0);
        // Strong enough that the single battle is certainly won.
        $hero->level = 40;
        $hero->stats = array_merge($hero->stats, ['str' => 255, 'int' => 255, 'spd' => 255, 'luk' => 255, 'vit' => 255]);
        app(PlayerService::class)->refreshVitals($hero);
        $hero->stats = array_merge($hero->stats, ['hp' => $hero->stats['maxhp']]);
        $hero->save();
        app(DungeonService::class)->enter($user->id, (string) Str::uuid(), 'goblin_trail', [$hero->id], []);
        // Both requests target "grass" from the entrance. The first moves and fights; the
        // second then starts in "grass", where "grass" is not adjacent, and is rejected.
        $call = fn () => ['move', [$user->id, (string) Str::uuid(), 'grass']];
        $results = $this->race($call(), $call());
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['ok', 'rejected'], $statuses);
        $this->assertSame(1, DungeonRun::firstOrFail()->steps);
        $this->assertSame('active', DungeonRun::firstOrFail()->status);
        // One move (2) and one battle (5) from the starting 108, never twice.
        $this->assertSame(101 * 86400, $hero->fresh()->stamina_units);
    }

    public function test_a_rescue_racing_the_last_step_settles_one_way_only(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and independent PHP processes.');
        }
        $user = User::factory()->create();
        $tank = app(CharacterFactory::class)->create($user, 1, 'Tank', 0);
        $fragile = app(CharacterFactory::class)->create($user, 1, 'Fragile', 0);
        $herbs = InventoryItem::create(['user_id' => $user->id, 'item_id' => '4100', 'quantity' => 1, 'location' => 'warehouse']);
        app(DungeonService::class)->enter($user->id, (string) Str::uuid(), 'goblin_trail', [$tank->id, $fragile->id], [['id' => $herbs->id, 'quantity' => 1]]);
        // The fragile member is dying with one step left; the next room holds no fight.
        $run = DungeonRun::firstOrFail();
        $run->members = [$fragile->id => ['dying' => 1]];
        $run->rooms = array_merge($run->rooms, ['grass' => ['visited' => true, 'cleared' => true]]);
        $run->save();
        $fragile->forceFill(['stats' => array_merge($fragile->stats, ['hp' => 0])])->save();
        $herb = InventoryItem::where('location', 'pack')->firstOrFail();
        $results = $this->race(['move', [$user->id, (string) Str::uuid(), 'grass']], ['act', [$user->id, (string) Str::uuid(), 'use', ['item' => $herb->id, 'character' => $fragile->id]]]);

        $this->assertSame('ok', $results[0]['status']);
        $member = $fragile->fresh() ?? Character::withoutGlobalScope('living')->findOrFail($fragile->id);
        if ($results[1]['status'] === 'ok') {
            // Rescued first: the herb is spent, then the move costs nothing more.
            $this->assertNull($member->died_at);
            $this->assertGreaterThan(0, $member->stats['hp']);
            $this->assertSame([$fragile->id => ['wounded' => true]], DungeonRun::firstOrFail()->members);
            $this->assertNull($herb->fresh());
        } else {
            // The step ran out first: the member is dead and the herb is untouched.
            $this->assertNotNull($member->died_at);
            $this->assertSame([], DungeonRun::firstOrFail()->members);
            $this->assertSame(1, $herb->fresh()->quantity);
        }
    }
}
