<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\SmartCard;
use App\Services\CardActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardActivationController extends Controller
{
    public function __construct(protected CardActivationService $cards) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'cards' => $this->cards->forUser($request->user()),
            'activation_fee' => (float) config('roicard.card_activation_fee', 75),
            'currency' => 'GHS',
        ]);
    }

    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profile_id' => ['required', 'integer', 'exists:profiles,id'],
            'card_id' => ['required', 'string', 'max:255'],
            'method' => ['nullable', 'string', 'max:40'],
            'momo_number' => ['nullable', 'string', 'max:40'],
        ]);

        $profile = $request->user()->profiles()->findOrFail($validated['profile_id']);
        $result = $this->cards->initiate(
            $request->user(),
            $profile,
            $validated['card_id'],
            $validated,
        );

        return response()->json([
            ...$result,
            'message' => 'Complete the card activation payment to bind this card.',
        ], 201);
    }

    public function deactivate(Request $request, SmartCard $smartCard): JsonResponse
    {
        $card = $this->cards->deactivate($request->user(), $smartCard);

        return response()->json([
            'card' => $card,
            'message' => 'Card deactivated and unbound from your profile.',
        ]);
    }
}
