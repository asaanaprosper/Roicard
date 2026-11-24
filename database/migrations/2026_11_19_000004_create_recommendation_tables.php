<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recommended_profile_id')->constrained('profiles')->cascadeOnDelete();
            // impression | connect | dismiss | not_relevant | accepted | declined
            $table->string('event_type', 30);
            $table->decimal('score', 8, 2)->nullable();
            $table->json('reason_codes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'created_at']);
            $table->index(['recommended_profile_id', 'created_at']);
            $table->index('event_type');
        });

        Schema::create('recommendation_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recommended_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('reason', 30)->default('dismissed');
            $table->dateTime('dismissed_at');
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->unique(['profile_id', 'recommended_profile_id'], 'rec_dismissals_pair_unique');
            $table->index('expires_at');
        });

        Schema::create('recommendation_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recommended_profile_id')->constrained('profiles')->cascadeOnDelete();
            // Stable category-pair key derived from the matched signals.
            $table->string('category_pair_key', 120);
            $table->decimal('penalty', 6, 2)->default(0);
            $table->timestamps();

            $table->unique(['profile_id', 'recommended_profile_id', 'category_pair_key'], 'rec_feedback_pair_key_unique');
            $table->index(['profile_id', 'recommended_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_feedback');
        Schema::dropIfExists('recommendation_dismissals');
        Schema::dropIfExists('recommendation_events');
    }
};
