<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_signals', function (Blueprint $table) {
            // Catalog-driven offering signals are system-managed. The unique
            // key prevents duplicate offering rows for one catalog item.
            $table->foreignId('catalog_item_id')->nullable()->after('profile_id')
                ->unique();
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('profile_signals', function (Blueprint $table) {
                $table->foreign('catalog_item_id')
                    ->references('id')
                    ->on('business_catalog_items')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('profile_signals', function (Blueprint $table) {
                $table->dropForeign(['catalog_item_id']);
            });
        }

        Schema::table('profile_signals', function (Blueprint $table) {
            $table->dropUnique(['catalog_item_id']);
            $table->dropColumn('catalog_item_id');
        });
    }
};
