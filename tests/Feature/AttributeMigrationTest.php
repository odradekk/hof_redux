<?php

namespace Tests\Feature;

use App\Domain\Character\Attributes;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AttributeMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private function character(): object
    {
        return DB::table('characters')->first();
    }

    public function test_existing_characters_are_refunded_and_refilled_both_ways(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        $user = User::factory()->create();
        // A level-20 wizard in the old shape: allocated STR/INT, full 100 stamina, wounded.
        DB::table('characters')->insert([
            'user_id' => $user->id, 'name' => 'Old', 'gender' => 0, 'base_type' => 2, 'job_id' => '200', 'level' => 20, 'xp' => 0,
            'stat_points' => 3, 'skill_points' => 0, 'skills' => '[1000]', 'tactics' => '[]', 'position' => 'back', 'guard_policy' => '{"mode":"never"}',
            'stats' => json_encode(['str' => 40, 'int' => 50, 'dex' => 5, 'spd' => 3, 'luk' => 1, 'hp' => 10, 'maxhp' => 300, 'sp' => 5, 'maxsp' => 200]),
            'stamina_units' => 100 * 86400, 'stamina_updated_at' => now(), 'health_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('migrate', ['--force' => true]);
        $row = $this->character();
        $stats = json_decode($row->stats, true);
        $this->assertSame(['str' => 2, 'int' => 10, 'dex' => 5, 'spd' => 3, 'luk' => 1, 'vit' => 3], array_intersect_key($stats, array_flip(Attributes::STATS)));
        $this->assertSame(5 * 19, (int) $row->stat_points);
        $this->assertSame([Attributes::maxHp(1.5, 20, 3), Attributes::maxSp(1, 20, 10)], [$stats['maxhp'], $stats['maxsp']]);
        $this->assertSame([$stats['maxhp'], $stats['maxsp']], [$stats['hp'], $stats['sp']]);
        $this->assertSame(103 * 86400, (int) $row->stamina_units);
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('dungeon_runs', 'members'));

        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        $row = $this->character();
        $stats = json_decode($row->stats, true);
        $this->assertArrayNotHasKey('vit', $stats);
        $this->assertSame(3 * 19, (int) $row->stat_points);
        $this->assertSame(100 * 86400, (int) $row->stamina_units);
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('dungeon_runs', 'members'));
        Artisan::call('migrate', ['--force' => true]);
    }
}
