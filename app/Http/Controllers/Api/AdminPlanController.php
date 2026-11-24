<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\AuditLogger;
use App\Services\EntitlementService;
use App\Support\PlanLimitKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminPlanController extends Controller
{
    public function __construct(protected EntitlementService $entitlements) {}

    public function index(): JsonResponse
    {
        $plans = Plan::query()
            ->with('entitlements')
            ->withCount('users')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'plans' => $plans,
            'limit_keys' => PlanLimitKey::all(),
            'configured_defaults' => collect(PlanLimitKey::all())
                ->mapWithKeys(fn ($key) => [$key => $this->entitlements->configLimit($key)])
                ->all(),
            'default_plan_key' => config('roicard.plans.default_key'),
        ]);
    }

    public function show(Plan $plan): JsonResponse
    {
        $plan->load('entitlements')->loadCount('users');

        return response()->json([
            'plan' => $plan,
            'limit_keys' => PlanLimitKey::all(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $this->validated($request);

        $plan = DB::transaction(function () use ($validated) {
            if (! empty($validated['is_default'])) {
                Plan::query()->update(['is_default' => false]);
            }

            return Plan::create([
                'key' => $validated['key'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'is_default' => $validated['is_default'] ?? false,
                'sort_order' => $validated['sort_order'] ?? 0,
                'price' => $validated['price'] ?? null,
                'currency' => isset($validated['currency']) ? strtoupper($validated['currency']) : null,
                'billing_interval' => $validated['billing_interval'] ?? null,
                'verification_mark' => $validated['verification_mark'] ?? false,
                'metadata' => $validated['metadata'] ?? null,
            ]);
        });

        if (array_key_exists('entitlements', $validated)) {
            $this->assertEntitlementKeys(array_keys($validated['entitlements']));
            $this->entitlements->syncEntitlements($plan, $validated['entitlements']);
        }

        $audit->log('plan.created', $plan, [
            'key' => $plan->key,
            'is_default' => $plan->is_default,
        ]);

        return response()->json([
            'plan' => $plan->fresh()->load('entitlements'),
            'message' => 'Plan created successfully',
        ], 201);
    }

    public function update(Request $request, Plan $plan, AuditLogger $audit): JsonResponse
    {
        $validated = $this->validated($request, $plan->id);
        $before = $plan->only(['key', 'name', 'is_active', 'is_default', 'price', 'currency', 'billing_interval', 'verification_mark']);

        DB::transaction(function () use ($plan, $validated) {
            if (! empty($validated['is_default'])) {
                Plan::whereKeyNot($plan->id)->update(['is_default' => false]);
            }

            $plan->fill([
                'key' => $validated['key'] ?? $plan->key,
                'name' => $validated['name'] ?? $plan->name,
                'description' => array_key_exists('description', $validated) ? $validated['description'] : $plan->description,
                'is_active' => $validated['is_active'] ?? $plan->is_active,
                'is_default' => $validated['is_default'] ?? $plan->is_default,
                'sort_order' => $validated['sort_order'] ?? $plan->sort_order,
                'price' => array_key_exists('price', $validated) ? $validated['price'] : $plan->price,
                'currency' => array_key_exists('currency', $validated)
                    ? ($validated['currency'] ? strtoupper($validated['currency']) : null)
                    : $plan->currency,
                'billing_interval' => array_key_exists('billing_interval', $validated)
                    ? $validated['billing_interval']
                    : $plan->billing_interval,
                'verification_mark' => array_key_exists('verification_mark', $validated)
                    ? (bool) $validated['verification_mark']
                    : $plan->verification_mark,
                'metadata' => array_key_exists('metadata', $validated) ? $validated['metadata'] : $plan->metadata,
            ])->save();
        });

        if (array_key_exists('entitlements', $validated)) {
            $this->assertEntitlementKeys(array_keys($validated['entitlements']));
            $this->entitlements->syncEntitlements($plan, $validated['entitlements']);
        }

        $audit->log('plan.updated', $plan, [
            'before' => $before,
            'after' => $plan->only(['key', 'name', 'is_active', 'is_default', 'price', 'currency']),
        ]);

        if (($before['verification_mark'] ?? false) !== (bool) $plan->verification_mark) {
            \App\Models\Profile::query()
                ->whereHas('user', fn ($query) => $query->where('plan_id', $plan->id))
                ->get()
                ->each(fn (\App\Models\Profile $profile) => $profile->bustPublicCache());
        }

        return response()->json([
            'plan' => $plan->fresh()->load('entitlements'),
            'message' => 'Plan updated successfully',
        ]);
    }

    public function destroy(Plan $plan, AuditLogger $audit): JsonResponse
    {
        if ($plan->users()->exists()) {
            return response()->json([
                'message' => 'This plan is assigned to members. Reassign them before deleting it.',
            ], 422);
        }

        if (\App\Models\Subscription::where('plan_id', $plan->id)->exists()) {
            return response()->json([
                'message' => 'This plan has subscription history and cannot be deleted.',
            ], 422);
        }

        $snapshot = $plan->only(['key', 'name']);
        $plan->delete();

        $audit->log('plan.deleted', $plan, $snapshot);

        return response()->json(['message' => 'Plan deleted successfully']);
    }

    /**
     * Replace the plan's entitlement map. NULL means unlimited.
     */
    public function updateEntitlements(Request $request, Plan $plan, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'entitlements' => ['required', 'array'],
            'entitlements.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->assertEntitlementKeys(array_keys($validated['entitlements']));

        $before = $plan->entitlements()->pluck('limit_value', 'limit_key')->all();
        $this->entitlements->syncEntitlements($plan, $validated['entitlements']);

        $audit->log('plan.entitlements_updated', $plan, [
            'before' => $before,
            'after' => $plan->fresh()->load('entitlements')->entitlementMap(),
        ]);

        return response()->json([
            'plan' => $plan->fresh()->load('entitlements'),
            'message' => 'Plan entitlements updated successfully',
        ]);
    }

    /**
     * @param  array<int, string|int>  $keys
     */
    private function assertEntitlementKeys(array $keys): void
    {
        $invalid = array_values(array_filter($keys, fn ($key) => ! PlanLimitKey::isValid((string) $key)));

        if ($invalid !== []) {
            abort(response()->json([
                'message' => "Unknown entitlement key [{$invalid[0]}].",
                'errors' => ['entitlements' => ["Unknown entitlement key [{$invalid[0]}]."]],
            ], 422));
        }
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'key' => [
                $ignoreId ? 'sometimes' : 'required',
                'string',
                'max:60',
                'regex:/^[A-Z0-9_]+$/',
                Rule::unique('plans', 'key')->ignore($ignoreId),
            ],
            'name' => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'billing_interval' => ['nullable', 'string', Rule::in(['month', 'year'])],
            'verification_mark' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
            'entitlements' => ['sometimes', 'array'],
            'entitlements.*' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
