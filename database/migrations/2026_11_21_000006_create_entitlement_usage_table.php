<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlement_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('limit_key', 60);
            // e.g. 2026-11-21 for daily keys.
            $table->string('period_key', 30);
            $table->unsignedInteger('used')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'limit_key', 'period_key']);
            $table->index(['limit_key', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_usage');
    }
};
