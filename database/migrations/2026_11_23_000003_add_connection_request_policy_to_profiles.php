<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // NULL keeps historical profiles on the legacy is_contactable
            // behaviour. New profiles/default UI use one of:
            // communities | matching | none.
            $table->string('connection_request_policy', 20)->nullable()->after('is_contactable');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('connection_request_policy');
        });
    }
};
