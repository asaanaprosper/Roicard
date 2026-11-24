<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->boolean('is_published')->default(false)->after('is_live');
            $table->timestamp('published_at')->nullable()->after('is_published');
            $table->timestamp('privacy_confirmed_at')->nullable()->after('published_at');

            // Approved public content and the member's structured offering.
            $table->json('skills')->nullable()->after('bio');
            $table->json('services')->nullable()->after('skills');

            // Contact options with per-field visibility.
            $table->string('contact_email')->nullable()->after('phone');
            $table->string('website_url')->nullable()->after('contact_email');
            $table->json('contact_visibility')->nullable()->after('contact_details_visibility');

            // AI drafts are stored separately so member-provided text is never
            // overwritten before individual approval.
            $table->json('ai_draft')->nullable()->after('contact_visibility');
            $table->json('ai_approved_fields')->nullable()->after('ai_draft');

            $table->index(['is_published', 'visibility']);
        });

        // Existing live MVP 1 profiles keep their public URLs and become
        // explicitly published. No slugs or profile content are changed.
        DB::table('profiles')
            ->where('is_live', true)
            ->whereNotNull('slug')
            ->update([
                'is_published' => true,
                'published_at' => DB::raw('COALESCE(updated_at, created_at)'),
                'privacy_confirmed_at' => DB::raw('COALESCE(updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropIndex(['is_published', 'visibility']);
            $table->dropColumn([
                'is_published',
                'published_at',
                'privacy_confirmed_at',
                'skills',
                'services',
                'contact_email',
                'website_url',
                'contact_visibility',
                'ai_draft',
                'ai_approved_fields',
            ]);
        });
    }
};
