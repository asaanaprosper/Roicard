<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // month | year. NULL means recurring billing is not configured.
            $table->string('billing_interval', 20)->nullable()->after('currency');
            // Only plans explicitly marked by an administrator display the
            // Roicard verification mark.
            $table->boolean('verification_mark')->default(false)->after('billing_interval');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 32)->nullable()->unique()->after('plan_id');
            $table->unsignedBigInteger('referred_by_user_id')->nullable()->after('referral_code');
            $table->timestamp('referral_attributed_at')->nullable()->after('referred_by_user_id');
            $table->timestamp('referral_attribution_expires_at')->nullable()->after('referral_attributed_at');
            $table->boolean('referral_signature_enabled')->default(false)->after('referral_attribution_expires_at');
            $table->boolean('legacy_member')->default(false)->after('referral_signature_enabled');
            $table->timestamp('legacy_member_since')->nullable()->after('legacy_member');
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('referred_by_user_id')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        // Preserve historical paid members: they are explicitly tagged rather
        // than silently downgraded when Free-plan limits are configured later.
        $paidUserIds = DB::table('payments')
            ->where('status', 'success')
            ->whereNotNull('user_id')
            ->select('user_id')
            ->distinct()
            ->pluck('user_id')
            ->all();

        if ($paidUserIds !== []) {
            DB::table('users')
                ->whereIn('id', $paidUserIds)
                ->update([
                    'legacy_member' => true,
                    'legacy_member_since' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['referred_by_user_id']);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['referral_code']);
            $table->dropColumn([
                'referral_code',
                'referred_by_user_id',
                'referral_attributed_at',
                'referral_attribution_expires_at',
                'referral_signature_enabled',
                'legacy_member',
                'legacy_member_since',
            ]);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['billing_interval', 'verification_mark']);
        });
    }
};
