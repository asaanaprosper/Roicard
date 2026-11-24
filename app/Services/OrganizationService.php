<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class OrganizationService
{
    public function __construct(
        protected CommunityMembershipService $memberships,
        protected ProfileVisibilityService $visibility,
    ) {}

    public function register(User $user, array $data): Community
    {
        return DB::transaction(function () use ($user, $data) {
            $organization = Community::create([
                'owner_user_id' => $user->id,
                'name' => trim((string) $data['name']),
                'slug' => Community::makeSlug($data['name']),
                'description' => $data['description'] ?? null,
                'type' => Community::TYPE_ORGANIZATION,
                'join_method' => Community::JOIN_REQUEST,
                'status' => Community::STATUS_INACTIVE,
                'verification_status' => Community::VERIFICATION_PENDING,
                'industry' => $data['industry'] ?? null,
                'website_url' => $data['website_url'] ?? null,
                'location' => $data['location'] ?? null,
                'services' => $data['services'] ?? null,
                'seeking' => $data['seeking'] ?? null,
                'offering' => $data['offering'] ?? null,
            ]);

            $organization->members()->create([
                'user_id' => $user->id,
                'role' => CommunityMember::ROLE_OWNER,
                'status' => CommunityMember::STATUS_ACTIVE,
                'join_method' => CommunityMember::JOIN_ADMIN,
                'joined_at' => now(),
                'affiliation_confirmed_at' => now(),
                'member_accepted_at' => now(),
                'affiliation_confirmed_by' => $user->id,
            ]);

            return $organization->fresh();
        });
    }

    public function update(User $actor, Community $organization, array $data): Community
    {
        $this->assertManage($actor, $organization);
        $this->assertOrganization($organization);

        $organization->fill([
            'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : $organization->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $organization->description,
            'industry' => array_key_exists('industry', $data) ? $data['industry'] : $organization->industry,
            'website_url' => array_key_exists('website_url', $data) ? $data['website_url'] : $organization->website_url,
            'location' => array_key_exists('location', $data) ? $data['location'] : $organization->location,
            'services' => array_key_exists('services', $data) ? $data['services'] : $organization->services,
            'seeking' => array_key_exists('seeking', $data) ? $data['seeking'] : $organization->seeking,
            'offering' => array_key_exists('offering', $data) ? $data['offering'] : $organization->offering,
            'join_method' => $data['join_method'] ?? $organization->join_method,
        ])->save();

        $this->bustMemberPublicCaches($organization);

        return $organization->fresh();
    }

    /**
     * @return array<int, Community>
     */
    public function mine(User $user): array
    {
        return Community::query()
            ->where('type', Community::TYPE_ORGANIZATION)
            ->where(function ($query) use ($user) {
                $query->where('owner_user_id', $user->id)
                    ->orWhereHas('members', function ($members) use ($user) {
                        $members->where('user_id', $user->id)
                            ->where('status', CommunityMember::STATUS_ACTIVE);
                    });
            })
            ->withCount(['activeMembers as members_count', 'activeMembers as active_members_count'])
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function publicDirectory(Community $organization, ?User $viewer, array $options = []): array
    {
        abort_unless($organization->isPubliclyVisible(), 404);

        $query = $organization->affiliatedMembers()
            ->with([
                'user:id,first_name,last_name,status,deactivated_at',
                'user.profile:id,user_id,slug,display_name,title,organisation,location,profile_type,visibility,is_live,is_published,is_searchable,completion_pct',
                'user.profile.media',
            ])
            ->orderBy('role')
            ->orderBy('id')
            ->limit(max(1, min(200, (int) ($options['limit'] ?? 200))));

        if (! empty($options['q'])) {
            $like = '%' . addcslashes(trim($options['q']), '%_\\') . '%';
            $query->whereHas('user', function ($user) use ($like) {
                $user->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like);
            });
        }

        $members = $query->get()
            ->map(function (CommunityMember $membership) use ($viewer) {
                $profile = $membership->user?->profile;

                if (! $profile || ! $this->visibility->canView($viewer, $profile)) {
                    return null;
                }

                return [
                    'membership_id' => $membership->id,
                    'role' => $membership->role,
                    'profile' => [
                        'id' => $profile->id,
                        'slug' => $profile->slug,
                        'display_name' => $profile->display_name,
                        'title' => $profile->title,
                        'organisation' => $profile->organisation,
                        'location' => $profile->location,
                        'profile_type' => $profile->profile_type,
                        'avatar_url' => $profile->avatar_url,
                        'verified' => $membership->user?->status === 'active'
                            && $membership->user?->email_verified_at !== null,
                        'user' => [
                            'first_name' => $membership->user?->first_name,
                            'last_name' => $membership->user?->last_name,
                        ],
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'organization' => $this->payload($organization),
            'members' => $members,
            'members_count' => count($members),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function managerMembers(Community $organization, User $actor, array $options = []): array
    {
        $this->assertManage($actor, $organization);

        $status = $options['status'] ?? null;
        $role = $options['role'] ?? null;

        $members = $organization->members()
            ->with('user:id,first_name,last_name,email')
            ->when($status, fn ($query, $value) => $query->where('status', $value))
            ->when($role, fn ($query, $value) => $query->where('role', $value))
            ->orderBy('role')
            ->orderBy('id')
            ->get()
            ->map(fn (CommunityMember $membership) => $this->membershipPayload($membership))
            ->values()
            ->all();

        $invitations = $organization->invitations()
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (OrganizationInvitation $invitation) => $invitation->isPending())
            ->map(fn (OrganizationInvitation $invitation) => $this->invitationPayload($invitation))
            ->values()
            ->all();

        return [
            'organization' => $this->payload($organization),
            'members' => $members,
            'invitations' => $invitations,
            'counts' => [
                'active' => $organization->activeMembers()->count(),
                'affiliated' => $organization->affiliatedMembers()->count(),
                'pending' => $organization->members()->where('status', CommunityMember::STATUS_PENDING)->count(),
                'invited' => $organization->members()->where('status', CommunityMember::STATUS_INVITED)->count(),
            ],
            'seat_limit' => $this->seatLimit($organization),
        ];
    }

    /**
     * Invite one or many emails. Existing accounts receive an in-app
     * notification; non-members receive an email invitation.
     *
     * @param  array<int, string>  $emails
     * @return array<string, mixed>
     */
    public function invite(Community $organization, User $actor, array $emails, string $role = CommunityMember::ROLE_MEMBER): array
    {
        $this->assertManage($actor, $organization);
        $this->assertOrganization($organization);
        abort_unless($organization->isActive(), 422, 'This organization is not active.');

        $this->assertCanAssignRole($actor, $organization, $role);

        $emails = array_values(array_unique(array_filter(array_map(
            fn ($email) => mb_strtolower(trim((string) $email)),
            $emails
        ))));

        $max = max(1, (int) config('roicard.organizations.max_invitations_per_bulk', 50));

        if (count($emails) > $max) {
            throw ValidationException::withMessages([
                'emails' => "You can invite at most {$max} addresses at a time.",
            ]);
        }

        $created = [];
        $skipped = [];
        $expiresAt = now()->addDays(max(1, (int) config('roicard.organizations.invitation_expiry_days', 30)));

        DB::transaction(function () use ($organization, $actor, $emails, $role, $expiresAt, &$created, &$skipped) {
            foreach ($emails as $email) {
                $existingMember = $organization->members()
                    ->whereHas('user', fn ($user) => $user->whereRaw('LOWER(email) = ?', [$email]))
                    ->first();

                if ($existingMember && in_array($existingMember->status, [
                    CommunityMember::STATUS_ACTIVE,
                    CommunityMember::STATUS_INVITED,
                    CommunityMember::STATUS_PENDING,
                ], true)) {
                    $skipped[] = $email;
                    continue;
                }

                $invitation = OrganizationInvitation::updateOrCreate(
                    ['community_id' => $organization->id, 'email' => $email],
                    [
                        'role' => $role,
                        'token' => OrganizationInvitation::makeToken(),
                        'status' => OrganizationInvitation::STATUS_PENDING,
                        'invited_by' => $actor->id,
                        'accepted_by' => null,
                        'accepted_at' => null,
                        'expires_at' => $expiresAt,
                    ],
                );

                $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

                if ($user) {
                    $membership = CommunityMember::firstOrNew([
                        'community_id' => $organization->id,
                        'user_id' => $user->id,
                    ]);

                    $membership->fill([
                        'role' => $role,
                        'status' => CommunityMember::STATUS_INVITED,
                        'join_method' => CommunityMember::JOIN_INVITE,
                        'affiliation_confirmed_at' => $membership->affiliation_confirmed_at ?? now(),
                        'affiliation_confirmed_by' => $membership->affiliation_confirmed_by ?? $actor->id,
                        'member_accepted_at' => null,
                        'ends_at' => null,
                    ])->save();

                    $user->notify(new OrganizationInvitationNotification($invitation, $organization));
                } else {
                    Notification::route('mail', $email)
                        ->notify(new OrganizationInvitationNotification($invitation, $organization));
                }

                $created[] = $invitation->fresh();
            }
        });

        $this->bustMemberPublicCaches($organization);

        return [
            'created' => $created,
            'skipped' => $skipped,
            'message' => count($created) . ' invitation(s) sent, ' . count($skipped) . ' skipped.',
        ];    }

    public function addExistingMember(Community $organization, User $actor, User $target, string $role = CommunityMember::ROLE_MEMBER): CommunityMember
    {
        $this->assertManage($actor, $organization);
        $this->assertOrganization($organization);
        $this->assertCanAssignRole($actor, $organization, $role);

        if ((string) $target->id === (string) $organization->owner_user_id) {
            throw ValidationException::withMessages(['user_id' => 'This member already owns the organization.']);
        }

        $existing = $organization->members()->where('user_id', $target->id)->first();

        if ($existing && in_array($existing->status, [
            CommunityMember::STATUS_ACTIVE,
            CommunityMember::STATUS_INVITED,
            CommunityMember::STATUS_PENDING,
        ], true)) {
            throw ValidationException::withMessages(['user_id' => 'This member is already affiliated or invited.']);
        }

        $membership = CommunityMember::firstOrNew([
            'community_id' => $organization->id,
            'user_id' => $target->id,
        ]);

        $membership->fill([
            'role' => $role,
            'status' => CommunityMember::STATUS_INVITED,
            'join_method' => CommunityMember::JOIN_ADMIN,
            'affiliation_confirmed_at' => now(),
            'affiliation_confirmed_by' => $actor->id,
            'member_accepted_at' => null,
            'ends_at' => null,
        ])->save();

        $invitation = OrganizationInvitation::updateOrCreate(
            ['community_id' => $organization->id, 'email' => mb_strtolower($target->email)],
            [
                'role' => $role,
                'token' => OrganizationInvitation::makeToken(),
                'status' => OrganizationInvitation::STATUS_PENDING,
                'invited_by' => $actor->id,
                'accepted_by' => null,
                'accepted_at' => null,
                'expires_at' => now()->addDays(max(1, (int) config('roicard.organizations.invitation_expiry_days', 30))),
            ],
        );

        $target->notify(new OrganizationInvitationNotification($invitation, $organization));

        app(AnalyticsService::class)->record($organization->owner_user_id, 'organization_member_invited', [
            'organization_id' => $organization->id,
            'membership_id' => $membership->id,
        ]);

        $this->bustMemberPublicCaches($organization);

        return $membership->fresh();
    }

    public function requestJoin(Community $organization, User $user): CommunityMember
    {
        $this->assertOrganization($organization);
        abort_unless($organization->isPubliclyVisible(), 422, 'This organization is not accepting requests.');

        $existing = $organization->members()->where('user_id', $user->id)->first();

        if ($existing && $existing->status === CommunityMember::STATUS_ACTIVE
            && $existing->member_accepted_at !== null) {
            return $existing;
        }

        $membership = CommunityMember::firstOrNew([
            'community_id' => $organization->id,
            'user_id' => $user->id,
        ]);

        $membership->fill([
            'role' => CommunityMember::ROLE_MEMBER,
            'status' => CommunityMember::STATUS_PENDING,
            'join_method' => CommunityMember::JOIN_REQUEST,
            'member_accepted_at' => now(),
            'affiliation_confirmed_at' => null,
            'ends_at' => null,
        ])->save();

        return $membership->fresh();
    }

    public function approveMembership(Community $organization, User $actor, CommunityMember $membership): CommunityMember
    {
        $this->assertManage($actor, $organization);
        abort_unless((int) $membership->community_id === (int) $organization->id, 404);

        if ((string) $membership->user_id === (string) $organization->owner_user_id) {
            return $membership;
        }

        $this->assertCanAssignRole($actor, $organization, $membership->role);

        $membership = $this->memberships->activate($membership);

        $membership->forceFill([
            'affiliation_confirmed_at' => $membership->affiliation_confirmed_at ?? now(),
            'affiliation_confirmed_by' => $actor->id,
        ])->save();

        $this->bustMemberPublicCaches($organization);

        app(AnalyticsService::class)->record($organization->owner_user_id, 'organization_affiliation_confirmed', [
            'organization_id' => $organization->id,
            'membership_id' => $membership->id,
        ]);

        return $membership->fresh();
    }

    public function rejectMembership(Community $organization, User $actor, CommunityMember $membership): CommunityMember
    {
        $this->assertManage($actor, $organization);
        abort_unless((int) $membership->community_id === (int) $organization->id, 404);

        if ((string) $membership->user_id === (string) $organization->owner_user_id) {
            throw ValidationException::withMessages(['member' => 'The organization owner cannot be removed.']);
        }

        $membership->forceFill([
            'status' => CommunityMember::STATUS_DECLINED,
            'ends_at' => now(),
        ])->save();

        $this->bustMemberPublicCaches($organization);

        return $membership->fresh();
    }

    public function assignAdministrator(Community $organization, User $actor, User $target): CommunityMember
    {
        $this->assertManage($actor, $organization);
        $this->assertOrganization($organization);
        $this->assertCanAssignRole($actor, $organization, CommunityMember::ROLE_MANAGER);

        if ((string) $target->id === (string) $organization->owner_user_id) {
            throw ValidationException::withMessages(['user_id' => 'This member already owns the organization.']);
        }

        $membership = CommunityMember::firstOrNew([
            'community_id' => $organization->id,
            'user_id' => $target->id,
        ]);

        $membership->fill([
            'role' => CommunityMember::ROLE_MANAGER,
            'status' => CommunityMember::STATUS_ACTIVE,
            'join_method' => CommunityMember::JOIN_ADMIN,
            'joined_at' => $membership->joined_at ?? now(),
            'affiliation_confirmed_at' => now(),
            'affiliation_confirmed_by' => $actor->id,
            'member_accepted_at' => now(),
            'ends_at' => null,
        ])->save();

        return $membership->fresh();
    }

    public function acceptAffiliation(Community $organization, User $user, CommunityMember $membership): CommunityMember
    {
        $this->assertOrganization($organization);
        abort_unless((int) $membership->community_id === (int) $organization->id, 404);
        abort_unless((int) $membership->user_id === (int) $user->id, 403);

        if ($membership->affiliation_confirmed_at === null) {
            throw ValidationException::withMessages([
                'affiliation' => 'The organization has not confirmed this affiliation yet.',
            ]);
        }

        if (! $organization->isActive()) {
            throw ValidationException::withMessages([
                'organization' => 'This organization is not currently active.',
            ]);
        }

        if ($membership->status !== CommunityMember::STATUS_ACTIVE) {
            $membership = $this->memberships->activate($membership);
        }

        $membership->forceFill([
            'member_accepted_at' => $membership->member_accepted_at ?? now(),
        ])->save();

        $this->bustMemberPublicCaches($organization);

        app(AnalyticsService::class)->record($organization->owner_user_id, 'organization_affiliation_accepted', [
            'organization_id' => $organization->id,
            'membership_id' => $membership->id,
        ]);

        return $membership->fresh();
    }

    public function removeMember(Community $organization, User $actor, CommunityMember $membership): CommunityMember
    {
        $this->assertManage($actor, $organization);
        abort_unless((int) $membership->community_id === (int) $organization->id, 404);

        if ((string) $membership->user_id === (string) $organization->owner_user_id) {
            throw ValidationException::withMessages(['member' => 'The organization owner cannot be removed.']);
        }

        if ((int) $membership->user_id === (int) $actor->id) {
            throw ValidationException::withMessages(['member' => 'You cannot remove your own membership.']);
        }

        $membership->forceFill([
            'status' => CommunityMember::STATUS_LEFT,
            'ends_at' => now(),
        ])->save();

        $this->bustMemberPublicCaches($organization);

        return $membership->fresh();
    }

    public function revokeInvitation(Community $organization, User $actor, OrganizationInvitation $invitation): OrganizationInvitation
    {
        $this->assertManage($actor, $organization);
        abort_unless((int) $invitation->community_id === (int) $organization->id, 404);

        $invitation->forceFill(['status' => OrganizationInvitation::STATUS_REVOKED])->save();

        return $invitation->fresh();
    }

    public function acceptInvitation(string $token, User $user): CommunityMember
    {
        $invitation = OrganizationInvitation::where('token', $token)->firstOrFail();

        if (! $invitation->isPending()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation is no longer valid.']);
        }

        if (mb_strtolower($invitation->email) !== mb_strtolower($user->email)) {
            abort(403, 'This invitation belongs to a different email address.');
        }

        $organization = $invitation->organization;
        abort_unless($organization && $organization->isActive(), 422, 'This organization is not active.');

        return DB::transaction(function () use ($invitation, $organization, $user) {
            $membership = CommunityMember::firstOrNew([
                'community_id' => $organization->id,
                'user_id' => $user->id,
            ]);

            $membership->fill([
                'role' => $invitation->role,
                'status' => CommunityMember::STATUS_INVITED,
                'join_method' => CommunityMember::JOIN_INVITE,
                'affiliation_confirmed_at' => $membership->affiliation_confirmed_at ?? now(),
                'affiliation_confirmed_by' => $membership->affiliation_confirmed_by ?? $invitation->invited_by,
                'ends_at' => null,
            ])->save();

            $membership = $this->memberships->activate($membership);
            $membership->forceFill(['member_accepted_at' => now()])->save();

            $invitation->forceFill([
                'status' => OrganizationInvitation::STATUS_ACCEPTED,
                'accepted_by' => $user->id,
                'accepted_at' => now(),
            ])->save();

            $this->bustMemberPublicCaches($organization);

            return $membership->fresh();
        });
    }

    public function seatLimit(Community $organization): ?int
    {
        $limits = array_filter([
            $organization->member_seat_limit,
            $organization->owner
                ? app(EntitlementService::class)->limit($organization->owner, \App\Support\PlanLimitKey::ORG_MEMBER_SEATS)
                : null,
        ], fn ($value) => $value !== null);

        return $limits === [] ? null : min(array_map('intval', $limits));
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Community $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'description' => $organization->description,
            'type' => $organization->type,
            'status' => $organization->status,
            'verification_status' => $organization->verification_status,
            'join_method' => $organization->join_method,
            'industry' => $organization->industry,
            'website_url' => $organization->website_url,
            'location' => $organization->location,
            'services' => $organization->services ?? [],
            'seeking' => $organization->seeking,
            'offering' => $organization->offering,
            'logo_url' => $organization->logo_url,
            'cover_url' => $organization->cover_url,
            'owner_user_id' => $organization->owner_user_id,
            'approved_at' => $organization->approved_at,
            'rejected_reason' => $organization->rejected_reason,
            'suspended_at' => $organization->suspended_at,
            'member_seat_limit' => $organization->member_seat_limit,
            'members_count' => $organization->active_members_count
                ?? $organization->activeMembers()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function membershipPayload(CommunityMember $membership): array
    {
        return [
            'id' => $membership->id,
            'user_id' => $membership->user_id,
            'role' => $membership->role,
            'status' => $membership->status,
            'join_method' => $membership->join_method,
            'joined_at' => $membership->joined_at,
            'affiliation_confirmed_at' => $membership->affiliation_confirmed_at,
            'member_accepted_at' => $membership->member_accepted_at,
            'publicly_affiliated' => $membership->isPubliclyAffiliated(),
            'user' => $membership->user ? [
                'id' => $membership->user->id,
                'first_name' => $membership->user->first_name,
                'last_name' => $membership->user->last_name,
                'email' => $membership->user->email,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function invitationPayload(OrganizationInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'status' => $invitation->status,
            'expires_at' => $invitation->expires_at,
            'accepted_at' => $invitation->accepted_at,
            'created_at' => $invitation->created_at,
        ];
    }

    /**
     * Clear the shared public-profile response cache for members of this
     * organization after affiliation or verification changes.
     */
    public function bustMemberPublicCaches(Community $organization): void
    {
        $userIds = $organization->members()->pluck('user_id')->all();

        if ($userIds === []) {
            return;
        }

        \App\Models\Profile::query()
            ->whereIn('user_id', $userIds)
            ->get()
            ->each(fn (\App\Models\Profile $profile) => $profile->bustPublicCache());
    }

    protected function assertManage(User $actor, Community $organization): void
    {
        $this->assertOrganization($organization);
        abort_unless($this->memberships->canManage($actor, $organization), 403);
    }

    protected function assertOrganization(Community $organization): void
    {
        abort_unless($organization->isOrganization(), 404);
    }

    protected function assertCanAssignRole(User $actor, Community $organization, string $role): void
    {
        if ($role !== CommunityMember::ROLE_MANAGER) {
            return;
        }

        abort_unless(
            $actor->isAdmin() || (string) $organization->owner_user_id === (string) $actor->id,
            403,
            'Only the organization owner can assign organization administrators.'
        );
    }
}
