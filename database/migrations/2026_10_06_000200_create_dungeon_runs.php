<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dungeon_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('dungeon_id', 64);
            $t->string('content_version', 64);
            // Character IDs in party order; members stay listed after death.
            $t->json('party');
            $t->string('room', 32);
            $t->string('previous_room', 32)->nullable();
            // Per-room state: visited, cleared, remaining rest uses.
            $t->json('rooms');
            // Money found inside; items found inside are inventory rows in location 'loot'.
            $t->bigInteger('loot_money')->default(0);
            $t->string('status', 16)->default('active');
            $t->unsignedInteger('steps')->default(0);
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
        // At most one active run per account.
        DB::statement("CREATE UNIQUE INDEX dungeon_runs_one_active ON dungeon_runs (user_id) WHERE status = 'active'");
        Schema::create('dungeon_run_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dungeon_run_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('sequence');
            $t->string('type', 16);
            $t->string('room', 32);
            $t->string('text', 500);
            $t->foreignId('battle_report_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['dungeon_run_id', 'sequence']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE dungeon_runs ADD CONSTRAINT dungeon_runs_status_valid CHECK (status IN ('active','cleared','retreated','wiped')), ADD CONSTRAINT dungeon_runs_loot_nonnegative CHECK (loot_money >= 0), ADD CONSTRAINT dungeon_runs_ended CHECK ((status = 'active') = (ended_at IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dungeon_run_events');
        Schema::dropIfExists('dungeon_runs');
    }
};
