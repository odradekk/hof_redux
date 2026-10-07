<?php

use App\Domain\Content\ContentCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attribute redesign (docs/rewrite/attribute-design.md): a sixth attribute, vitality; five
 * points per level with no per-attribute cap; stamina capacity set by vitality; dying and
 * wounded members in dungeon runs. Old allocations are not kept: every character returns to
 * its base attributes with all points refunded, and HP, SP and stamina are refilled.
 *
 * The numbers are written out here so the migration keeps its meaning if the rules change.
 */
return new class extends Migration
{
    private const STATS = ['str', 'int', 'dex', 'spd', 'luk'];

    private const STARTING_VIT = [1 => 8, 2 => 3, 3 => 4, 4 => 5];

    private const UNIT = 86400;

    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';
        if ($pgsql) {
            // Relaxed before the refill below can exceed 100 points. The maximum depends on
            // vitality and is enforced by the application.
            DB::statement('ALTER TABLE characters DROP CONSTRAINT characters_stamina_bounds');
            DB::statement('ALTER TABLE characters ADD CONSTRAINT characters_stamina_bounds CHECK (stamina_units >= 0)');
        }
        $catalog = app(ContentCatalog::class);
        $now = now();
        foreach (DB::table('characters')->orderBy('id')->get() as $row) {
            $base = $catalog->get('base_characters', $row->base_type);
            $job = $catalog->get('jobs', $row->job_id);
            $stats = json_decode($row->stats, true, 512, JSON_THROW_ON_ERROR);
            foreach (self::STATS as $stat) {
                $stats[$stat] = (int) $base[$stat];
            }
            $stats['vit'] = self::STARTING_VIT[$row->base_type];
            $level = 1 + ($row->level - 1) / 49;
            $stats['maxhp'] = (int) round(100 * $job['coe'][0] * $level * (100 + $stats['vit']) / 100);
            $stats['maxsp'] = (int) round(100 * $job['coe'][1] * $level * (100 + $stats['int']) / 100);
            $alive = $row->died_at === null;
            $stats['hp'] = $alive ? $stats['maxhp'] : 0;
            $stats['sp'] = $alive ? $stats['maxsp'] : 0;
            DB::table('characters')->where('id', $row->id)->update([
                'stats' => json_encode($stats, JSON_THROW_ON_ERROR), 'stat_points' => 5 * ($row->level - 1),
                'stamina_units' => (100 + $stats['vit']) * self::UNIT, 'stamina_updated_at' => $now, 'health_updated_at' => $now,
            ]);
        }
        Schema::table('dungeon_runs', function (Blueprint $t) {
            // Per-member run state keyed by character ID: {"dying": moves left} or {"wounded": true}.
            $t->json('members')->nullable();
        });
    }

    public function down(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';
        Schema::table('dungeon_runs', function (Blueprint $t) {
            $t->dropColumn('members');
        });
        $catalog = app(ContentCatalog::class);
        foreach (DB::table('characters')->orderBy('id')->get() as $row) {
            $base = $catalog->get('base_characters', $row->base_type);
            $job = $catalog->get('jobs', $row->job_id);
            $stats = json_decode($row->stats, true, 512, JSON_THROW_ON_ERROR);
            unset($stats['vit']);
            foreach (self::STATS as $stat) {
                $stats[$stat] = (int) $base[$stat];
            }
            $level = 1 + ($row->level - 1) / 49;
            foreach (['hp' => ['str', 0], 'sp' => ['int', 1]] as $resource => [$attribute, $coefficient]) {
                $stats['max'.$resource] = (int) round(100 * $job['coe'][$coefficient] * $level * (1 + (255 ** 2 - (255 - $stats[$attribute]) ** 2) / (255 ** 2)));
                $stats[$resource] = min($stats['max'.$resource], (int) ($stats[$resource] ?? 0));
            }
            DB::table('characters')->where('id', $row->id)->update([
                'stats' => json_encode($stats, JSON_THROW_ON_ERROR), 'stat_points' => 3 * ($row->level - 1),
                'stamina_units' => min((int) $row->stamina_units, 100 * self::UNIT),
            ]);
        }
        if ($pgsql) {
            DB::statement('ALTER TABLE characters DROP CONSTRAINT characters_stamina_bounds');
            DB::statement('ALTER TABLE characters ADD CONSTRAINT characters_stamina_bounds CHECK (stamina_units BETWEEN 0 AND 8640000)');
        }
    }
};
