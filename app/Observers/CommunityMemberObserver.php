<?php

namespace App\Observers;

use App\Models\CommunityMember;
use App\Models\Profile;
use Illuminate\Support\Facades\Cache;

/**
 * Community membership changes affect discovery and the public profile's
 * organization list. Invalidate both caches so a removal, leave or
 * discoverability change is reflected immediately instead of waiting for the
 * recommendation TTL or the one-hour public-profile cache.
 */
class CommunityMemberObserver
{
    public function saved(CommunityMember $membership): void
    {
        $this->invalidate((int) $membership->user_id);
    }

    public function deleted(CommunityMember $membership): void
    {
        $this->invalidate((int) $membership->user_id);
    }

    protected function invalidate(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        Cache::increment('matchmaking:version');

        Profile::query()
            ->where('user_id', $userId)
            ->get()
            ->each(fn (Profile $profile) => $profile->bustPublicCache());
    }
}
