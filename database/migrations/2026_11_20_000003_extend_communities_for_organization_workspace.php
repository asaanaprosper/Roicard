<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->string('industry')->nullable()->after('organisation_name');
            $table->string('website_url')->nullable()->after('industry');
            $table->string('location')->nullable()->after('website_url');
            $table->json('services')->nullable()->after('location');
            $table->text('seeking')->nullable()->after('services');
            $table->text('offering')->nullable()->after('seeking');

            // unverified | pending | verified | rejected | suspended
            $table->string('verification_status', 20)->default('unverified')->after('status');
            $table->timestamp('approved_at')->nullable()->after('verification_status');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->text('rejected_reason')->nullable()->after('approved_by');
            $table->timestamp('suspended_at')->nullable()->after('rejected_reason');

            $table->index(['type', 'verification_status', 'status'], 'communities_org_discovery_index');
            $table->index('industry');
            $table->index('location');
        });

        // Existing organization communities predate the approval workflow. They
        // keep their current visibility rather than silently becoming verified.
        DB::table('communities')
            ->where('type', 'organization')
            ->update(['verification_status' => 'unverified']);
    }

    public function down(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropIndex('communities_org_discovery_index');
            $table->dropIndex(['industry']);
            $table->dropIndex(['location']);
            $table->dropColumn([
                'industry',
                'website_url',
                'location',
                'services',
                'seeking',
                'offering',
                'verification_status',
                'approved_at',
                'approved_by',
                'rejected_reason',
                'suspended_at',
            ]);
        });
    }
};
