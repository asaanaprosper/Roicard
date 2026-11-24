<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\User;
use App\Support\PlanLimitKey;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CommunityMembershipService
{
    public function __construct(protected EntitlementService $entitlements) {}

    public function join(Community $community, User $user, string $joinMethod = CommunityMember::JOIN_OPEN): CommunityMember
    {
        if (! $community->isActive()) {
            throw new AccessDeniedHttpException('This community is not accepting members.');
        }

        $membership = CommunityMember::firstOrNew([
            'community_id' => $community->id,
            'user_id' => $user->id,
        ]);

        if ($membership->exists && $membership->status === CommunityMember::STATUS_ACTIVE) {
            return $membership;
        }

        // An existing invitation is the one path into an invite-only
        // community; the member can accept it without an admin action.
        if ($membership->exists && $membership->status === CommunityMember::STATUS_INVITED) {
            if ($community->isOrganization()) {
                $membership->forceFill([
                    'affiliation_confirmed_at' => $membership->affiliation_confirmed_at ?? now(),
                    'member_accepted_at' => $membership->member_accepted_at ?? now(),
                ])->save();
            }

            return $this->activate($membership);
        }

        $requestedMethod = in_array($joinMethod, CommunityMember::joinMethods(), true)
            ? $joinMethod
            : CommunityMember::JOIN_OPEN;

        if ($community->join_method === Community::JOIN_INVITE
            && ! in_array($requestedMethod, [CommunityMember::JOIN_INVITE, CommunityMember::JOIN_ADMIN], true)) {
            throw new AccessDeniedHttpException('This community is invite only.');
        }

        $status = match ($community->join_method) {
            Community::JOIN_REQUEST => CommunityMember::STATUS_PENDING,
            Community::JOIN_INVITE => CommunityMember::STATUS_INVITED,
            default => CommunityMember::STATUS_ACTIVE,
        };

        if ($status === CommunityMember::STATUS_ACTIVE) {
            $this->assertCanHoldActiveSeat($community, $user);
        }

        $membership->fill([
            'role' => $membership->exists ? ($membership->role ?: CommunityMember::ROLE_MEMBER) : CommunityMember::ROLE_MEMBER,
            'status' => $status,
            'join_method' => $requestedMethod,
            'joined_at' => $status === CommunityMember::STATUS_ACTIVE ? now() : null,
            'affiliation_confirmed_at' => $community->isOrganization()
                ? ($status === CommunityMember::STATUS_ACTIVE ? now() : null)
                : $membership->affiliation_confirmed_at,
            'member_accepted_at' => $community->isOrganization()
                ? now()
                : $membership->member_accepted_at,
            'ends_at' => null,
        ])->save();

        return $membership->fresh() ?? $membership;
    }

    public function activate(CommunityMember $membership): CommunityMember
    {
        $community = $membership->community;
        abort_unless($community && $community->isActive(), 403, 'This community is not accepting members.');

        $this->assertCanHoldActiveSeat($community, $membership->user);

        $membership->forceFill([
            'status' => CommunityMember::STATUS_ACTIVE,
            'joined_at' => $membership->joined_at ?? now(),
            'ends_at' => null,
        ])->save();

        return $membership->fresh() ?? $membership;
    }

    public function leave(Community $community, User $user): ?CommunityMember
    {
        $membership = CommunityMember::where('community_id', $community->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $membership || $membership->status === CommunityMember::STATUS_LEFT) {
            return $membership;
        }

        $membership->forceFill([
            'status' => CommunityMember::STATUS_LEFT,
            'ends_at' => now(),
        ])->save();

        return $membership->fresh() ?? $membership;
    }

    /**
     * Administrative add/invite. This path is intentionally separate from
     * self-service join so admins can seed organization members.
     */
    public function addMember(
        Community $community,
        User $user,
        string $role = CommunityMember::ROLE_MEMBER,
        string $status = CommunityMember::STATUS_ACTIVE,
        string $joinMethod = CommunityMember::JOIN_ADMIN,
    ): CommunityMember {
        if ($status === CommunityMember::STATUS_ACTIVE) {
            $this->assertCanHoldActiveSeat($community, $user);
        }

        $membership = CommunityMember::firstOrNew([
            'community_id' => $community->id,
            'user_id' => $user->id,
        ]);

        // The owner's membership row is a structural invariant: it can never
        // be demoted or made inactive through the generic member endpoint.
        if ((string) $community->owner_user_id === (string) $user->getKey()) {
            $role = CommunityMember::ROLE_OWNER;
            $status = CommunityMember::STATUS_ACTIVE;
            $joinMethod = CommunityMember::JOIN_ADMIN;
        }

        $membership->fill([
            'role' => $role,
            'status' => $status,
            'join_method' => $joinMethod,
            'joined_at' => $status === CommunityMember::STATUS_ACTIVE
                ? ($membership->joined_at ?? now())
                : $membership->joined_at,
            'ends_at' => $status === CommunityMember::STATUS_ACTIVE ? null : $membership->ends_at,
        ])->save();

        return $membership->fresh() ?? $membership;
    }

    public function canManage(User $user, Community $community): bool
    {
        if ($user->isAdmin() || (string) $community->owner_user_id === (string) $user->getKey()) {
            return true;
        }

        return CommunityMember::where('community_id', $community->id)
            ->where('user_id', $user->id)
            ->where('status', CommunityMember::STATUS_ACTIVE)
            ->whereIn('role', [CommunityMember::ROLE_OWNER, CommunityMember::ROLE_MANAGER])
            ->exists();
    }

    public function activeMembershipCount(User $user): int
    {
        return CommunityMember::query()
            ->where('user_id', $user->id)
            ->active()
            ->count();
    }

    protected function assertCanHoldActiveSeat(Community $community, User $user): void
    {
        // Owners already hold their seat by definition; counting their
        // ownership membership against the member's plan would make it
        // impossible to own a community on a restrictive plan.
        if ((string) $community->owner_user_id === (string) $user->getKey()) {
            return;
        }

        $alreadyMember = CommunityMember::where('community_id', $community->id)
            ->where('user_id', $user->id)
            ->where('status', CommunityMember::STATUS_ACTIVE)
            ->exists();

        if (! $alreadyMember) {
            $current = $this->activeMembershipCount($user);
            $this->entitlements->ensureWithinLimit(
                $user,
                PlanLimitKey::COMMUNITIES_JOINED,
                $current,
                1,
                'Your plan does not allow joining another community.',
            );
        }

        if (! $community->isOrganization()) {
            return;
        }

        // Organization seats belong to the community's plan holder, not to
        // each person requesting a seat.
        $seatPlanHolder = $community->owner;
        $limits = array_filter([
            $community->member_seat_limit,
            $seatPlanHolder
                ? $this->entitlements->limit($seatPlanHolder, PlanLimitKey::ORG_MEMBER_SEATS)
                : null,
        ], fn ($value) => $value !== null);

        if ($limits === []) {
            return;
        }

        $seatLimit = min(array_map('intval', $limits));
        $seated = $community->activeMembers()->count();

        if (! $alreadyMember && $seated >= $seatLimit) {
            throw new \App\Exceptions\EntitlementExceededException(
                PlanLimitKey::ORG_MEMBER_SEATS,
                $seatLimit,
                $seated,
                'This organization community has no member seats left.',
            );
        }
    }
}
