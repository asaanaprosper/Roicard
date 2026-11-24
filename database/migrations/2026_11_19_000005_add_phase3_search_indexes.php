<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->index(['is_published', 'is_searchable', 'visibility'], 'profiles_discovery_index');
            $table->index('profile_type');
            $table->index('organisation');
            $table->index('location');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['status', 'deactivated_at'], 'users_search_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropIndex('profiles_discovery_index');
            $table->dropIndex(['profile_type']);
            $table->dropIndex(['organisation']);
            $table->dropIndex(['location']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_search_status_index');
        });
    }
};
