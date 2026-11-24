<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            // Pricing remains founder-confirmed data, not a code constant.
            // Both columns are nullable until pricing is approved.
            $table->decimal('price', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index('is_default');
        });

        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            // One of App\Support\PlanLimitKey::all().
            $table->string('limit_key', 60);
            // NULL means unlimited. Zero means the feature is disabled.
            $table->integer('limit_value')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'limit_key']);
            $table->index('limit_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('plans');
    }
};
