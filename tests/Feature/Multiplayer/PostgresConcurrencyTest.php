<?php

namespace Tests\Feature\Multiplayer;

use App\Application\Multiplayer\AuctionService;
use App\Application\Multiplayer\BossService;
use App\Application\Multiplayer\RankingService;
use App\Models\AuctionListing;
use App\Models\BossChallenge;
use App\Models\BossInstance;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\RankingChallenge;
use App\Models\RankingEntry;
use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PostgresConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function worker(string $command, array $payload): Process
    {
        $connection = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];

        return new Process([PHP_BINARY, __DIR__.'/fixtures/action.php', base_path(), $command, json_encode($payload, JSON_THROW_ON_ERROR)], base_path(), $env, timeout: 30);
    }

    private function player(string $login): User
    {
        $user = User::create(['login' => $login, 'name' => $login, 'password' => 'password']);
        $user->money = 100000;
        $user->save();
        InventoryItem::create(['user_id' => $user->id, 'item_id' => '9000', 'quantity' => 1, 'location' => 'warehouse']);

        return $user;
    }

    public function test_independent_bidders_and_settlers_conserve_money_and_items(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and independent PHP processes.');
        }
        $seller = $this->player('seller');
        $a = $this->player('bidder_a');
        $b = $this->player('bidder_b');
        $item = InventoryItem::create(['user_id' => $seller->id, 'item_id' => '1000', 'quantity' => 1, 'location' => 'warehouse']);
        $id = app(AuctionService::class)->exhibit($seller->id, (string) Str::uuid(), $item->id, 1, 100, 1)['auction_id'];
        $one = $this->worker('bid', [$a->id, (string) Str::uuid(), $id, 200]);
        $two = $this->worker('bid', [$b->id, (string) Str::uuid(), $id, 300]);
        DB::beginTransaction();
        DB::select('select pg_advisory_xact_lock(72641001)');
        $one->start();
        $two->start();
        usleep(150000);
        DB::commit();
        $one->wait();
        $two->wait();
        self::assertTrue($one->isSuccessful(), $one->getErrorOutput());
        self::assertTrue($two->isSuccessful(), $two->getErrorOutput());
        $results = [json_decode($one->getOutput(), true), json_decode($two->getOutput(), true)];
        self::assertContains('ok', array_column($results, 'status'));
        $listing = AuctionListing::findOrFail($id);
        self::assertSame(299500, User::sum('money') + $listing->escrow);
        self::assertSame(1, InventoryItem::where('item_id', '1000')->sum('quantity'));
        $listing->ends_at = now()->subSecond();
        $listing->save();
        $one = $this->worker('settle', []);
        $two = $this->worker('settle', []);
        $one->start();
        $two->start();
        $one->wait();
        $two->wait();
        self::assertTrue($one->isSuccessful(), $one->getErrorOutput());
        self::assertTrue($two->isSuccessful(), $two->getErrorOutput());
        $first = json_decode($one->getOutput(), true);
        $second = json_decode($two->getOutput(), true);
        self::assertNotSame($first['pid'], $second['pid']);
        self::assertSame(1, $first['result']['settled'] + $second['result']['settled']);
        self::assertSame('sold', $listing->fresh()->status);
        self::assertSame(299500, (int) User::sum('money'));
        self::assertSame(1, InventoryItem::where('item_id', '1000')->where('location', 'warehouse')->sum('quantity'));
    }

    public function test_same_request_on_two_connections_debits_once(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL.');
        }
        $seller = $this->player('seller');
        $buyer = $this->player('buyer');
        $item = InventoryItem::create(['user_id' => $seller->id, 'item_id' => '1000', 'quantity' => 1, 'location' => 'warehouse']);
        $id = app(AuctionService::class)->exhibit($seller->id, (string) Str::uuid(), $item->id, 1, 100, 1)['auction_id'];
        $payload = [$buyer->id, (string) Str::uuid(), $id, 200];
        $one = $this->worker('bid', $payload);
        $two = $this->worker('bid', $payload);
        $one->start();
        $two->start();
        $one->wait();
        $two->wait();
        self::assertTrue($one->isSuccessful(), $one->getErrorOutput());
        self::assertTrue($two->isSuccessful(), $two->getErrorOutput());
        $first = json_decode($one->getOutput(), true);
        $second = json_decode($two->getOutput(), true);
        self::assertSame('ok', $first['status']);
        self::assertSame('ok', $second['status']);
        self::assertNotSame($first['pid'], $second['pid']);
        self::assertSame($first['result'], $second['result']);
        self::assertSame(99800, $buyer->fresh()->money);
        self::assertSame(1, AuctionListing::findOrFail($id)->bid_count);
    }

    public function test_parallel_rank_challenges_preserve_unique_ladder_slots(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL.');
        }
        $users = [];
        $service = app(RankingService::class);
        foreach (['first', 'second', 'third'] as $name) {
            $user = $this->player($name);
            $character = app(CharacterFactory::class)->create($user, 1, $name, 0);
            $service->register($user->id, (string) Str::uuid(), [$character->id]);
            $users[] = $user;
        }
        $service->challenge($users[0]->id, (string) Str::uuid());
        $one = $this->worker('rank', [$users[1]->id, (string) Str::uuid()]);
        $two = $this->worker('rank', [$users[2]->id, (string) Str::uuid()]);
        $one->start();
        $two->start();
        $one->wait();
        $two->wait();
        self::assertTrue($one->isSuccessful(), $one->getErrorOutput());
        self::assertTrue($two->isSuccessful(), $two->getErrorOutput());
        self::assertSame('ok', json_decode($one->getOutput(), true)['status']);
        self::assertSame('ok', json_decode($two->getOutput(), true)['status']);
        self::assertSame([1, 2, 3], RankingEntry::orderBy('position')->pluck('position')->all());
        self::assertSame(2, RankingChallenge::count());
        self::assertSame(300000, (int) User::sum('money'));
    }

    public function test_parallel_attacks_on_one_hp_boss_award_one_kill_only(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL.');
        }
        $users = [];
        $parties = [];
        foreach (['first', 'second'] as $name) {
            $user = $this->player($name);
            $character = app(CharacterFactory::class)->create($user, 1, $name, 0);
            $stats = $character->stats;
            foreach (['str', 'int', 'dex', 'spd', 'luk'] as $stat) {
                $stats[$stat] = 255;
            }
            $character->stats = $stats;
            $character->level = 50;
            $character->tactics = [['judge' => 1000, 'quantity' => 0, 'action' => 1000]];
            $character->save();
            $users[] = $user;
            $parties[] = [$character->id];
        }
        app(BossService::class)->bootstrap();
        $boss = BossInstance::where('monster_id', '2000')->firstOrFail();
        $definition = $boss->definition;
        $definition['SlaveAmount'] = 0;
        $definition['SlaveSpecify'] = [];
        $definition['maxsp'] = 0;
        $definition['maxhp'] = 1;
        $definition['spd'] = 0;
        $definition['dex'] = 0;
        $definition['luk'] = 0;
        $definition['atk'] = [0, 0];
        $definition['def'] = [0, 0, 0, 0];
        $definition['judge'] = [1000];
        $definition['quantity'] = [0];
        $definition['action'] = [1000];
        $boss->definition = $definition;
        $boss->hp = 1;
        $boss->sp = 0;
        $boss->save();
        $one = $this->worker('boss', [$users[0]->id, (string) Str::uuid(), $boss->id, $parties[0]]);
        $two = $this->worker('boss', [$users[1]->id, (string) Str::uuid(), $boss->id, $parties[1]]);
        $one->start();
        $two->start();
        $one->wait();
        $two->wait();
        self::assertTrue($one->isSuccessful(), $one->getErrorOutput());
        self::assertTrue($two->isSuccessful(), $two->getErrorOutput());
        $statuses = [json_decode($one->getOutput(), true)['status'], json_decode($two->getOutput(), true)['status']];
        sort($statuses);
        self::assertSame(['ok', 'rejected'], $statuses);
        self::assertSame(0, $boss->fresh()->hp);
        self::assertSame(1, BossChallenge::where('killed', true)->count());
        self::assertSame(1, BossChallenge::count());
        // Only the winning challenger's character paid 10 of its 108 stamina (warrior, VIT 8).
        self::assertSame((2 * 108 - 10) * 86400, (int) Character::sum('stamina_units'));
    }
}
