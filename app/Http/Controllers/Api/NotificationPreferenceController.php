<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceService;
use App\Support\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function show(Request $request, NotificationPreferenceService $preferences): JsonResponse
    {
        return response()->json($preferences->settings($request->user()));
    }

    public function update(Request $request, NotificationPreferenceService $preferences): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['array'],
            'preferences.*.*' => ['boolean'],
        ]);

        return response()->json([
            'preferences' => $preferences->update($request->user(), $validated['preferences']),
            'message' => 'Notification preferences updated.',
        ]);
    }

    public function unsubscribe(
        User $user,
        string $type,
        string $channel,
        NotificationPreferenceService $preferences,
    ): JsonResponse {
        abort_unless(in_array($channel, [
            NotificationType::CHANNEL_DATABASE,
            NotificationType::CHANNEL_MAIL,
            NotificationType::CHANNEL_PUSH,
        ], true), 404);

        abort_unless(isset(NotificationType::all()[$type]), 404);

        $preferences->unsubscribe($user, $type, $channel);

        return response()->json([
            'message' => 'Notification preference updated.',
            'type' => $type,
            'channel' => $channel,
            'enabled' => false,
        ]);
    }
}
