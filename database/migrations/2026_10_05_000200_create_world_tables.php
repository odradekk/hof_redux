<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battle_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('mode', 16);
            $t->boolean('public')->default(true);
            $t->json('report');
            $t->timestamps();
            $t->index(['mode', 'public', 'created_at']);
        });
        Schema::create('board_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('author_name', 40);
            $t->string('body', 200);
            $t->timestamps();
        });
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->string('title', 120);
            $t->text('body');
            $t->boolean('published')->default(true);
            $t->timestamps();
        });
        Schema::create('admin_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 64);
            $t->string('target', 80)->nullable();
            $t->json('details');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['admin_audits', 'announcements', 'board_messages', 'battle_reports'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
