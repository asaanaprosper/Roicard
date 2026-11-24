<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessBrandingController extends Controller
{
    public function uploadLogo(Request $request, Profile $profile): JsonResponse
    {
        return $this->store($request, $profile, 'logo');
    }

    public function uploadCover(Request $request, Profile $profile): JsonResponse
    {
        return $this->store($request, $profile, 'cover');
    }

    protected function store(Request $request, Profile $profile, string $collection): JsonResponse
    {
        abort_unless($profile->isBusiness(), 422, 'Branding images are only available on business profiles.');

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
        ]);

        $profile->addMedia($request->file('image'))
            ->toMediaCollection($collection);

        $profile->bustPublicCache();

        return response()->json([
            'url' => $collection === 'logo' ? $profile->fresh()->logo_url : $profile->fresh()->cover_url,
            'message' => $collection === 'logo' ? 'Business logo updated.' : 'Business cover updated.',
        ]);
    }
}
