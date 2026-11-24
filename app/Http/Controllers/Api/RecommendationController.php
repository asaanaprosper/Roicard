<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Services\MatchmakingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function __construct(protected MatchmakingService $matchmaking) {}

    public function index(Request $request): JsonResponse
    {
        $profile = $this->selectedProfile($request);

        return response()->json([
            'profile_id' => $profile->id,
            ...$this->matchmaking->recommendations($request->user(), $profile),
        ]);
    }

    public function dismiss(Request $request, Profile $recommended): JsonResponse
    {
        $profile = $this->selectedProfile($request);

        if ((int) $profile->id === (int) $recommended->id) {
            abort(422, 'You cannot dismiss your own profile.');
        }

        $this->matchmaking->dismiss($profile, $recommended, 'dismissed');

        return response()->json(['message' => 'Recommendation dismissed for 90 days.']);
    }

    public function notRelevant(Request $request, Profile $recommended): JsonResponse
    {
        $profile = $this->selectedProfile($request);

        if ((int) $profile->id === (int) $recommended->id) {
            abort(422, 'You cannot provide feedback on your own profile.');
        }

        $validated = $request->validate([
            'category_pair_keys' => ['nullable', 'array'],
            'category_pair_keys.*' => ['string', 'max:60'],
        ]);

        $this->matchmaking->notRelevant(
            $profile,
            $recommended,
            $validated['category_pair_keys'] ?? [],
        );

        return response()->json(['message' => 'Thanks — we will show fewer matches like this.']);
    }

    protected function selectedProfile(Request $request): Profile
    {
        $profileId = $request->integer('profile_id') ?: $request->input('profile_id');

        if ($profileId) {
            return $request->user()->profiles()->findOrFail($profileId);
        }

        return $request->user()->defaultProfile()
            ?? abort(422, 'Create a profile before requesting recommendations.');
    }
}
