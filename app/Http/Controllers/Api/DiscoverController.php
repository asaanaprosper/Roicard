<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Services\MatchmakingService;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscoverController extends Controller
{
    public function __construct(
        protected MatchmakingService $matchmaking,
        protected SearchService $search,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->selectedProfile($request);

        $recommendations = $this->matchmaking->recommendations($user, $profile);

        $communityIds = $user->communityMemberships()
            ->active()
            ->pluck('community_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $communityPeople = $communityIds === []
            ? []
            : $this->search->search($user, [
                'community_ids' => $communityIds,
                'per_page' => 12,
            ])->items();

        $network = $this->search->search($user, ['per_page' => 12])->items();

        return response()->json([
            'profile_id' => $profile->id,
            'recommendations' => $recommendations,
            'community_people' => $communityPeople,
            'network' => $network,
            'has_communities' => $communityIds !== [],
        ]);
    }

    protected function selectedProfile(Request $request): Profile
    {
        $profileId = $request->integer('profile_id');

        if ($profileId) {
            $profile = $request->user()->profiles()->findOrFail($profileId);
            return $profile;
        }

        return $request->user()->defaultProfile()
            ?? abort(422, 'Create a profile before using Discover.');
    }
}
