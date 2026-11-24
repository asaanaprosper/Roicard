<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Sign-up consent for profile processing. AI consent is recorded
            // separately when the member first enables AI features.
            $table->timestamp('privacy_consent_at')->nullable()->after('ai_consent_version');
            $table->string('privacy_consent_version', 40)->nullable()->after('privacy_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['privacy_consent_at', 'privacy_consent_version']);
        });
    }
};
