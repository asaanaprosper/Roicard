<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_cards', function (Blueprint $table) {
            $table->foreignId('profile_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('activation_payment_id')->nullable()->after('profile_id')
                ->constrained('payments')->nullOnDelete();
            $table->timestamp('activated_at')->nullable()->after('assigned_at');
            $table->timestamp('deactivated_at')->nullable()->after('activated_at');
            // Only active bindings receive a key, so a profile can have one
            // live card while retaining historical unbound cards.
            $table->string('binding_key', 80)->nullable()->unique()->after('deactivated_at');

            $table->index(['profile_id', 'inventory_status']);
        });

        // Backfill existing active inventory cards to the holder's default
        // profile without overwriting an existing binding.
        $cards = DB::table('smart_cards')
            ->whereNotNull('user_id')
            ->where('inventory_status', 'active')
            ->orderBy('id')
            ->get(['id', 'user_id']);

        $used = [];
        foreach ($cards as $card) {
            $profileId = DB::table('profiles')
                ->where('user_id', $card->user_id)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');

            if (! $profileId) {
                continue;
            }

            $key = 'profile:' . $profileId;
            if (isset($used[$key])) {
                continue;
            }

            $used[$key] = true;
            DB::table('smart_cards')->where('id', $card->id)->update([
                'profile_id' => $profileId,
                'activated_at' => now(),
                'binding_key' => $key,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('smart_cards', function (Blueprint $table) {
            $table->dropForeign(['profile_id']);
            $table->dropForeign(['activation_payment_id']);
            $table->dropUnique(['binding_key']);
            $table->dropIndex(['profile_id', 'inventory_status']);
            $table->dropColumn([
                'profile_id',
                'activation_payment_id',
                'activated_at',
                'deactivated_at',
                'binding_key',
            ]);
        });
    }
};
