<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_listings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('bidder_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $t->json('item_snapshot');
            $t->bigInteger('price');
            $t->bigInteger('escrow')->default(0);
            $t->integer('bid_count')->default(0);
            $t->string('status', 16)->default('active');
            $t->string('comment', 200)->default('');
            $t->timestamp('ends_at')->index();
            $t->timestamp('settled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('auction_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('auction_listing_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind', 16);
            $t->bigInteger('amount')->default(0);
            $t->timestamps();
        });
        Schema::create('ranking_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->integer('position')->nullable()->unique();
            $t->json('party');
            $t->timestamp('party_set_at');
            $t->timestamp('challenge_at')->nullable();
            $t->integer('wins')->default(0);
            $t->integer('losses')->default(0);
            $t->integer('draws')->default(0);
            $t->integer('defenses')->default(0);
            $t->timestamps();
        });
        Schema::create('ranking_challenges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('challenger_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('defender_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('result', 32);
            $t->json('report')->nullable();
            $t->timestamps();
        });
        Schema::create('boss_instances', function (Blueprint $t) {
            $t->id();
            $t->string('monster_id', 32)->unique();
            $t->json('definition');
            $t->bigInteger('hp');
            $t->bigInteger('sp');
            $t->integer('generation')->default(1);
            $t->timestamp('defeated_at')->nullable();
            $t->timestamp('respawns_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('boss_challenges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boss_instance_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->integer('generation');
            $t->bigInteger('damage');
            $t->boolean('killed')->default(false);
            $t->json('report');
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
        // History retains the item reference; only live escrow must be exclusive.
        DB::statement("CREATE UNIQUE INDEX auction_active_inventory_unique ON auction_listings (inventory_item_id) WHERE status = 'active'");
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE auction_listings ADD CONSTRAINT auction_money_valid CHECK (price >= 0 AND escrow >= 0 AND bid_count >= 0), ADD CONSTRAINT auction_escrow_valid CHECK ((status = 'active' AND ((bidder_id IS NULL AND escrow = 0) OR (bidder_id IS NOT NULL AND escrow = price))) OR (status IN ('sold','unsold') AND escrow = 0))");
            DB::statement('ALTER TABLE boss_instances ADD CONSTRAINT boss_resources_valid CHECK (hp >= 0 AND sp >= 0)');
            DB::statement('CREATE UNIQUE INDEX boss_single_kill ON boss_challenges (boss_instance_id,generation) WHERE killed = true');
        }
    }

    public function down(): void
    {
        foreach (['boss_challenges', 'boss_instances', 'ranking_challenges', 'ranking_entries', 'auction_events', 'auction_listings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
