<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // owner | manager | moderator | member
            $table->string('role', 20)->default('member');
            // pending | active | invited | declined | left
            $table->string('status', 20)->default('active');
            // open | request | invite | admin
            $table->string('join_method', 20)->default('open');

            $table->timestamp('joined_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            // One membership record per community/member pair. Rejoining
            // reuses the row (status transitions) instead of creating a
            // duplicate history record.
            $table->unique(['community_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['community_id', 'status']);
            $table->index('role');
            $table->index('ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_members');
    }
};
