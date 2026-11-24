<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmartCard;
use App\Services\AuditLogger;
use App\Services\CardActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCardController extends Controller
{
    public function __construct(protected CardActivationService $cards) {}

    public function replace(Request $request, SmartCard $smartCard, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'new_card_id' => ['required', 'string', 'max:255', 'exists:smart_cards,card_id'],
            'profile_id' => ['nullable', 'integer', 'exists:profiles,id'],
        ]);

        $newCard = $this->cards->replace(
            $request->user(),
            $smartCard,
            $validated['new_card_id'],
            $validated['profile_id'] ?? null,
        );

        $audit->log('smart_card.replaced', $newCard, [
            'old_card_id' => $smartCard->card_id,
            'new_card_id' => $newCard->card_id,
            'profile_id' => $newCard->profile_id,
            'user_id' => $newCard->user_id,
        ]);

        return response()->json([
            'old_card' => $smartCard->fresh(),
            'new_card' => $newCard->fresh()->load('profile:id,slug,display_name'),
            'message' => 'Card replaced and bound to the same profile.',
        ]);
    }
}
