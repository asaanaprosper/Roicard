<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $table) {
            $table->index(['member_id', 'type', 'created_at'], 'analytics_member_type_created_index');
            $table->index(['type', 'created_at'], 'analytics_type_created_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'payments_status_created_index');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'subscriptions_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $table) {
            $table->dropIndex('analytics_member_type_created_index');
            $table->dropIndex('analytics_type_created_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_status_created_index');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_status_created_index');
        });
    }
};
