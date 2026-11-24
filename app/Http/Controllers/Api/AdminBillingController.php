<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingEvent;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminBillingController extends Controller
{
    public function payments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['pending', 'success', 'failed', 'refunded'])],
            'purpose' => ['nullable', 'string', Rule::in(['membership', 'subscription', 'card_activation'])],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Payment::query()
            ->with(['user:id,first_name,last_name,email', 'plan:id,key,name'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['purpose'] ?? null, fn ($q, $purpose) => $q->where('purpose', $purpose));

        if (! empty($validated['q'])) {
            $like = '%' . addcslashes(trim($validated['q']), '%_\\') . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('provider_reference', 'like', $like)
                    ->orWhereHas('user', fn ($user) => $user
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like));
            });
        }

        return response()->json($query->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 25)));
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([
                Subscription::STATUS_PENDING,
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_PAST_DUE,
                Subscription::STATUS_CANCELLED,
                Subscription::STATUS_EXPIRED,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Subscription::query()
            ->with(['user:id,first_name,last_name,email', 'plan:id,key,name,price,currency'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        return response()->json($query->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 25)));
    }

    public function events(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(
            BillingEvent::query()
                ->with(['user:id,first_name,last_name,email'])
                ->orderByDesc('created_at')
                ->paginate((int) ($validated['per_page'] ?? 25))
        );
    }
}
