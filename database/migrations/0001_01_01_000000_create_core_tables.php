<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('login', 16)->unique();
            $t->string('name', 40)->nullable()->unique();
            $t->string('password');
            $t->boolean('is_admin')->default(false);
            $t->rememberToken();
            $t->bigInteger('money')->default(10000);
            // One stamina is exactly 86400 units, regenerated at 500 units/second.
            $t->bigInteger('stamina_units')->default(8640000);
            $t->timestamp('stamina_updated_at');
            $t->json('preferences')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->timestamps();
        });
        Schema::create('characters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 40);
            $t->unsignedTinyInteger('gender')->default(0);
            $t->unsignedTinyInteger('base_type');
            $t->string('job_id', 32);
            $t->unsignedSmallInteger('level')->default(1);
            $t->bigInteger('xp')->default(0);
            $t->integer('stat_points')->default(0);
            $t->integer('skill_points')->default(0);
            $t->json('stats');
            $t->json('skills');
            $t->json('tactics');
            $t->json('tactics_memo')->nullable();
            $t->string('position', 8)->default('front');
            $t->json('guard_policy');
            $t->timestamps();
            $t->unique(['id', 'user_id']);
        });
        Schema::create('inventory_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('item_id', 64);
            $t->integer('quantity')->default(1);
            $t->unsignedTinyInteger('refinement')->default(0);
            $t->json('enchantments')->nullable();
            $t->string('location', 16)->default('backpack');
            $t->unsignedBigInteger('character_id')->nullable();
            $t->string('slot', 16)->nullable();
            $t->timestamps();
            $t->foreign(['character_id', 'user_id'])->references(['id', 'user_id'])->on('characters')->restrictOnDelete();
            $t->index(['user_id', 'location']);
        });
        Schema::create('operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('command', 64);
            $t->uuid('operation_id');
            $t->string('request_hash', 64);
            $t->json('result')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'command', 'operation_id']);
        });
        Schema::create('asset_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $t->string('kind', 32);
            $t->bigInteger('amount');
            $t->string('item_id', 64)->nullable();
            $t->string('reason', 128);
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
        Schema::create('cache', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('owner');
            $t->integer('expiration');
        });
        DB::statement("CREATE UNIQUE INDEX inventory_equipped_slot ON inventory_items (character_id, slot) WHERE location = 'equipped'");
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_money_nonnegative CHECK (money >= 0), ADD CONSTRAINT users_stamina_bounds CHECK (stamina_units BETWEEN 0 AND 8640000)');
            DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT inventory_quantity_positive CHECK (quantity > 0), ADD CONSTRAINT inventory_refinement_bounds CHECK (refinement BETWEEN 0 AND 10), ADD CONSTRAINT inventory_location_valid CHECK (location IN ('backpack','equipped','auction')), ADD CONSTRAINT inventory_equipment_valid CHECK ((location = 'equipped' AND character_id IS NOT NULL AND slot IS NOT NULL AND quantity = 1) OR (location <> 'equipped' AND character_id IS NULL AND slot IS NULL))");
            DB::statement('ALTER TABLE characters ADD CONSTRAINT characters_progress_valid CHECK (level BETWEEN 1 AND 50 AND xp >= 0 AND stat_points >= 0 AND skill_points >= 0), ADD CONSTRAINT characters_gender_valid CHECK (gender IN (0,1))');
        }
    }

    public function down(): void
    {
        foreach (['cache_locks', 'cache', 'sessions', 'asset_entries', 'operations', 'inventory_items', 'characters', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
