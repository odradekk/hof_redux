<?php

namespace Tests\Feature\Player;

use App\Models\InventoryItem;
use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PostgresPlayerConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function player(): User
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires independent PostgreSQL connections.');
        }

        return User::create(['login' => 'concurrent', 'name' => 'concurrent', 'password' => 'password123']);
    }

    private function race(User $user, string $command, array $first, array $second, bool $sameKey = false): array
    {
        $connection = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $key = (string) Str::uuid();
        $workers = [];
        foreach ([$first, $second] as $i => $payload) {
            $arguments = [$user->id, $command, $sameKey ? $key : (string) Str::uuid(), $payload];
            $workers[] = new Process([PHP_BINARY, __DIR__.'/fixtures/command.php', base_path(), json_encode($arguments, JSON_THROW_ON_ERROR)], base_path(), $env, timeout: 30);
        }
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

    public function test_same_work_request_on_two_connections_is_paid_once(): void
    {
        $user = $this->player();
        $worker = DB::transaction(fn () => app(CharacterFactory::class)->create(User::lockForUpdate()->findOrFail($user->id), 1, 'Worker', 0));
        $results = $this->race($user, 'work', ['character_id' => $worker->id], ['character_id' => $worker->id], true);
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame($results[0]['result'], $results[1]['result']);
        $this->assertSame(10500, $user->fresh()->money);
        $this->assertSame(0, $worker->fresh()->stamina_units);
        $this->assertSame(1, DB::table('operations')->count());
    }

    public function test_parallel_recruitment_cannot_exceed_five_characters(): void
    {
        $user = $this->player();
        $user->forceFill(['money' => 100000])->save();
        DB::transaction(function () use ($user) {
            $locked = User::lockForUpdate()->findOrFail($user->id);
            for ($i = 0; $i < 4; $i++) {
                app(CharacterFactory::class)->create($locked, 1, 'Hero '.$i, 0);
            }
        });
        $data = ['base_type' => 1, 'name' => 'Recruit', 'gender' => 0];
        $results = $this->race($user, 'recruit', $data, $data);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['ok', 'rejected'], $statuses);
        $this->assertSame(5, $user->characters()->count());
        $this->assertSame(98000, $user->fresh()->money);
    }

    public function test_parallel_purchases_cannot_overdraw_funds(): void
    {
        $user = $this->player();
        $user->forceFill(['money' => 3000])->save();
        $data = ['items' => [['id' => 1002, 'quantity' => 1]]];
        $results = $this->race($user, 'buy', $data, $data);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['ok', 'rejected'], $statuses);
        $this->assertSame(0, $user->fresh()->money);
        $this->assertSame(1, $user->inventory()->sum('quantity'));
    }

    public function test_parallel_sales_cannot_sell_same_item_twice(): void
    {
        $user = $this->player();
        $item = InventoryItem::create(['user_id' => $user->id, 'item_id' => '1000', 'quantity' => 1, 'location' => 'warehouse']);
        $data = ['items' => [['id' => $item->id, 'quantity' => 1]]];
        $results = $this->race($user, 'sell', $data, $data);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['ok', 'rejected'], $statuses);
        $this->assertSame(10100, $user->fresh()->money);
        $this->assertSame(0, $user->inventory()->count());
    }

    public function test_parallel_crafting_cannot_duplicate_shared_materials(): void
    {
        $user = $this->player();
        InventoryItem::create(['user_id' => $user->id, 'item_id' => '6001', 'quantity' => 4, 'location' => 'warehouse']);
        $results = $this->race($user, 'craft', ['item_id' => 1000], ['item_id' => 1000]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['ok', 'rejected'], $statuses);
        $this->assertSame(1, $user->inventory()->where('item_id', '1000')->sum('quantity'));
        $this->assertSame(0, $user->inventory()->where('item_id', '6001')->count());
    }
}
