<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(protected OnboardingService $onboarding) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->onboarding->status($request->user()));
    }

    public function updateTask(Request $request, string $task): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['required', 'string', 'in:todo,done,skipped'],
        ]);

        return response()->json(
            $this->onboarding->updateTask($request->user(), $task, $validated['state'])
        );
    }

    public function saveGoals(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'goals' => ['present', 'array'],
            'goals.*' => ['string'],
        ]);

        return response()->json(
            $this->onboarding->saveGoals($request->user(), $validated['goals'])
        );
    }

    public function dismissGoals(Request $request): JsonResponse
    {
        return response()->json($this->onboarding->dismissGoals($request->user()));
    }

    public function dismissSetupCard(Request $request): JsonResponse
    {
        return response()->json($this->onboarding->dismissSetupCard($request->user()));
    }
}
