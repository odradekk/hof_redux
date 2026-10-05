<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DatabaseInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_equipped_items_cannot_cross_character_ownership(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $character = DB::transaction(fn () => app(CharacterFactory::class)->create($owner, 1, 'Owned', 0));
        $this->expectException(QueryException::class);
        $other->inventory()->create(['item_id' => '1000', 'location' => 'equipped', 'character_id' => $character->id, 'slot' => 'item']);
    }

    public function test_equipped_slot_cannot_have_two_items(): void
    {
        $owner = User::factory()->create();
        $character = DB::transaction(fn () => app(CharacterFactory::class)->create($owner, 1, 'Owned', 0));
        $this->expectException(QueryException::class);
        $owner->inventory()->create(['item_id' => '1000', 'location' => 'equipped', 'character_id' => $character->id, 'slot' => 'weapon']);
    }

    public function test_postgresql_refuses_negative_currency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Production constraint is tested on PostgreSQL.');
        }
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $user->id)->update(['money' => -1]);
    }

    public function test_postgresql_refuses_nonpositive_inventory(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Production constraint is tested on PostgreSQL.');
        }
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        $user->inventory()->create(['item_id' => '1000', 'quantity' => 0]);
    }
}
