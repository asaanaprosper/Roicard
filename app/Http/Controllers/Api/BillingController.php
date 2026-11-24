<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(protected BillingService $billing) {}

    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'method' => ['nullable', 'string', 'max:40'],
            'momo_number' => ['nullable', 'string', 'max:40'],
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);
        $result = $this->billing->subscribe($request->user(), $plan, $validated);

        return response()->json([
            ...$result,
            'message' => $result['message'] ?? 'Complete the payment to activate your subscription.',
        ], 201);
    }

    public function cancel(Request $request): JsonResponse
    {
        $subscription = $this->billing->cancelAtPeriodEnd($request->user());

        return response()->json([
            'subscription' => $subscription,
            'message' => 'Your subscription will remain active until the end of the current period.',
        ]);
    }

    public function resume(Request $request): JsonResponse
    {
        $subscription = $this->billing->resume($request->user());

        return response()->json([
            'subscription' => $subscription,
            'message' => 'Subscription renewal resumed.',
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json($this->billing->history($request->user()));
    }
}
