<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_enquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained('business_catalog_items')->cascadeOnDelete();
            $table->foreignId('business_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->text('message');
            // new | read | archived
            $table->string('status', 20)->default('new');
            // public | member
            $table->string('source', 20)->default('public');
            $table->timestamps();

            $table->index(['catalog_item_id', 'created_at']);
            $table->index(['business_profile_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('sender_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_enquiries');
    }
};
