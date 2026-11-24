<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberBlock;
use App\Models\Profile;
use App\Models\User;
use App\Services\BlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockController extends Controller
{
    public function __construct(protected BlockService $blocks) {}

    public function index(Request $request): JsonResponse
    {
        $blockedIds = $this->blocks->blockedByUser($request->user());

        $users = User::query()
            ->whereIn('id', $blockedIds)
            ->with(['profile:id,user_id,slug,display_name,title,organisation', 'profile.media'])
            ->get(['id', 'first_name', 'last_name']);

        return response()->json(['blocked' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'profile_id' => ['nullable', 'integer', 'exists:profiles,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $targetUserId = $validated['user_id'] ?? null;
        if (!$targetUserId && !empty($validated['profile_id'])) {
            $targetUserId = (int) Profile::whereKey($validated['profile_id'])->value('user_id');
        }

        if (!$targetUserId) {
            return response()->json([
                'message' => 'Choose a member or profile to block.',
                'errors' => ['user_id' => ['Choose a member or profile to block.']],
            ], 422);
        }

        $block = $this->blocks->block($request->user(), (int) $targetUserId, $validated['reason'] ?? null);

        return response()->json([
            'block' => $block,
            'message' => 'Member blocked.',
        ], 201);
    }

    public function destroy(int $userId, Request $request): JsonResponse
    {
        $removed = $this->blocks->unblock($request->user(), $userId);

        return response()->json([
            'removed' => $removed,
            'message' => $removed ? 'Member unblocked.' : 'No block was found.',
        ]);
    }
}
