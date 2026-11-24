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
            // Personal vs business profile. Existing MVP 1 profiles are
            // personal and keep their existing URL slugs untouched.
            $table->string('profile_type', 20)->default('personal')->after('user_id');
            $table->string('display_name')->nullable()->after('profile_type');

            // Independent discoverability controls. Defaults preserve the
            // MVP 1 public profile behaviour while applying the MVP 2
            // contact-detail rule (connections only) to existing rows.
            $table->string('visibility', 20)->default('public')->after('is_live');
            $table->boolean('is_searchable')->default(true)->after('visibility');
            $table->boolean('is_contactable')->default(true)->after('is_searchable');
            $table->string('contact_details_visibility', 20)->default('connections')->after('is_contactable');

            // Exactly one default profile per user is enforced by the
            // profile service (a portable partial unique index is not
            // available across MySQL and SQLite).
            $table->boolean('is_default')->default(false)->after('contact_details_visibility');

            $table->index(['user_id', 'is_default']);
            $table->index('visibility');
        });

        // Backfill: the oldest profile for each user becomes that account's
        // default profile. Existing public URLs are not modified.
        $defaultIds = DB::table('profiles')
            ->selectRaw('MIN(id) as id')
            ->groupBy('user_id')
            ->pluck('id');

        foreach ($defaultIds->chunk(500) as $chunk) {
            DB::table('profiles')->whereIn('id', $chunk->all())->update(['is_default' => true]);
        }
    }

    public function down(): void
    {
        // MySQL can use the composite (user_id, is_default) index to support
        // the profiles.user_id foreign key. Dropping that index while the FK
        // still exists fails with error 1553, so temporarily remove the FK,
        // drop the MVP 2 indexes/columns, then restore the original FK.
        $isMysql = DB::connection()->getDriverName() === 'mysql';

        if ($isMysql) {
            Schema::table('profiles', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_default']);
            $table->dropIndex(['visibility']);
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn([
                'profile_type',
                'display_name',
                'visibility',
                'is_searchable',
                'is_contactable',
                'contact_details_visibility',
                'is_default',
            ]);
        });

        if ($isMysql) {
            Schema::table('profiles', function (Blueprint $table) {
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            });
        }
    }
};
