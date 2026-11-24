<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;

class UserObserver
{
    public function created(User $user): void
    {
        $role = $user->role === 'admin' ? 'admin' : 'member';

        // The `role` column is the source of truth for routing. The Spatie
        // role is a secondary convenience, so a deployment that has not run
        // the seeder yet must not turn account creation into a 500.
        if (Role::where('name', $role)->where('guard_name', 'web')->exists()) {
            $user->assignRole($role);
        }

        // Phase 2: every member gets a server-backed setup task list. Admins
        // use the console rather than the member setup dashboard.
        if ($user->role !== 'admin') {
            app(\App\Services\OnboardingService::class)->ensureTasks($user);
        }
    }

    /**
     * Soft deletion must not leave the account's profiles in recommendation
     * caches or the public-profile cache until their TTL expires.
     */
    public function deleted(User $user): void
    {
        Cache::increment('matchmaking:version');

        $user->profiles()
            ->get()
            ->each(fn (\App\Models\Profile $profile) => $profile->bustPublicCache());
    }
}
