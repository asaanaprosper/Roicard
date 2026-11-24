<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileRequest;
use App\Models\Category;
use App\Models\Profile;
use App\Services\MemberAccessService;
use App\Services\MemberAccountService;
use App\Services\OnboardingService;
use App\Services\ProfileManager;
use App\Services\ProfilePublishingService;
use App\Services\ProfileSaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(): JsonResponse
    {
        $profile = auth()->user()->profile()->with([
            'education',
            'experience',
            'achievements',
            'socialLinks',
            'categories',
            'tags',
        ])->firstOrFail();

        $profile->load('user', 'media');

        return response()->json([
            'profile' => $profile,
        ]);
    }

    /**
     * All profiles owned by the authenticated account. The default profile
     * is surfaced explicitly so the client can label it.
     */
    public function index(): JsonResponse
    {
        $user = auth()->user();

        $profiles = $user->profiles()
            ->with(['categories', 'media'])
            ->withCount(['education', 'experience', 'achievements'])
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return response()->json([
            'profiles' => $profiles,
            'default_profile_id' => $user->defaultProfile()?->id,
        ]);
    }

    public function store(ProfileRequest $request): JsonResponse
    {
        $profile = auth()->user()->profile()->firstOrCreate([]);
        $profile->update($request->validated());

        if ($request->boolean('confirm_privacy')) {
            $profile->forceFill(['privacy_confirmed_at' => now()])->save();
        }

        return response()->json([
            'profile' => $profile->fresh(),
            'message' => 'Profile created successfully',
        ]);
    }

    /**
     * Create an additional personal or business profile. Enforces
     * PROFILE_COUNT through the centralized entitlement service.
     */
    public function storeProfile(ProfileRequest $request, ProfileManager $profiles): JsonResponse
    {
        $user = $request->user();

        $profile = $profiles->create($user, $request->validated());
        $profile = app(ProfileSaveService::class)->save($user, $request->validated(), $profile);

        return response()->json([
            'profile' => $this->profilePayload($profile),
            'message' => 'Profile created successfully',
        ], 201);
    }

    public function showProfile(Profile $profile): JsonResponse
    {
        $profile->load([
            'education',
            'experience',
            'achievements',
            'socialLinks',
            'categories',
            'tags',
            'media',
        ]);

        return response()->json([
            'profile' => $this->profilePayload($profile),
        ]);
    }

    public function publish(
        Profile $profile,
        ProfilePublishingService $publishing,
        OnboardingService $onboarding,
    ): JsonResponse {
        $profile = $publishing->publish(request()->user(), $profile);

        return response()->json([
            'profile' => $this->profilePayload($profile),
            'onboarding' => $onboarding->status(request()->user()),
            'message' => 'Profile published successfully',
        ]);
    }

    public function unpublish(
        Profile $profile,
        ProfilePublishingService $publishing,
        OnboardingService $onboarding,
    ): JsonResponse {
        $profile = $publishing->unpublish(request()->user(), $profile);

        return response()->json([
            'profile' => $this->profilePayload($profile),
            'onboarding' => $onboarding->status(request()->user()),
            'message' => 'Profile unpublished',
        ]);
    }

    public function requestAiDraft(
        Profile $profile,
        ProfilePublishingService $publishing,
    ): JsonResponse {
        $maxChars = max(1000, (int) config('roicard.ai.max_source_text_chars', 12000));

        $validated = request()->validate([
            'source_text' => ['nullable', 'string', 'max:' . $maxChars],
            'website_url' => ['nullable', 'url', 'max:2048'],
            'cv_media_id' => ['nullable', 'integer'],
        ]);

        $response = $publishing->requestAiDraft(request()->user(), $profile, $validated);

        return response()->json($response);
    }

    public function approveAiDraft(
        Profile $profile,
        ProfilePublishingService $publishing,
        OnboardingService $onboarding,
    ): JsonResponse {
        $validated = request()->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', 'in:headline,about,experience,skills,services,seeking,offering'],
        ]);

        $profile = $publishing->approveDraftFields(request()->user(), $profile, $validated['fields']);

        return response()->json([
            'profile' => $this->profilePayload($profile),
            'onboarding' => $onboarding->status(request()->user()),
            'message' => 'Approved fields applied to your profile',
        ]);
    }

    public function updateCategories(
        Profile $profile,
        OnboardingService $onboarding,
    ): JsonResponse {
        $validated = request()->validate([
            'seeking_category_ids' => ['sometimes', 'array'],
            'seeking_category_ids.*' => ['integer', 'exists:categories,id'],
            'offering_category_ids' => ['sometimes', 'array'],
            'offering_category_ids.*' => ['integer', 'exists:categories,id'],
        ]);

        $seekingIds = array_map('intval', $validated['seeking_category_ids'] ?? []);
        $offeringIds = array_map('intval', $validated['offering_category_ids'] ?? []);

        $seeking = Category::whereIn('id', $seekingIds)->get();
        $offering = Category::whereIn('id', $offeringIds)->get();

        if ($seeking->contains(fn (Category $category) => $category->kind !== Category::KIND_SEEKING)) {
            throw ValidationException::withMessages([
                'seeking_category_ids' => 'Only seeking categories can be selected here.',
            ]);
        }

        if ($offering->contains(fn (Category $category) => $category->kind !== Category::KIND_OFFERING)) {
            throw ValidationException::withMessages([
                'offering_category_ids' => 'Only offering categories can be selected here.',
            ]);
        }

        $profile->categories()->sync(
            $seeking->pluck('id')->merge($offering->pluck('id'))->unique()->all()
        );

        $profile->bustPublicCache();
        app(\App\Services\MatchmakingService::class)->invalidate();
        $profile = $profile->fresh()->load('categories');

        return response()->json([
            'profile' => $this->profilePayload($profile),
            'onboarding' => $onboarding->status(request()->user()),
            'message' => 'Profile categories updated',
        ]);
    }

    public function updateProfile(ProfileRequest $request, Profile $profile): JsonResponse
    {
        $profile = app(ProfileSaveService::class)->save($request->user(), $request->validated(), $profile);

        return response()->json([
            'profile' => $this->profilePayload($profile),
            'message' => 'Profile updated successfully',
        ]);
    }

    public function destroyProfile(Profile $profile, ProfileManager $profiles): JsonResponse
    {
        $profiles->delete(request()->user(), $profile);

        return response()->json([
            'message' => 'Profile deleted successfully',
            'default_profile_id' => request()->user()->defaultProfile()?->id,
        ]);
    }

    public function setDefaultProfile(Profile $profile, ProfileManager $profiles): JsonResponse
    {
        $profile = $profiles->setDefault(request()->user(), $profile);

        return response()->json([
            'profile' => $this->profilePayload($profile),
            'message' => 'Default profile updated successfully',
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $profile = auth()->user()->profile()->firstOrFail();

        return $this->storeAvatar($request, $profile);
    }

    public function uploadProfileAvatar(Request $request, Profile $profile): JsonResponse
    {
        return $this->storeAvatar($request, $profile);
    }

    protected function storeAvatar(Request $request, Profile $profile): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
        ]);

        $file = $request->file('avatar');
        $ext = $file->extension();
        $filename = "avatar.{$ext}";

        $profile->addMedia($file)
            ->usingFileName($filename)
            ->toMediaCollection('avatar');

        // Media writes don't save the profile row, so the observer doesn't
        // fire — bust the public cache here so the new avatar appears
        // immediately on the public profile.
        $profile->bustPublicCache();

        $url = $profile->getFirstMediaUrl('avatar');
        $versionedUrl = $url . '?v=' . now()->timestamp;

        return response()->json([
            'url' => $versionedUrl,
        ]);
    }

    public function update(ProfileRequest $request): JsonResponse
    {
        $user = auth()->user();
        $data = $request->validated();

        $profile = app(ProfileSaveService::class)->save($user, $data);

        // Kept for backwards compatibility with older clients that finish the
        // journey by saving the profile. Completion + the once-only welcome
        // email live in MemberAccountService, so every completion path (member,
        // payment, admin) stays consistent.
        app(MemberAccountService::class)->markOnboardingComplete($user, true, true);

        $profile->load('education', 'experience', 'achievements', 'socialLinks', 'media');
        $user->refresh();

        return response()->json([
            'profile' => $profile,
            'message' => 'Profile updated successfully',
            'user' => app(MemberAccessService::class)->payload($user),
        ]);
    }

    /**
     * Explicitly finishes the onboarding journey.
     *
     * The client must not decide completion locally: marking this on the
     * server is what lets `next_step` flip to `dashboard`, so a completed
     * member is never bounced back into onboarding.
     */
    public function complete(): JsonResponse
    {
        $user = auth()->user();
        $profile = $user->profile()->firstOrCreate([]);
        $profile->recalculateCompletion();

        // Sends the welcome email only on the first completion, with the
        // correct member/draft variant for the current membership state.
        app(MemberAccountService::class)->markOnboardingComplete($user, true, true);
        $user->refresh();

        return response()->json([
            'message' => 'Onboarding complete',
            'profile' => $profile->fresh(),
            'user' => app(MemberAccessService::class)->payload($user),
        ]);
    }

    protected function profilePayload(Profile $profile): array
    {
        $profile->loadMissing(['media', 'categories']);
        $payload = $profile->toArray();
        unset($payload['media']);

        return $payload;
    }
}
