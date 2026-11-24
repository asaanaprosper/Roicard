<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // event | group | organization
            $table->string('type', 20)->default('group');
            // open | request | invite
            $table->string('join_method', 20)->default('open');
            // active | inactive | archived
            $table->string('status', 20)->default('active');

            // Organization communities can carry a seat cap in addition to
            // the plan-level ORG_MEMBER_SEATS entitlement.
            $table->string('organisation_name')->nullable();
            $table->unsignedInteger('member_seat_limit')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['owner_user_id', 'status']);
            $table->index(['join_method', 'status']);
            $table->index('starts_at');
            $table->index('ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communities');
    }
};
