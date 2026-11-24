<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // membership | subscription | card_activation
            $table->string('purpose', 30)->default('membership')->after('status');
            $table->foreignId('plan_id')->nullable()->after('purpose')->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->after('plan_id')->constrained()->nullOnDelete();
            $table->foreignId('smart_card_id')->nullable()->after('subscription_id')->constrained('smart_cards')->nullOnDelete();
            $table->foreignId('profile_id')->nullable()->after('smart_card_id')->constrained()->nullOnDelete();
            $table->string('idempotency_key', 120)->nullable()->unique()->after('provider');
            $table->string('provider_event_id', 120)->nullable()->after('provider_reference');
            $table->timestamp('paid_at')->nullable()->after('provider_event_id');
            $table->json('metadata')->nullable()->after('paid_at');

            $table->index(['purpose', 'status']);
            $table->index(['user_id', 'purpose']);
            $table->index('provider_event_id');
        });

        DB::table('payments')->update(['purpose' => 'membership']);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropForeign(['subscription_id']);
            $table->dropForeign(['smart_card_id']);
            $table->dropForeign(['profile_id']);
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['purpose', 'status']);
            $table->dropIndex(['user_id', 'purpose']);
            $table->dropIndex(['provider_event_id']);
            $table->dropColumn([
                'purpose',
                'plan_id',
                'subscription_id',
                'smart_card_id',
                'profile_id',
                'idempotency_key',
                'provider_event_id',
                'paid_at',
                'metadata',
            ]);
        });
    }
};
