<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_members', function (Blueprint $table) {
            // Per-community Discover/recommendation opt-out. Direct profile
            // visibility is unaffected; only discovery inside that community.
            $table->boolean('discoverable')->default(true)->after('join_method');
        });
    }

    public function down(): void
    {
        Schema::table('community_members', function (Blueprint $table) {
            $table->dropColumn('discoverable');
        });
    }
};
