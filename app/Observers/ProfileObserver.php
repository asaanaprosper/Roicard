<?php

namespace App\Observers;

use App\Models\EducationEntry;
use App\Models\ExperienceEntry;
use App\Models\AchievementEntry;
use App\Models\Profile;
use App\Models\SocialLink;
use App\Services\AnalyticsService;
use App\Services\SlugService;
use Illuminate\Support\Facades\Cache;

class ProfileObserver
{
    public function creating(Profile $profile): void
    {
        // The first profile for an account is always the default. Additional
        // profiles inherit the personal/public defaults from the migration.
        if (! $profile->is_default) {
            $profile->is_default = ! Profile::where('user_id', $profile->user_id)->exists();
        }

        $this->syncPublishedFlag($profile);
        $this->ensureSlug($profile);
    }

    public function updating(Profile $profile): void
    {
        $this->syncPublishedFlag($profile);
        $this->ensureSlug($profile);
    }

    /**
     * Legacy/publication compatibility: profiles explicitly made live are
     * also published. New profiles start private until the member completes
     * the Phase 2 publishing task.
     */
    protected function syncPublishedFlag(Profile $profile): void
    {
        if ($profile->is_live && ! $profile->is_published) {
            $profile->is_published = true;
        }

        if ($profile->is_published && $profile->published_at === null) {
            $profile->published_at = now();
        }
    }

    public function saved(Profile $profile): void
    {
        $profile->recalculateCompletion();
        $this->clearCache($profile);
    }

    public function updated(Profile $profile): void
    {
        $analytics = app(AnalyticsService::class);

        if ($profile->wasChanged('is_published')) {
            $analytics->record(
                $profile->user_id,
                $profile->is_published ? 'profile_published' : 'profile_unpublished',
                ['profile_id' => $profile->id],
            );
        }

        if ($profile->wasChanged('is_searchable')) {
            $analytics->record(
                $profile->user_id,
                $profile->is_searchable ? 'searchability_enabled' : 'searchability_disabled',
                ['profile_id' => $profile->id],
            );
        }

        if ($profile->wasChanged('visibility')) {
            $analytics->record($profile->user_id, 'profile_visibility_changed', [
                'profile_id' => $profile->id,
                'visibility' => $profile->visibility,
            ]);
        }
    }

    /** Generates a public URL slug for every profile, even before payment. */
    protected function ensureSlug(Profile $profile): void
    {
        if ($profile->slug !== null) {
            return;
        }

        $user = $profile->user;
        if (!$user || !$user->first_name) {
            return;
        }

        // A business/profile display name produces a more meaningful URL,
        // while MVP 1 profiles (no display name) keep the existing name slug.
        $baseName = trim((string) ($profile->display_name ?? ''));
        if ($baseName !== '') {
            $profile->slug = app(SlugService::class)->generate($baseName, '');
            return;
        }

        $profile->slug = app(SlugService::class)->generate($user->first_name, $user->last_name);
    }

    public function deleted(Profile $profile): void
    {
        $this->clearCache($profile);
    }

    protected function clearCache(Profile $profile): void
    {
        $profile->bustPublicCache();
        Cache::increment('matchmaking:version');
    }
}
