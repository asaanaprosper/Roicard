<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_action_logs', function (Blueprint $table) {
            // Canonical actor/target pair for the MVP 2 audit log. The legacy
            // admin_id/target_user_id columns are retained so the existing
            // activity feed keeps working unchanged.
            $table->unsignedBigInteger('actor_id')->nullable()->after('id');
            $table->string('target_type', 100)->nullable()->after('target_user_id');
            $table->string('target_id', 100)->nullable()->after('target_type');

            $table->index('actor_id');
            $table->index(['action', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('admin_action_logs', function (Blueprint $table) {
                $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        // Backfill legacy rows so both representations agree. The target id
        // is written in PHP because CAST(... AS TEXT) is not portable to MySQL.
        DB::table('admin_action_logs')->whereNotNull('admin_id')->update([
            'actor_id' => DB::raw('admin_id'),
        ]);

        DB::table('admin_action_logs')
            ->whereNotNull('target_user_id')
            ->select(['id', 'target_user_id'])
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('admin_action_logs')->where('id', $row->id)->update([
                        'target_type' => 'User',
                        'target_id' => (string) $row->target_user_id,
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('admin_action_logs', function (Blueprint $table) {
                $table->dropForeign(['actor_id']);
            });
        }

        Schema::table('admin_action_logs', function (Blueprint $table) {
            $table->dropIndex(['actor_id']);
            $table->dropIndex(['action', 'created_at']);
            $table->dropIndex(['target_type', 'target_id']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['actor_id', 'target_type', 'target_id']);
        });
    }
};
