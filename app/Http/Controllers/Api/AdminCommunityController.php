<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CommunityMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminCommunityController extends Controller
{
    public function __construct(protected CommunityMembershipService $memberships) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', Rule::in(Community::types())],
            'status' => ['nullable', 'string', Rule::in(Community::statuses())],
            'join_method' => ['nullable', 'string', Rule::in(Community::joinMethods())],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Community::query()
            ->with('owner:id,first_name,last_name,email')
            ->withCount(['members', 'activeMembers as active_members_count'])
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['join_method'] ?? null, fn ($query, $method) => $query->where('join_method', $method));

        if (! empty($validated['q'])) {
            $like = '%' . addcslashes(trim($validated['q']), '%_\\') . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('organisation_name', 'like', $like);
            });
        }

        return response()->json(
            $query->orderBy('name')->paginate((int) ($validated['per_page'] ?? 20))
        );
    }

    public function show(Community $community): JsonResponse
    {
        $community->load([
            'owner:id,first_name,last_name,email',
            'members' => fn ($query) => $query->with('user:id,first_name,last_name,email')->orderBy('id'),
        ])->loadCount(['members', 'activeMembers as active_members_count']);

        return response()->json([
            'community' => $community,
            'roles' => CommunityMember::roles(),
            'statuses' => CommunityMember::statuses(),
            'join_methods' => CommunityMember::joinMethods(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $this->validated($request);

        $community = DB::transaction(function () use ($validated, $request) {
            $owner = User::findOrFail($validated['owner_user_id']);

            $community = Community::create([
                'owner_user_id' => $owner->id,
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? Community::makeSlug($validated['name']),
                'description' => $validated['description'] ?? null,
                'type' => $validated['type'] ?? Community::TYPE_GROUP,
                'join_method' => $validated['join_method'] ?? Community::JOIN_OPEN,
                'status' => $validated['status'] ?? Community::STATUS_ACTIVE,
                'organisation_name' => $validated['organisation_name'] ?? null,
                'member_seat_limit' => $validated['member_seat_limit'] ?? null,
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
                'metadata' => $validated['metadata'] ?? null,
            ]);

            // Ownership is represented by an owner-role membership row too,
            // so member lists and visibility rules have one source of truth.
            $community->members()->create([
                'user_id' => $owner->id,
                'role' => CommunityMember::ROLE_OWNER,
                'status' => CommunityMember::STATUS_ACTIVE,
                'join_method' => CommunityMember::JOIN_ADMIN,
                'joined_at' => now(),
            ]);

            return $community;
        });

        $audit->log('community.created', $community, [
            'type' => $community->type,
            'join_method' => $community->join_method,
            'owner_user_id' => $community->owner_user_id,
        ]);

        return response()->json([
            'community' => $community->fresh()->load('owner:id,first_name,last_name,email'),
            'message' => 'Community created successfully',
        ], 201);
    }

    public function update(Request $request, Community $community, AuditLogger $audit): JsonResponse
    {
        $validated = $this->validated($request, $community->id);
        $before = $community->only([
            'name', 'slug', 'type', 'join_method', 'status', 'owner_user_id',
            'member_seat_limit', 'starts_at', 'ends_at',
        ]);

        DB::transaction(function () use ($community, $validated) {
            $newOwnerId = $validated['owner_user_id'] ?? $community->owner_user_id;

            $community->fill([
                'owner_user_id' => $newOwnerId,
                'name' => $validated['name'] ?? $community->name,
                'slug' => $validated['slug'] ?? $community->slug,
                'description' => array_key_exists('description', $validated) ? $validated['description'] : $community->description,
                'type' => $validated['type'] ?? $community->type,
                'join_method' => $validated['join_method'] ?? $community->join_method,
                'status' => $validated['status'] ?? $community->status,
                'organisation_name' => array_key_exists('organisation_name', $validated)
                    ? $validated['organisation_name']
                    : $community->organisation_name,
                'member_seat_limit' => array_key_exists('member_seat_limit', $validated)
                    ? $validated['member_seat_limit']
                    : $community->member_seat_limit,
                'starts_at' => array_key_exists('starts_at', $validated) ? $validated['starts_at'] : $community->starts_at,
                'ends_at' => array_key_exists('ends_at', $validated) ? $validated['ends_at'] : $community->ends_at,
                'metadata' => array_key_exists('metadata', $validated) ? $validated['metadata'] : $community->metadata,
            ])->save();

            if ((int) $newOwnerId !== (int) $before['owner_user_id']) {
                CommunityMember::where('community_id', $community->id)
                    ->where('role', CommunityMember::ROLE_OWNER)
                    ->update(['role' => CommunityMember::ROLE_MANAGER]);

                CommunityMember::updateOrCreate(
                    ['community_id' => $community->id, 'user_id' => $newOwnerId],
                    [
                        'role' => CommunityMember::ROLE_OWNER,
                        'status' => CommunityMember::STATUS_ACTIVE,
                        'join_method' => CommunityMember::JOIN_ADMIN,
                        'joined_at' => now(),
                        'ends_at' => null,
                    ],
                );
            }
        });

        $audit->log('community.updated', $community, [
            'before' => $before,
            'after' => $community->only([
                'name', 'slug', 'type', 'join_method', 'status', 'owner_user_id',
                'member_seat_limit', 'starts_at', 'ends_at',
            ]),
        ]);

        return response()->json([
            'community' => $community->fresh()->load('owner:id,first_name,last_name,email'),
            'message' => 'Community updated successfully',
        ]);
    }

    public function destroy(Community $community, AuditLogger $audit): JsonResponse
    {
        $snapshot = $community->only(['name', 'slug', 'type', 'status']);
        $community->delete();

        $audit->log('community.deleted', $community, $snapshot);

        return response()->json(['message' => 'Community deleted successfully']);
    }

    public function members(Request $request, Community $community): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(CommunityMember::statuses())],
            'role' => ['nullable', 'string', Rule::in(CommunityMember::roles())],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $community->members()
            ->with('user:id,first_name,last_name,email')
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['role'] ?? null, fn ($query, $role) => $query->where('role', $role));

        if (! empty($validated['q'])) {
            $like = '%' . addcslashes(trim($validated['q']), '%_\\') . '%';
            $query->whereHas('user', function ($builder) use ($like) {
                $builder->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        return response()->json(
            $query->orderBy('id')->paginate((int) ($validated['per_page'] ?? 25))
        );
    }

    public function addMember(Request $request, Community $community, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            // Ownership is transferred through the community record, never by
            // adding an owner-role membership for someone else.
            'role' => ['sometimes', 'string', Rule::in([
                CommunityMember::ROLE_MEMBER,
                CommunityMember::ROLE_MODERATOR,
                CommunityMember::ROLE_MANAGER,
            ])],
            'status' => ['sometimes', 'string', Rule::in(CommunityMember::statuses())],
            'join_method' => ['sometimes', 'string', Rule::in(CommunityMember::joinMethods())],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $membership = $this->memberships->addMember(
            $community,
            User::findOrFail($validated['user_id']),
            $validated['role'] ?? CommunityMember::ROLE_MEMBER,
            $validated['status'] ?? CommunityMember::STATUS_ACTIVE,
            $validated['join_method'] ?? CommunityMember::JOIN_ADMIN,
        );

        $membership->fill([
            'starts_at' => $validated['starts_at'] ?? $membership->starts_at,
            'ends_at' => $validated['ends_at'] ?? $membership->ends_at,
        ])->save();

        $audit->log('community.member_added', $community, [
            'membership_id' => $membership->id,
            'user_id' => $membership->user_id,
            'role' => $membership->role,
            'status' => $membership->status,
        ]);

        return response()->json([
            'membership' => $membership->fresh()->load('user:id,first_name,last_name,email'),
            'message' => 'Community member saved successfully',
        ], 201);
    }

    public function updateMember(
        Request $request,
        Community $community,
        CommunityMember $membership,
        AuditLogger $audit,
    ): JsonResponse {
        abort_unless((int) $membership->community_id === (int) $community->id, 404);

        $validated = $request->validate([
            'role' => ['sometimes', 'string', Rule::in(CommunityMember::roles())],
            'status' => ['sometimes', 'string', Rule::in(CommunityMember::statuses())],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        if (($validated['role'] ?? null) === CommunityMember::ROLE_OWNER
            && (int) $membership->user_id !== (int) $community->owner_user_id) {
            return response()->json([
                'message' => 'Transfer community ownership from the community record instead of changing a member role.',
            ], 422);
        }

        if ((int) $membership->user_id === (int) $community->owner_user_id) {
            if (array_key_exists('role', $validated) && $validated['role'] !== CommunityMember::ROLE_OWNER) {
                return response()->json([
                    'message' => 'The community owner membership cannot be demoted.',
                ], 422);
            }

            if (array_key_exists('status', $validated) && $validated['status'] !== CommunityMember::STATUS_ACTIVE) {
                return response()->json([
                    'message' => 'The community owner membership must stay active.',
                ], 422);
            }
        }

        if (isset($validated['status']) && $validated['status'] === CommunityMember::STATUS_ACTIVE
            && $membership->status !== CommunityMember::STATUS_ACTIVE) {
            $membership = $this->memberships->activate($membership);
        }

        $membership->fill([
            'role' => $validated['role'] ?? $membership->role,
            'status' => $validated['status'] ?? $membership->status,
            'starts_at' => array_key_exists('starts_at', $validated) ? $validated['starts_at'] : $membership->starts_at,
            'ends_at' => array_key_exists('ends_at', $validated) ? $validated['ends_at'] : $membership->ends_at,
        ])->save();

        $audit->log('community.member_updated', $community, [
            'membership_id' => $membership->id,
            'user_id' => $membership->user_id,
            'role' => $membership->role,
            'status' => $membership->status,
        ]);

        return response()->json([
            'membership' => $membership->fresh()->load('user:id,first_name,last_name,email'),
            'message' => 'Community member updated successfully',
        ]);
    }

    public function removeMember(
        Community $community,
        CommunityMember $membership,
        AuditLogger $audit,
    ): JsonResponse {
        abort_unless((int) $membership->community_id === (int) $community->id, 404);

        if ((int) $membership->user_id === (int) $community->owner_user_id) {
            return response()->json([
                'message' => 'The community owner cannot be removed. Transfer ownership first.',
            ], 422);
        }

        $userId = $membership->user_id;
        $membership->delete();

        $audit->log('community.member_removed', $community, [
            'user_id' => $userId,
        ]);

        return response()->json(['message' => 'Community member removed successfully']);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'owner_user_id' => [$ignoreId ? 'sometimes' : 'required', 'integer', 'exists:users,id'],
            'name' => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('communities', 'slug')->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string'],
            'type' => ['sometimes', 'string', Rule::in(Community::types())],
            'join_method' => ['sometimes', 'string', Rule::in(Community::joinMethods())],
            'status' => ['sometimes', 'string', Rule::in(Community::statuses())],
            'organisation_name' => ['nullable', 'string', 'max:255'],
            'member_seat_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'metadata' => ['nullable', 'array'],
        ]);
    }
}
