<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CommunityMembershipService;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function __construct(
        protected OrganizationService $organizations,
        protected CommunityMembershipService $memberships,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'industry' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Community::query()
            ->verifiedOrganizations()
            ->with('media')
            ->withCount(['activeMembers as members_count'])
            ->when($validated['industry'] ?? null, fn ($q, $industry) => $q->where('industry', 'like', '%' . $this->escapeLike($industry) . '%'))
            ->when($validated['location'] ?? null, fn ($q, $location) => $q->where('location', 'like', '%' . $this->escapeLike($location) . '%'));

        if (! empty($validated['q'])) {
            $like = '%' . $this->escapeLike($validated['q']) . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('name', 'like', $like)
                    ->orWhere('organisation_name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('industry', 'like', $like)
                    ->orWhereRaw('LOWER(CAST(services AS CHAR)) LIKE LOWER(?)', [$like]);
            });
        }

        $organizations = $query->orderBy('name')
            ->paginate((int) ($validated['per_page'] ?? 20));

        $organizations->getCollection()->transform(
            fn (Community $organization) => $this->organizations->payload($organization)
        );

        return response()->json($organizations);
    }

    public function show(string $slug, Request $request): JsonResponse
    {
        $organization = Community::where('slug', $slug)
            ->where('type', Community::TYPE_ORGANIZATION)
            ->firstOrFail();

        $viewer = $request->user('sanctum');

        if (! $organization->isPubliclyVisible()
            && ! ($viewer && ($this->memberships->canManage($viewer, $organization) || $viewer->isAdmin()))) {
            abort(404);
        }

        return response()->json(
            $this->organizations->publicDirectory($organization, $viewer, [
                'q' => $request->string('q')->toString(),
            ])
        );
    }

    public function mine(Request $request): JsonResponse
    {
        $organizations = collect($this->organizations->mine($request->user()))
            ->map(fn (Community $organization) => [
                ...$this->organizations->payload($organization),
                'can_manage' => $this->memberships->canManage($request->user(), $organization),
                'my_membership' => $organization->members()
                    ->where('user_id', $request->user()->id)
                    ->first()
                    ?->only(['id', 'role', 'status', 'affiliation_confirmed_at', 'member_accepted_at']),
            ])
            ->values();

        return response()->json(['organizations' => $organizations]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $organization = $this->organizations->register($request->user(), $validated);

        if ($request->hasFile('logo')) {
            $organization->addMedia($request->file('logo'))->toMediaCollection('logo');
        $this->organizations->bustMemberPublicCaches($organization);
        }
        if ($request->hasFile('cover')) {
            $organization->addMedia($request->file('cover'))->toMediaCollection('cover');
        $this->organizations->bustMemberPublicCaches($organization);
        }

        return response()->json([
            'organization' => $this->organizations->payload($organization->fresh()),
            'message' => 'Organization submitted for approval.',
        ], 201);
    }

    public function update(Request $request, Community $organization): JsonResponse
    {
        $validated = $this->validated($request, true);
        $organization = $this->organizations->update($request->user(), $organization, $validated);

        return response()->json([
            'organization' => $this->organizations->payload($organization),
            'message' => 'Organization updated.',
        ]);
    }

    public function uploadLogo(Request $request, Community $organization): JsonResponse
    {
        abort_unless($organization->isOrganization(), 404);
        abort_unless($this->memberships->canManage($request->user(), $organization), 403);
        $this->validateImage($request, 'logo');
        $organization->addMedia($request->file('logo'))->toMediaCollection('logo');
        $this->organizations->bustMemberPublicCaches($organization);

        return response()->json([
            'organization' => $this->organizations->payload($organization->fresh()),
            'message' => 'Logo updated.',
        ]);
    }

    public function uploadCover(Request $request, Community $organization): JsonResponse
    {
        abort_unless($organization->isOrganization(), 404);
        abort_unless($this->memberships->canManage($request->user(), $organization), 403);
        $this->validateImage($request, 'cover');
        $organization->addMedia($request->file('cover'))->toMediaCollection('cover');
        $this->organizations->bustMemberPublicCaches($organization);

        return response()->json([
            'organization' => $this->organizations->payload($organization->fresh()),
            'message' => 'Cover updated.',
        ]);
    }

    public function members(Request $request, Community $organization): JsonResponse
    {
        return response()->json($this->organizations->managerMembers(
            $organization,
            $request->user(),
            $request->only(['status', 'role'])
        ));
    }

    public function addMember(Request $request, Community $organization): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'email' => ['nullable', 'email', 'max:255'],
            'role' => ['sometimes', 'string', Rule::in([
                CommunityMember::ROLE_MEMBER,
                CommunityMember::ROLE_MODERATOR,
                CommunityMember::ROLE_MANAGER,
            ])],
        ]);

        $target = ! empty($validated['user_id'])
            ? User::findOrFail($validated['user_id'])
            : User::whereRaw('LOWER(email) = ?', [mb_strtolower((string) ($validated['email'] ?? ''))])->first();

        if (! $target) {
            throw ValidationException::withMessages([
                'email' => 'No ROICARD account uses this email. Send an invitation instead.',
            ]);
        }

        $membership = $this->organizations->addExistingMember(
            $organization,
            $request->user(),
            $target,
            $validated['role'] ?? CommunityMember::ROLE_MEMBER,
        );

        return response()->json([
            'membership' => $this->organizations->membershipPayload($membership->load('user')),
            'message' => 'Member invited to confirm the affiliation.',
        ], 201);
    }

    public function invite(Request $request, Community $organization): JsonResponse
    {
        $max = max(1, (int) config('roicard.organizations.max_invitations_per_bulk', 50));

        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'emails' => ['nullable', 'array', 'max:' . $max],
            'emails.*' => ['email', 'max:255'],
            'role' => ['sometimes', 'string', Rule::in([
                CommunityMember::ROLE_MEMBER,
                CommunityMember::ROLE_MODERATOR,
                CommunityMember::ROLE_MANAGER,
            ])],
        ]);

        $emails = $validated['emails'] ?? [];
        if (! empty($validated['email'])) {
            $emails[] = $validated['email'];
        }

        if ($emails === []) {
            throw ValidationException::withMessages([
                'emails' => 'Enter at least one email address.',
            ]);
        }

        $result = $this->organizations->invite(
            $organization,
            $request->user(),
            $emails,
            $validated['role'] ?? CommunityMember::ROLE_MEMBER,
        );

        return response()->json([
            ...$result,
            'invitations' => collect($result['created'])->map(
                fn (OrganizationInvitation $invitation) => $this->organizations->invitationPayload($invitation)
            )->values(),
        ], 201);
    }

    public function revokeInvitation(Request $request, Community $organization, OrganizationInvitation $invitation): JsonResponse
    {
        return response()->json([
            'invitation' => $this->organizations->invitationPayload(
                $this->organizations->revokeInvitation($organization, $request->user(), $invitation)
            ),
            'message' => 'Invitation revoked.',
        ]);
    }

    public function approveMembership(Request $request, Community $organization, CommunityMember $membership): JsonResponse
    {
        return response()->json([
            'membership' => $this->organizations->membershipPayload(
                $this->organizations->approveMembership($organization, $request->user(), $membership)->load('user')
            ),
            'message' => 'Affiliation confirmed.',
        ]);
    }

    public function rejectMembership(Request $request, Community $organization, CommunityMember $membership): JsonResponse
    {
        return response()->json([
            'membership' => $this->organizations->membershipPayload(
                $this->organizations->rejectMembership($organization, $request->user(), $membership)->load('user')
            ),
            'message' => 'Membership request declined.',
        ]);
    }

    public function acceptAffiliation(Request $request, Community $organization, CommunityMember $membership): JsonResponse
    {
        return response()->json([
            'membership' => $this->organizations->membershipPayload(
                $this->organizations->acceptAffiliation($organization, $request->user(), $membership)->load('user')
            ),
            'message' => 'Affiliation accepted.',
        ]);
    }

    public function removeMember(
        Request $request,
        Community $organization,
        CommunityMember $membership,
        AuditLogger $audit,
    ): JsonResponse {
        $membershipId = $membership->id;
        $userId = $membership->user_id;

        $removed = $this->organizations->removeMember($organization, $request->user(), $membership);

        $audit->log('organization.member_removed', $organization, [
            'membership_id' => $membershipId,
            'user_id' => $userId,
        ]);

        return response()->json([
            'membership' => $this->organizations->membershipPayload($removed->load('user')),
            'message' => 'Member removed from the organization.',
        ]);
    }

    public function requestJoin(Request $request, Community $organization): JsonResponse
    {
        $membership = $this->organizations->requestJoin($organization, $request->user());

        return response()->json([
            'membership' => $this->organizations->membershipPayload($membership),
            'message' => 'Join request submitted. The organization must confirm your affiliation.',
        ], 201);
    }

    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        $membership = $this->organizations->acceptInvitation($token, $request->user());

        return response()->json([
            'membership' => $this->organizations->membershipPayload($membership->load('user')),
            'message' => 'Invitation accepted. Your affiliation is now confirmed.',
        ]);
    }

    public function myInvitations(Request $request): JsonResponse
    {
        $invitations = OrganizationInvitation::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($request->user()->email)])
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->with('organization:id,name,slug')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (OrganizationInvitation $invitation) => $invitation->isPending())
            ->map(fn (OrganizationInvitation $invitation) => [
                ...$this->organizations->invitationPayload($invitation),
                'organization' => $invitation->organization ? [
                    'id' => $invitation->organization->id,
                    'name' => $invitation->organization->name,
                    'slug' => $invitation->organization->slug,
                ] : null,
                'token' => $invitation->token,
            ])
            ->values();

        return response()->json(['invitations' => $invitations]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $partial = false): array
    {
        $maxKb = max(1, (int) config('roicard.catalog.max_image_kb', 5120));

        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'industry' => ['nullable', 'string', 'max:120'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'services' => ['nullable', 'array', 'max:30'],
            'services.*' => ['string', 'max:120'],
            'seeking' => ['nullable', 'string', 'max:2000'],
            'offering' => ['nullable', 'string', 'max:2000'],
            'join_method' => ['sometimes', 'string', Rule::in([
                Community::JOIN_REQUEST,
                Community::JOIN_INVITE,
            ])],
            'logo' => ['sometimes', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:' . $maxKb],
            'cover' => ['sometimes', 'image', 'mimes:jpeg,png,jpg,webp', 'max:' . $maxKb],
        ]);
    }

    protected function validateImage(Request $request, string $field): void
    {
        $maxKb = max(1, (int) config('roicard.catalog.max_image_kb', 5120));

        $request->validate([
            $field => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:' . $maxKb],
        ]);
    }

    protected function escapeLike(string $value): string
    {
        return addcslashes(trim($value), '%_\\');
    }
}
