<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            // guest = existing public Connect flow; member = authenticated
            // member-to-member request.
            $table->string('request_type', 20)->default('guest')->after('status');
            $table->unsignedBigInteger('sender_profile_id')->nullable()->after('guest_user_id');
            $table->unsignedBigInteger('recipient_profile_id')->nullable()->after('sender_profile_id');
            $table->text('member_note')->nullable()->after('guest_intent');
            $table->text('match_reason')->nullable()->after('member_note');
            $table->json('match_reason_codes')->nullable()->after('match_reason');
            // Stable sorted account-pair key. Guest rows leave it null so
            // existing duplicate guest records are not broken.
            $table->string('pair_key', 60)->nullable()->unique()->after('match_reason_codes');
            $table->timestamp('responded_at')->nullable()->after('pair_key');
            $table->timestamp('withdrawn_at')->nullable()->after('responded_at');
            $table->timestamp('removed_at')->nullable()->after('withdrawn_at');

            $table->index(['member_id', 'status']);
            $table->index(['guest_user_id', 'status']);
            $table->index('sender_profile_id');
            $table->index('recipient_profile_id');
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('connections', function (Blueprint $table) {
                $table->foreign('sender_profile_id')->references('id')->on('profiles')->nullOnDelete();
                $table->foreign('recipient_profile_id')->references('id')->on('profiles')->nullOnDelete();
            });
        }

        // Backfill pair keys for existing guest connections where possible.
        // Duplicate legacy pairs keep the first key and remain readable.
        $used = [];
        $rows = DB::table('connections')
            ->whereNotNull('guest_user_id')
            ->orderBy('id')
            ->get(['id', 'member_id', 'guest_user_id']);

        foreach ($rows as $row) {
            $a = (int) $row->member_id;
            $b = (int) $row->guest_user_id;
            if ($a === $b) {
                continue;
            }
            $key = min($a, $b) . ':' . max($a, $b);
            if (isset($used[$key])) {
                continue;
            }
            $used[$key] = true;
            DB::table('connections')->where('id', $row->id)->update(['pair_key' => $key]);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('connections', function (Blueprint $table) {
                $table->dropForeign(['sender_profile_id']);
                $table->dropForeign(['recipient_profile_id']);
            });
        }

        Schema::table('connections', function (Blueprint $table) {
            $table->dropUnique(['pair_key']);
            $table->dropIndex(['member_id', 'status']);
            $table->dropIndex(['guest_user_id', 'status']);
            $table->dropIndex(['sender_profile_id']);
            $table->dropIndex(['recipient_profile_id']);
            $table->dropColumn([
                'request_type',
                'sender_profile_id',
                'recipient_profile_id',
                'member_note',
                'match_reason',
                'match_reason_codes',
                'pair_key',
                'responded_at',
                'withdrawn_at',
                'removed_at',
            ]);
        });
    }
};
