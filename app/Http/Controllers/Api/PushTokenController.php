<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PushTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', Rule::in(['web', 'android', 'ios'])],
        ]);

        $existing = PushToken::where('token', $validated['token'])->first();

        if ($existing && (int) $existing->user_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'This push token is already registered to another account.',
            ], 422);
        }

        $pushToken = PushToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'] ?? 'web',
            ],
        );

        return response()->json([
            'push_token' => $pushToken->only(['id', 'platform', 'created_at']),
            'message' => 'Push token registered.',
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $deleted = PushToken::where('user_id', $request->user()->id)
            ->where('token', $validated['token'])
            ->delete();

        return response()->json([
            'removed' => $deleted > 0,
            'message' => $deleted > 0 ? 'Push token removed.' : 'Push token not found.',
        ]);
    }
}
