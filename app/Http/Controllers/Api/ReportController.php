<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\MemberReport;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profile_id' => ['nullable', 'integer', 'exists:profiles,id'],
            'connection_id' => ['nullable', 'integer', 'exists:connections,id'],
            'reason_category' => ['required', 'string', Rule::in(MemberReport::REASONS)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        if (empty($validated['profile_id']) && empty($validated['connection_id'])) {
            return response()->json([
                'message' => 'Choose a profile or connection to report.',
                'errors' => ['profile_id' => ['Choose a profile or connection to report.']],
            ], 422);
        }

        $reporter = $request->user();
        $profileId = isset($validated['profile_id']) ? (int) $validated['profile_id'] : null;
        $connectionId = isset($validated['connection_id']) ? (int) $validated['connection_id'] : null;
        $reportedUserId = null;

        if ($connectionId) {
            $connection = Connection::findOrFail($connectionId);
            abort_unless(
                in_array((int) $reporter->id, [(int) $connection->member_id, (int) $connection->guest_user_id], true),
                403
            );

            $reporterIsMember = (int) $connection->member_id === (int) $reporter->id;
            $reportedUserId = $reporterIsMember
                ? (int) $connection->guest_user_id
                : (int) $connection->member_id;

            if ($reportedUserId === 0) {
                return response()->json(['message' => 'You cannot report this member.'], 422);
            }

            $counterpartProfileId = $reporterIsMember
                ? $connection->sender_profile_id
                : $connection->recipient_profile_id;

            // If both a connection and a profile are supplied, the profile
            // must belong to the other party in that connection. This prevents
            // a report from mixing a legitimate connection id with an unrelated
            // member's profile id.
            if ($profileId !== null) {
                $profile = Profile::findOrFail($profileId);

                if ((int) $profile->user_id !== $reportedUserId) {
                    return response()->json([
                        'message' => 'The report target does not match this connection.',
                    ], 422);
                }
            } else {
                $profileId = $counterpartProfileId ? (int) $counterpartProfileId : null;

                if ($profileId !== null
                    && ! Profile::whereKey($profileId)->where('user_id', $reportedUserId)->exists()) {
                    $profileId = null;
                }
            }
        } elseif ($profileId !== null) {
            $profile = Profile::findOrFail($profileId);
            $reportedUserId = (int) $profile->user_id;
        }

        if (!$reportedUserId || $reportedUserId === (int) $reporter->id) {
            return response()->json(['message' => 'You cannot report this member.'], 422);
        }

        $report = MemberReport::create([
            'reporter_id' => $reporter->id,
            'reported_user_id' => $reportedUserId,
            'profile_id' => $profileId,
            'connection_id' => $connectionId,
            'reason_category' => $validated['reason_category'],
            'description' => $validated['description'] ?? null,
            'status' => MemberReport::STATUS_PENDING,
        ]);

        return response()->json([
            'report' => $report,
            'message' => 'Report submitted for review.',
        ], 201);
    }
}
