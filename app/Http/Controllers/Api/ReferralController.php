<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function __construct(protected ReferralService $referrals) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->referrals->summary($request->user()));
    }

    public function settings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'signature_referral_enabled' => ['required', 'boolean'],
        ]);

        $request->user()->forceFill([
            'referral_signature_enabled' => $validated['signature_referral_enabled'],
        ])->save();

        return response()->json([
            'signature_referral_enabled' => (bool) $request->user()->fresh()->referral_signature_enabled,
            'message' => 'Signature referral attribution updated.',
        ]);
    }
}
