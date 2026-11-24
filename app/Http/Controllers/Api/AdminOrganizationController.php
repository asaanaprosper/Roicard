<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CommunityMembershipService;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminOrganizationController extends Controller
{
    public function __construct(
        protected OrganizationService $organizations,
        protected CommunityMembershipService $memberships,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'verification_status' => ['nullable', 'string', Rule::in(Community::verificationStatuses())],
            'status' => ['nullable', 'string', Rule::in(Community::statuses())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Community::query()
            ->where('type', Community::TYPE_ORGANIZATION)
            ->with(['owner:id,first_name,last_name,email', 'media'])
            ->withCount(['members', 'activeMembers as active_members_count'])
            ->when($validated['verification_status'] ?? null, fn ($q, $status) => $q->where('verification_status', $status))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        if (! empty($validated['q'])) {
            $like = '%' . addcslashes(trim($validated['q']), '%_\\') . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('industry', 'like', $like)
                    ->orWhere('location', 'like', $like);
            });
        }

        $page = $query->orderByDesc('created_at')->paginate((int) ($validated['per_page'] ?? 20));
        $page->getCollection()->transform(fn (Community $organization) => $this->organizations->payload($organization));

        return response()->json($page);
    }

    public function show(Community $organization): JsonResponse
    {
        abort_unless($organization->isOrganization(), 404);

        $organization->load([
            'owner:id,first_name,last_name,email',
            'members.user:id,first_name,last_name,email',
            'invitations',
        ]);

        return response()->json([
            'organization' => $this->organizations->payload($organization),
            'members' => $organization->members->map(
                fn (CommunityMember $membership) => $this->organizations->membershipPayload($membership)
            ),
            'invitations' => $organization->invitations->map(
                fn ($invitation) => $this->organizations->invitationPayload($invitation)
            ),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $this->validated($request);

        $organization = DB::transaction(function () use ($validated, $request) {
            $owner = User::findOrFail($validated['owner_user_id']);
            $verification = $validated['verification_status'] ?? Community::VERIFICATION_PENDING;

            $organization = Community::create([
                'owner_user_id' => $owner->id,
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? Community::makeSlug($validated['name']),
                'description' => $validated['description'] ?? null,
                'type' => Community::TYPE_ORGANIZATION,
                'join_method' => $validated['join_method'] ?? Community::JOIN_REQUEST,
                'status' => $validated['status'] ?? (
                    $verification === Community::VERIFICATION_VERIFIED
                        ? Community::STATUS_ACTIVE
                        : Community::STATUS_INACTIVE
                ),
                'verification_status' => $verification,
                'organisation_name' => $validated['name'],
                'industry' => $validated['industry'] ?? null,
                'website_url' => $validated['website_url'] ?? null,
                'location' => $validated['location'] ?? null,
                'services' => $validated['services'] ?? null,
                'seeking' => $validated['seeking'] ?? null,
                'offering' => $validated['offering'] ?? null,
                'member_seat_limit' => $validated['member_seat_limit'] ?? null,
                'approved_at' => $verification === Community::VERIFICATION_VERIFIED ? now() : null,
                'approved_by' => $verification === Community::VERIFICATION_VERIFIED ? $request->user()->id : null,
            ]);

            $organization->members()->create([
                'user_id' => $owner->id,
                'role' => CommunityMember::ROLE_OWNER,
                'status' => CommunityMember::STATUS_ACTIVE,
                'join_method' => CommunityMember::JOIN_ADMIN,
                'joined_at' => now(),
                'affiliation_confirmed_at' => now(),
                'member_accepted_at' => now(),
                'affiliation_confirmed_by' => $request->user()->id,
            ]);

            return $organization;
        });

        $audit->log('organization.created', $organization, [
            'verification_status' => $organization->verification_status,
            'owner_user_id' => $organization->owner_user_id,
        ]);

        return response()->json([
            'organization' => $this->organizations->payload($organization->fresh()),
            'message' => 'Organization created.',
        ], 201);
    }

    public function update(Request $request, Community $organization, AuditLogger $audit): JsonResponse
    {
        abort_unless($organization->isOrganization(), 404);
        $validated = $this->validated($request, true);
        $before = $organization->only([
            'name', 'slug', 'status', 'verification_status', 'industry', 'location', 'website_url',
        ]);

        $organization->fill([
            'name' => $validated['name'] ?? $organization->name,
            'slug' => $validated['slug'] ?? $organization->slug,
            'description' => array_key_exists('description', $validated)
                ? $validated['description'] : $organization->description,
            'industry' => array_key_exists('industry', $validated) ? $validated['industry'] : $organization->industry,
            'website_url' => array_key_exists('website_url', $validated) ? $validated['website_url'] : $organization->website_url,
            'location' => array_key_exists('location', $validated) ? $validated['location'] : $organization->location,
            'services' => array_key_exists('services', $validated) ? $validated['services'] : $organization->services,
            'seeking' => array_key_exists('seeking', $validated) ? $validated['seeking'] : $organization->seeking,
            'offering' => array_key_exists('offering', $validated) ? $validated['offering'] : $organization->offering,
            'member_seat_limit' => array_key_exists('member_seat_limit', $validated)
                ? $validated['member_seat_limit'] : $organization->member_seat_limit,
            'status' => $validated['status'] ?? $organization->status,
            'verification_status' => $validated['verification_status'] ?? $organization->verification_status,
        ])->save();

        $audit->log('organization.updated', $organization, [
            'before' => $before,
            'after' => $organization->only([
                'name', 'slug', 'status', 'verification_status', 'industry', 'location', 'website_url',
            ]),
        ]);

        return response()->json([
            'organization' => $this->organizations->payload($organization->fresh()),
            'message' => 'Organization updated.',
        ]);
    }

    public function approve(Request $request, Community $organization, AuditLogger $audit): JsonResponse
    {
        abort_unless($organization->isOrganization(), 404);

        $organization->forceFill([
            'verification_status' => Community::VERIFICATION_VERIFIED,
            'status' => Community::STATUS_ACTIVE,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'rejected_reason' => null,
            'suspended_at' => null,
        ])->save();

        $this->organizations->bustMemberPublicCaches($organization);

        $audit->log('organization.approved', $organization, [
            'verification_status' => $organization->verification_status,
        ]);

        return response()->json([
            'organization' => $this->organizations->payload($organization),
            'message' => 'Organization approved and verified.',
        ]);
    }

    public function reject(Request $request, Community $organization, AuditLogger $audit): JsonResponse
    {
        abort_unless($organization->isOrganization(), 404);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $organization->forceFill([
            'verification_status' => Community::VERIFICATION_REJECTED,
            'status' => Community::STATUS_INACTIVE,
            'rejected_reason' => $validated['reason'] ?? null,
            'approved_at' => null,
            'approved_by' => null,
        ])->save();

        $this->organizations->bustMemberPublicCaches($organization);

        $audit->log('organization.rejected', $organization, [
            'reason' => $organization->rejected_reason,
        ]);

        return response()->json([
            'organization' => $this->organizations->payload($organization),
            'message' => 'Organization rejected.',
        ]);
    }

    public function suspend(Request $request, Community $organization, AuditLogger $audit): JsonResponse
    {
        abort_unless($organization->isOrganization(), 404);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $organization->forceFill([
            'verification_status' => Community::VERIFICATION_SUSPENDED,
            'status' => Community::STATUS_INACTIVE,
            'suspended_at' => now(),
            'rejected_reason' => $validated['reason'] ?? $organization->rejected_reason,
        ])->save();

        $this->organizations->bustMemberPublicCaches($organization);

        $audit->log('organization.suspended', $organization, [
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'organization' => $this->organizations->payload($organization),
            'message' => 'Organization suspended.',
        ]);
    }

    public function assignAdministrator(Request $request, Community $organization, AuditLogger $audit): JsonResponse
    {
        abort_unless($organization->isOrganization(), 404);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $target = User::findOrFail($validated['user_id']);

        if ((string) $target->id === (string) $organization->owner_user_id) {
            throw ValidationException::withMessages(['user_id' => 'This member already owns the organization.']);
        }

        $membership = $this->organizations->assignAdministrator(
            $organization,
            $request->user(),
            $target,
        );

        $audit->log('organization.administrator_assigned', $organization, [
            'user_id' => $target->id,
            'membership_id' => $membership->id,
        ]);

        return response()->json([
            'membership' => $this->organizations->membershipPayload($membership->load('user')),
            'message' => 'Organization administrator invited.',
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'owner_user_id' => [$partial ? 'sometimes' : 'required', 'integer', 'exists:users,id'],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('communities', 'slug')->ignore($request->route('organization')?->id),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'industry' => ['nullable', 'string', 'max:120'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'services' => ['nullable', 'array', 'max:30'],
            'services.*' => ['string', 'max:120'],
            'seeking' => ['nullable', 'string', 'max:2000'],
            'offering' => ['nullable', 'string', 'max:2000'],
            'join_method' => ['sometimes', 'string', Rule::in([Community::JOIN_REQUEST, Community::JOIN_INVITE])],
            'status' => ['sometimes', 'string', Rule::in(Community::statuses())],
            'verification_status' => ['sometimes', 'string', Rule::in(Community::verificationStatuses())],
            'member_seat_limit' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
