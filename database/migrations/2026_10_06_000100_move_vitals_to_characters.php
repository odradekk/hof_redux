<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stamina moves from the account to each character, characters gain permanent death,
 * and the unlimited item store is renamed to the warehouse. "pack" holds items carried
 * into a dungeon and "loot" holds items found there until the run is settled.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';
        Schema::table('characters', function (Blueprint $t) {
            // One stamina point is exactly 86400 units, regenerated in town at 500 units/second.
            $t->bigInteger('stamina_units')->default(8640000);
            $t->timestamp('stamina_updated_at')->nullable();
            // Current HP/SP stay in stats; town recovery is measured from this instant.
            $t->timestamp('health_updated_at')->nullable();
            $t->timestamp('died_at')->nullable();
        });
        DB::table('characters')->update(['stamina_updated_at' => now(), 'health_updated_at' => now()]);
        if ($pgsql) {
            DB::statement('ALTER TABLE characters ALTER COLUMN stamina_updated_at SET NOT NULL, ALTER COLUMN health_updated_at SET NOT NULL');
            DB::statement('ALTER TABLE characters ADD CONSTRAINT characters_stamina_bounds CHECK (stamina_units BETWEEN 0 AND 8640000)');
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_stamina_bounds');
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['stamina_units', 'stamina_updated_at']);
        });

        if ($pgsql) {
            DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT inventory_location_valid');
        }
        DB::table('inventory_items')->where('location', 'backpack')->update(['location' => 'warehouse']);
        if ($pgsql) {
            DB::statement("ALTER TABLE inventory_items ALTER COLUMN location SET DEFAULT 'warehouse'");
            DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT inventory_location_valid CHECK (location IN ('warehouse','pack','loot','equipped','auction'))");
        }
    }

    public function down(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';
        if ($pgsql) {
            DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT inventory_location_valid');
        }
        // Dungeon-only locations have no pre-migration meaning; return them to the store.
        DB::table('inventory_items')->whereIn('location', ['warehouse', 'pack', 'loot'])->update(['location' => 'backpack']);
        if ($pgsql) {
            DB::statement("ALTER TABLE inventory_items ALTER COLUMN location SET DEFAULT 'backpack'");
            DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT inventory_location_valid CHECK (location IN ('backpack','equipped','auction'))");
        }
        Schema::table('users', function (Blueprint $t) {
            $t->bigInteger('stamina_units')->default(8640000);
            $t->timestamp('stamina_updated_at')->nullable();
        });
        DB::table('users')->update(['stamina_updated_at' => now()]);
        if ($pgsql) {
            DB::statement('ALTER TABLE users ALTER COLUMN stamina_updated_at SET NOT NULL');
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_stamina_bounds CHECK (stamina_units BETWEEN 0 AND 8640000)');
            DB::statement('ALTER TABLE characters DROP CONSTRAINT characters_stamina_bounds');
        }
        Schema::table('characters', function (Blueprint $t) {
            $t->dropColumn(['stamina_units', 'stamina_updated_at', 'health_updated_at', 'died_at']);
        });
    }
};
