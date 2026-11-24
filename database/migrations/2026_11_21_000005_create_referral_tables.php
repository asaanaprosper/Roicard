<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('referral_code', 32);
            $table->string('status', 20)->default('attributed');
            $table->string('source', 30)->nullable();
            $table->string('invitee_email')->nullable();
            $table->string('attribution_ip', 45)->nullable();
            $table->json('risk_flags')->nullable();
            $table->timestamp('attributed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['referrer_user_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('referral_code');
        });

        Schema::create('referral_reward_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_user_id')->nullable()->constrained('users')->nullOnDelete();
            // credit | void | payout_adjustment
            $table->string('entry_type', 30);
            // pending | available | voided | paid
            $table->string('status', 20);
            $table->decimal('amount', 12, 2)->nullable();
            // Signed amount applied to the spendable ledger balance.
            $table->decimal('delta_amount', 12, 2)->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['referral_id', 'status']);
            $table->index(['referrer_user_id', 'status']);
            $table->index(['referrer_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_reward_entries');
        Schema::dropIfExists('referrals');
    }
};
