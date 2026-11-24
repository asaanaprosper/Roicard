<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Profile;
use App\Services\MatchmakingService;
use App\Services\MemberConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberConnectionController extends Controller
{
    public function __construct(
        protected MemberConnectionService $connections,
        protected MatchmakingService $matchmaking,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profile_id' => ['required', 'integer', 'exists:profiles,id'],
            'sender_profile_id' => ['nullable', 'integer', 'exists:profiles,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'match_reason' => ['nullable', 'string', 'max:1000'],
            'reason_codes' => ['nullable', 'array'],
            'reason_codes.*' => ['string', 'max:60'],
            'match_score' => ['nullable', 'numeric'],
        ]);

        $recipientProfile = Profile::findOrFail($validated['profile_id']);
        $senderProfile = null;

        if (!empty($validated['sender_profile_id'])) {
            $senderProfile = $request->user()->profiles()->findOrFail($validated['sender_profile_id']);
        }

        $connection = $this->connections->sendRequest(
            $request->user(),
            $recipientProfile,
            $validated['note'] ?? null,
            $validated['match_reason'] ?? null,
            $validated['reason_codes'] ?? [],
            $senderProfile,
        );

        $senderProfile ??= $request->user()->defaultProfile();
        if ($senderProfile) {
            $this->matchmaking->recordAction($senderProfile, $recipientProfile, 'connect', [
                'score' => $validated['match_score'] ?? null,
                'reason_codes' => $validated['reason_codes'] ?? null,
                'connection_id' => $connection->id,
            ]);
        }

        return response()->json([
            'connection' => $connection->load(['senderProfile', 'recipientProfile']),
            'message' => 'Connection request sent.',
        ], 201);
    }

    public function accept(Connection $connection, Request $request): JsonResponse
    {
        return response()->json([
            'connection' => $this->connections->accept($request->user(), $connection),
            'message' => 'Connection accepted.',
        ]);
    }

    public function decline(Connection $connection, Request $request): JsonResponse
    {
        return response()->json([
            'connection' => $this->connections->decline($request->user(), $connection),
            'message' => 'Connection request declined.',
        ]);
    }

    public function withdraw(Connection $connection, Request $request): JsonResponse
    {
        return response()->json([
            'connection' => $this->connections->withdraw($request->user(), $connection),
            'message' => 'Connection request withdrawn.',
        ]);
    }

    public function remove(Connection $connection, Request $request): JsonResponse
    {
        return response()->json([
            'connection' => $this->connections->remove($request->user(), $connection),
            'message' => 'Connection removed.',
        ]);
    }
}
