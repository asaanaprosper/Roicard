<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Services\CommunityMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Member-facing community browse, join and leave endpoints.
 */
class CommunityController extends Controller
{
    public function __construct(protected CommunityMembershipService $memberships) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:event,group,organization'],
            'join_method' => ['nullable', 'string', 'in:open,request,invite'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $userId = $request->user()->id;

        $query = Community::query()
            ->active()
            ->with('owner:id,first_name,last_name')
            ->withCount(['activeMembers as members_count'])
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($validated['join_method'] ?? null, fn ($query, $method) => $query->where('join_method', $method));

        if (! empty($validated['q'])) {
            $like = '%' . addcslashes(trim($validated['q']), '%_\\') . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('organisation_name', 'like', $like);
            });
        }

        $communities = $query->orderBy('name')->paginate((int) ($validated['per_page'] ?? 20));

        $memberships = CommunityMember::where('user_id', $userId)
            ->whereIn('community_id', $communities->getCollection()->pluck('id'))
            ->get()
            ->keyBy('community_id');

        $communities->getCollection()->transform(function (Community $community) use ($memberships) {
            $membership = $memberships->get($community->id);
            $community->setAttribute('my_membership', $membership?->only([
                'id', 'role', 'status', 'join_method', 'discoverable', 'joined_at', 'ends_at',
            ]));
            $community->setAttribute('can_join', $community->isActive()
                && $community->join_method !== Community::JOIN_INVITE
                && (! $membership || ! $membership->isActive()));

            return $community;
        });

        return response()->json($communities);
    }

    public function show(string $slug, Request $request): JsonResponse
    {
        $community = Community::where('slug', $slug)
            ->with(['owner:id,first_name,last_name'])
            ->withCount(['activeMembers as members_count'])
            ->firstOrFail();

        $membership = CommunityMember::where('community_id', $community->id)
            ->where('user_id', $request->user()->id)
            ->first();

        return response()->json([
            'community' => $community,
            'my_membership' => $membership,
            'can_manage' => $this->memberships->canManage($request->user(), $community),
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $memberships = CommunityMember::where('user_id', $request->user()->id)
            ->with(['community' => fn ($query) => $query->withCount(['activeMembers as members_count'])])
            ->orderByDesc('joined_at')
            ->get();

        return response()->json([
            'memberships' => $memberships,
        ]);
    }

    public function join(Community $community, Request $request): JsonResponse
    {
        $membership = $this->memberships->join($community, $request->user());

        return response()->json([
            'membership' => $membership,
            'message' => $membership->status === CommunityMember::STATUS_ACTIVE
                ? 'Joined community successfully'
                : 'Community request submitted',
        ], 201);
    }

    public function leave(Community $community, Request $request): JsonResponse
    {
        if ((string) $community->owner_user_id === (string) $request->user()->id) {
            return response()->json([
                'message' => 'Community owners cannot leave their own community. Transfer ownership first.',
            ], 422);
        }

        $membership = $this->memberships->leave($community, $request->user());

        return response()->json([
            'membership' => $membership,
            'message' => 'Left community',
        ]);
    }

    /**
     * Per-community discoverability. This does not change profile visibility or
     * a direct profile link; it only controls appearance in Discover and
     * recommendations for this community.
     */
    public function updateMembership(Community $community, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'discoverable' => ['required', 'boolean'],
        ]);

        $membership = CommunityMember::where('community_id', $community->id)
            ->where('user_id', $request->user()->id)
            ->where('status', CommunityMember::STATUS_ACTIVE)
            ->firstOrFail();

        $membership->forceFill(['discoverable' => $validated['discoverable']])->save();
        \Illuminate\Support\Facades\Cache::increment('matchmaking:version');

        return response()->json([
            'membership' => $membership->fresh(),
            'message' => $validated['discoverable']
                ? 'Profile is discoverable in this community.'
                : 'Profile is hidden from Discover and recommendations in this community.',
        ]);
    }
}
