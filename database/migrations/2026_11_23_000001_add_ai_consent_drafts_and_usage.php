<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Explicit AI consent. A version lets a future consent-text change
            // require re-consent without deleting the historical timestamp.
            $table->timestamp('ai_consent_at')->nullable()->after('setup_card_dismissed_at');
            $table->string('ai_consent_version', 40)->nullable()->after('ai_consent_at');
        });

        Schema::table('profiles', function (Blueprint $table) {
            // Proposed business/catalog content stays separate from the live
            // profile until the owner approves each item.
            $table->json('ai_catalog_draft')->nullable()->after('ai_approved_fields');
        });

        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 60);
            $table->string('provider', 40)->default('gemini');
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->string('outcome', 40);
            $table->string('error_code', 80)->nullable();
            $table->decimal('estimated_cost_usd', 12, 6)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('ai_catalog_draft');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ai_consent_at', 'ai_consent_version']);
        });
    }
};
