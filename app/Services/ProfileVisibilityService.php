<?php

namespace App\Services;

use App\Models\CommunityMember;
use App\Models\Connection;
use App\Models\Profile;
use App\Models\User;
use App\Support\ProfileVisibilityOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Server-side source of truth for profile privacy.
 *
 * Frontend filtering is presentation only; every profile read, discovery
 * query and contact action must pass through these checks.
 */
class ProfileVisibilityService
{
    public function __construct(protected BlockService $blocks) {}

    public function isOwnerOrAdmin(?User $viewer, Profile $profile): bool
    {
        if ($viewer === null) {
            return false;
        }

        return (string) $viewer->getKey() === (string) $profile->user_id
            || $viewer->isAdmin();
    }

    public function canView(?User $viewer, Profile $profile): bool
    {
        if ($this->isOwnerOrAdmin($viewer, $profile)) {
            return true;
        }

        if ($viewer !== null && $this->isBlockedBetween($viewer, (int) $profile->user_id)) {
            return false;
        }

        if (! $this->profileIsPublished($profile)) {
            return false;
        }

        return match ($profile->visibility) {
            ProfileVisibilityOptions::VISIBILITY_PUBLIC => true,
            ProfileVisibilityOptions::VISIBILITY_MEMBERS => $viewer !== null && ! $viewer->isDeactivated(),
            ProfileVisibilityOptions::VISIBILITY_COMMUNITIES => $viewer !== null
                && $this->sharesCommunity($viewer, (int) $profile->user_id),
            ProfileVisibilityOptions::VISIBILITY_CONNECTIONS => $viewer !== null
                && $this->hasApprovedConnection($viewer, (int) $profile->user_id),
            default => false,
        };
    }

    /**
     * Searchability is independent from visibility: a profile can be viewed
     * by direct URL but deliberately excluded from discovery.
     */
    public function canDiscover(?User $viewer, Profile $profile): bool
    {
        return (bool) $profile->is_searchable && $this->canView($viewer, $profile);
    }

    public function canContact(?User $viewer, Profile $profile): bool
    {
        if (! $this->canView($viewer, $profile)) {
            return false;
        }

        return (bool) $profile->is_contactable;
    }

    /**
     * "Who can send connection requests" is narrower than general
     * contactability. NULL keeps legacy profiles on the prior behaviour.
     */
    public function canRequestConnection(?User $viewer, Profile $profile): bool
    {
        if (! $this->canContact($viewer, $profile)) {
            return false;
        }

        $policy = $profile->connection_request_policy;

        if ($policy === null || $policy === '') {
            return true;
        }

        if ($viewer === null) {
            return false;
        }

        return match ($policy) {
            'communities' => $this->sharesCommunity($viewer, (int) $profile->user_id),
            'matching' => true, // Verified with the sender profile in MemberConnectionService.
            'none' => false,
            default => true,
        };
    }

    public function canViewContactDetails(?User $viewer, Profile $profile): bool
    {
        if ($this->isOwnerOrAdmin($viewer, $profile)) {
            return true;
        }

        return count(array_filter($this->visibleContactFields($viewer, $profile))) > 0;
    }

    /**
     * Per-field contact visibility: phone, whatsapp, email, website.
     * Falls back to the profile-wide contact_details_visibility setting.
     *
     * @return array{phone: bool, whatsapp: bool, email: bool, website: bool}
     */
    public function visibleContactFields(?User $viewer, Profile $profile): array
    {
        $fields = ['phone', 'whatsapp', 'email', 'website'];

        if ($this->isOwnerOrAdmin($viewer, $profile)) {
            return array_fill_keys($fields, true);
        }

        if ($viewer !== null && $this->isBlockedBetween($viewer, (int) $profile->user_id)) {
            return array_fill_keys($fields, false);
        }

        $ownerId = (int) $profile->user_id;
        $settings = $profile->contact_visibility ?? [];
        $fallback = $profile->contact_details_visibility;
        $visible = [];

        foreach ($fields as $field) {
            $setting = $settings[$field] ?? $fallback;

            $visible[$field] = match ($setting) {
                'public' => true,
                ProfileVisibilityOptions::CONTACT_CONNECTIONS => $viewer !== null
                    && $this->hasApprovedConnection($viewer, $ownerId),
                ProfileVisibilityOptions::CONTACT_COMMUNITIES => $viewer !== null
                    && ($this->hasApprovedConnection($viewer, $ownerId)
                        || $this->sharesCommunity($viewer, $ownerId)),
                default => false,
            };
        }

        return $visible;
    }

    public function hasApprovedConnection(User $viewer, int $ownerUserId): bool
    {
        if ((int) $viewer->getKey() === $ownerUserId) {
            return true;
        }

        if ($this->isBlockedBetween($viewer, $ownerUserId)) {
            return false;
        }

        return Connection::query()
            ->where('status', 'approved')
            ->where(function (Builder $query) use ($viewer, $ownerUserId) {
                $query->where(function (Builder $pair) use ($viewer, $ownerUserId) {
                    $pair->where('member_id', $ownerUserId)
                        ->where('guest_user_id', $viewer->getKey());
                })->orWhere(function (Builder $pair) use ($viewer, $ownerUserId) {
                    $pair->where('member_id', $viewer->getKey())
                        ->where('guest_user_id', $ownerUserId);
                });
            })
            ->exists();
    }

    public function sharesCommunity(User $viewer, int $ownerUserId): bool
    {
        if ((int) $viewer->getKey() === $ownerUserId) {
            return true;
        }

        if ($this->isBlockedBetween($viewer, $ownerUserId)) {
            return false;
        }

        $now = now();

        return DB::table('community_members as viewer_members')
            ->join('community_members as owner_members', 'owner_members.community_id', '=', 'viewer_members.community_id')
            ->join('communities', 'communities.id', '=', 'viewer_members.community_id')
            ->where('viewer_members.user_id', $viewer->getKey())
            ->where('owner_members.user_id', $ownerUserId)
            ->where('viewer_members.status', CommunityMember::STATUS_ACTIVE)
            ->where('owner_members.status', CommunityMember::STATUS_ACTIVE)
            ->where('communities.status', 'active')
            ->where(function ($query) use ($now) {
                $query->whereNull('viewer_members.ends_at')->orWhere('viewer_members.ends_at', '>', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('owner_members.ends_at')->orWhere('owner_members.ends_at', '>', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('viewer_members.starts_at')->orWhere('viewer_members.starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('owner_members.starts_at')->orWhere('owner_members.starts_at', '<=', $now);
            })
            ->exists();
    }

    public function isBlockedBetween(User $viewer, int $ownerUserId): bool
    {
        if ((int) $viewer->getKey() === $ownerUserId) {
            return false;
        }

        return $this->blocks->isBlockedBetween($viewer, $ownerUserId);
    }

    /**
     * Apply the same visibility rules to a discovery query, so a profile list
     * can never expose rows the viewer cannot open.
     */
    public function applyVisibilityScope(Builder $query, ?User $viewer): Builder
    {
        $query->where('profiles.is_live', true)
            ->where('profiles.is_published', true)
            ->whereHas('user', fn (Builder $user) => $user->whereNull('deactivated_at'));

        if ($viewer !== null) {
            $query->whereNotIn('profiles.user_id', $this->blocks->blockedUserIds($viewer));
        }

        if ($viewer?->isAdmin()) {
            return $query;
        }

        $query->where(function (Builder $scope) use ($viewer) {
            $scope->where('profiles.visibility', ProfileVisibilityOptions::VISIBILITY_PUBLIC);

            if ($viewer === null) {
                return;
            }

            $scope->orWhere('profiles.visibility', ProfileVisibilityOptions::VISIBILITY_MEMBERS);

            $scope->orWhere(function (Builder $connections) use ($viewer) {
                $connections->where('profiles.visibility', ProfileVisibilityOptions::VISIBILITY_CONNECTIONS)
                    ->whereExists(function ($sub) use ($viewer) {
                        $sub->select(DB::raw(1))
                            ->from('connections')
                            ->where('connections.status', 'approved')
                            ->where(function ($pair) use ($viewer) {
                                $pair->where(function ($forward) use ($viewer) {
                                    $forward->whereColumn('connections.member_id', 'profiles.user_id')
                                        ->where('connections.guest_user_id', $viewer->getKey());
                                })->orWhere(function ($reverse) use ($viewer) {
                                    $reverse->whereColumn('connections.guest_user_id', 'profiles.user_id')
                                        ->where('connections.member_id', $viewer->getKey());
                                });
                            });
                    });
            });

            $scope->orWhere(function (Builder $communities) use ($viewer) {
                $communities->where('profiles.visibility', ProfileVisibilityOptions::VISIBILITY_COMMUNITIES)
                    ->whereExists(function ($sub) use ($viewer) {
                        $sub->select(DB::raw(1))
                            ->from('community_members as viewer_memberships')
                            ->join('community_members as profile_memberships', 'profile_memberships.community_id', '=', 'viewer_memberships.community_id')
                            ->join('communities', 'communities.id', '=', 'viewer_memberships.community_id')
                            ->whereColumn('profile_memberships.user_id', 'profiles.user_id')
                            ->where('viewer_memberships.user_id', $viewer->getKey())
                            ->where('viewer_memberships.status', CommunityMember::STATUS_ACTIVE)
                            ->where('profile_memberships.status', CommunityMember::STATUS_ACTIVE)
                            ->where('profile_memberships.discoverable', true)
                            ->where('communities.status', 'active')
                            ->where(function ($dates) {
                                $now = now();
                                $dates->whereNull('viewer_memberships.starts_at')->orWhere('viewer_memberships.starts_at', '<=', $now);
                            })
                            ->where(function ($dates) {
                                $now = now();
                                $dates->whereNull('viewer_memberships.ends_at')->orWhere('viewer_memberships.ends_at', '>', $now);
                            })
                            ->where(function ($dates) {
                                $now = now();
                                $dates->whereNull('profile_memberships.starts_at')->orWhere('profile_memberships.starts_at', '<=', $now);
                            })
                            ->where(function ($dates) {
                                $now = now();
                                $dates->whereNull('profile_memberships.ends_at')->orWhere('profile_memberships.ends_at', '>', $now);
                            });
                    });
            });

            // A private profile is never discoverable by another account.
            $scope->orWhere(function (Builder $own) use ($viewer) {
                $own->where('profiles.visibility', ProfileVisibilityOptions::VISIBILITY_PRIVATE)
                    ->where('profiles.user_id', $viewer->getKey());
            });
        });

        return $query;
    }

    public function applySearchableScope(Builder $query): Builder
    {
        return $query->where('profiles.is_searchable', true);
    }

    protected function profileIsPublished(Profile $profile): bool
    {
        $user = $profile->user;

        return (bool) $profile->is_live
            && (bool) $profile->is_published
            && $user !== null
            && ! $user->trashed()
            && ! $user->isDeactivated();
    }
}
