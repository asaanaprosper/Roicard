<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_members', function (Blueprint $table) {
            // An organization membership is only publicly displayed once both
            // the organization has confirmed the affiliation and the member has
            // accepted it.
            $table->timestamp('affiliation_confirmed_at')->nullable()->after('joined_at');
            $table->timestamp('member_accepted_at')->nullable()->after('affiliation_confirmed_at');
            $table->foreignId('affiliation_confirmed_by')->nullable()->after('member_accepted_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['community_id', 'affiliation_confirmed_at', 'member_accepted_at'], 'community_members_affiliation_index');
        });
    }

    public function down(): void
    {
        Schema::table('community_members', function (Blueprint $table) {
            $table->dropForeign(['affiliation_confirmed_by']);
            $table->dropIndex('community_members_affiliation_index');
            $table->dropColumn([
                'affiliation_confirmed_at',
                'member_accepted_at',
                'affiliation_confirmed_by',
            ]);
        });
    }
};
