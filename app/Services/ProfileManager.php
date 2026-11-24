<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\User;
use App\Support\PlanLimitKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the "multiple profiles under one account" invariants:
 * - a profile always belongs to its account;
 * - exactly one profile is the account default;
 * - creation respects the PROFILE_COUNT entitlement.
 */
class ProfileManager
{
    public function __construct(protected EntitlementService $entitlements) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data = []): Profile
    {
        return DB::transaction(function () use ($user, $data) {
            $count = $user->profiles()->count();

            $this->entitlements->ensureWithinLimit(
                $user,
                PlanLimitKey::PROFILE_COUNT,
                $count,
                1,
                'Your plan does not allow another profile.',
            );

            $profile = $user->profiles()->create($this->attributes($data));

            if ($count === 0 || ! $user->profiles()->where('is_default', true)->exists()) {
                $this->markDefault($user, $profile);
            }

            return $profile->fresh() ?? $profile;
        });
    }

    public function setDefault(User $user, Profile $profile): Profile
    {
        $this->assertOwns($user, $profile);

        DB::transaction(function () use ($user, $profile) {
            $this->markDefault($user, $profile);
        });

        return $profile->fresh() ?? $profile;
    }

    public function delete(User $user, Profile $profile): void
    {
        $this->assertOwns($user, $profile);

        if ($user->profiles()->count() <= 1) {
            throw ValidationException::withMessages([
                'profile' => 'An account must keep at least one profile.',
            ]);
        }

        DB::transaction(function () use ($user, $profile) {
            $wasDefault = (bool) $profile->is_default;
            $profile->purgeStoredMedia();
            $profile->delete();

            if ($wasDefault) {
                $next = $user->profiles()->orderBy('id')->first();
                if ($next) {
                    $this->markDefault($user, $next);
                }
            }
        });
    }

    public function assertOwns(User $user, Profile $profile): void
    {
        abort_unless((string) $profile->user_id === (string) $user->getKey(), 403, 'Profile does not belong to this account.');
    }

    /**
     * Allowed profile columns for creation. Account-level and publication
     * fields (user_id, slug, is_live, is_default) are never client supplied.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function attributes(array $data): array
    {
        $allowed = [
            'profile_type',
            'display_name',
            'title',
            'role_description',
            'organisation',
            'whatsapp_phone',
            'phone',
            'date_of_birth',
            'gender',
            'location',
            'bio',
            'seeking',
            'offering',
            'visibility',
            'is_searchable',
            'is_contactable',
            'connection_request_policy',
            'contact_details_visibility',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }

    protected function markDefault(User $user, Profile $profile): void
    {
        $user->profiles()->whereKeyNot($profile->id)->update(['is_default' => false]);
        $profile->forceFill(['is_default' => true])->save();
    }
}
