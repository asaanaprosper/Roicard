<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\ProfileSignal;
use App\Services\EntitlementService;
use App\Services\ProfileSignalService;
use App\Support\PlanLimitKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileSignalController extends Controller
{
    public function __construct(
        protected ProfileSignalService $signals,
        protected EntitlementService $entitlements,
    ) {}

    public function index(Profile $profile): JsonResponse
    {
        return response()->json($this->payload($profile));
    }

    public function store(Profile $profile, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(ProfileSignal::types())],
            'description' => ['required', 'string', 'max:2000'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $signal = $this->signals->create($request->user(), $profile, $validated);

        return response()->json([
            'signal' => $signal,
            'onboarding' => app(\App\Services\OnboardingService::class)->status($request->user()),
            'message' => ucfirst($signal->type) . ' item created.',
        ], 201);
    }

    public function update(Profile $profile, ProfileSignal $signal, Request $request): JsonResponse
    {
        abort_unless((int) $signal->profile_id === (int) $profile->id, 404);

        $validated = $request->validate([
            'description' => ['sometimes', 'string', 'max:2000'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $signal = $this->signals->update($request->user(), $profile, $signal, $validated);

        return response()->json([
            'signal' => $signal,
            'onboarding' => app(\App\Services\OnboardingService::class)->status($request->user()),
            'message' => 'Signal updated.',
        ]);
    }

    public function pause(Profile $profile, ProfileSignal $signal, Request $request): JsonResponse
    {
        abort_unless((int) $signal->profile_id === (int) $profile->id, 404);

        return response()->json([
            'signal' => $this->signals->pause($request->user(), $profile, $signal),
            'message' => 'Signal paused. It will not participate in matching.',
        ]);
    }

    public function resume(Profile $profile, ProfileSignal $signal, Request $request): JsonResponse
    {
        abort_unless((int) $signal->profile_id === (int) $profile->id, 404);

        return response()->json([
            'signal' => $this->signals->resume($request->user(), $profile, $signal),
            'message' => 'Signal resumed.',
        ]);
    }

    public function destroy(Profile $profile, ProfileSignal $signal, Request $request): JsonResponse
    {
        abort_unless((int) $signal->profile_id === (int) $profile->id, 404);

        $this->signals->delete($request->user(), $profile, $signal);

        return response()->json(['message' => 'Signal deleted.']);
    }

    protected function payload(Profile $profile): array
    {
        $user = $profile->user;
        $list = $this->signals->list($profile);
        $seekingActive = $list['seeking']->where('is_active', true)->count();
        $offeringActive = $list['offering']->where('is_active', true)->count();

        return [
            'seeking' => $list['seeking'],
            'offering' => $list['offering'],
            'counts' => [
                'seeking' => $list['seeking']->count(),
                'offering' => $list['offering']->count(),
                'active_seeking' => $seekingActive,
                'active_offering' => $offeringActive,
            ],
            'limits' => [
                'active_seeking' => $this->entitlements->limit($user, PlanLimitKey::ACTIVE_SEEKING_ITEMS),
                'active_offering' => $this->entitlements->limit($user, PlanLimitKey::ACTIVE_OFFERING_ITEMS),
            ],
        ];
    }
}
