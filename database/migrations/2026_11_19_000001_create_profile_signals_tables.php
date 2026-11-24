<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            // seeking | offering
            $table->string('type', 20);
            $table->text('description');
            $table->boolean('is_active')->default(true);
            $table->timestamp('paused_at')->nullable();
            $table->string('source', 20)->default('member');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['profile_id', 'type', 'is_active']);
            $table->index(['type', 'is_active']);
        });

        Schema::create('profile_signal_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signal_id')->constrained('profile_signals')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['signal_id', 'category_id']);
            $table->index('category_id');
        });

        Schema::create('profile_signal_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signal_id')->constrained('profile_signals')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['signal_id', 'tag_id']);
            $table->index('tag_id');
        });

        // Preserve Phase 2 free-text Seeking/Offering and their profile-level
        // categories as the first signal item per profile.
        $profiles = DB::table('profiles')
            ->select(['id', 'seeking', 'offering', 'created_at', 'updated_at'])
            ->get();

        foreach ($profiles as $profile) {
            foreach (['seeking', 'offering'] as $type) {
                $description = trim((string) ($profile->{$type} ?? ''));
                $categoryIds = DB::table('category_profile')
                    ->join('categories', 'categories.id', '=', 'category_profile.category_id')
                    ->where('category_profile.profile_id', $profile->id)
                    ->where('categories.kind', $type)
                    ->pluck('categories.id')
                    ->all();

                if ($description === '' && $categoryIds === []) {
                    continue;
                }

                $now = now();
                $signalId = DB::table('profile_signals')->insertGetId([
                    'profile_id' => $profile->id,
                    'type' => $type,
                    'description' => $description !== '' ? $description : ucfirst($type) . ' opportunity',
                    'is_active' => true,
                    'source' => 'legacy',
                    'sort_order' => 0,
                    'created_at' => $profile->created_at ?? $now,
                    'updated_at' => $profile->updated_at ?? $now,
                ]);

                foreach ($categoryIds as $categoryId) {
                    DB::table('profile_signal_category')->insert([
                        'signal_id' => $signalId,
                        'category_id' => $categoryId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_signal_tag');
        Schema::dropIfExists('profile_signal_category');
        Schema::dropIfExists('profile_signals');
    }
};
