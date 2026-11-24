<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            // square | story | email_banner
            $table->string('format', 30);
            $table->string('template', 30)->default('classic');
            $table->string('file_path');
            $table->string('hash', 64);
            $table->text('alt_text');
            $table->dateTime('generated_at');
            $table->timestamps();

            $table->unique(['profile_id', 'format', 'template']);
            $table->index(['profile_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_cards');
    }
};
