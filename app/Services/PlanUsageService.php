<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Community;
use App\Models\Connection;
use App\Models\Plan;
use App\Models\ProfileSignal;
use App\Models\Referral;
use App\Models\SignatureCard;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PlanLimitKey;

class PlanUsageService
{
    public function __construct(
        protected EntitlementService $entitlements,
        protected EntitlementUsageService $usage,
        protected PaymentService $payments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(User $user): array
    {
        $limits = $this->entitlements->allLimits($user);
        $usage = $this->usageFor($user);
        $plan = $this->entitlements->planFor($user);

        $items = [];
        foreach (PlanLimitKey::all() as $key) {
            $used = $usage[$key] ?? 0;
            $limit = $limits[$key];
            $items[$key] = [
                'key' => $key,
                'limit' => $limit,
                'used' => $used,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
                'unlimited' => $limit === null,
                'disabled' => $limit === 0,
            ];
        }

        $subscription = $user->subscriptions()
            ->with('plan')
            ->latest('id')
            ->first();

        return [
            'plan' => $plan ? $this->planPayload($plan) : null,
            'current_plan_key' => $plan?->key,
            'subscription' => $subscription ? $this->subscriptionPayload($subscription) : null,
            'verification' => [
                'active' => $this->entitlements->hasVerificationMark($user),
                'label' => $this->entitlements->verificationLabel(),
            ],
            'legacy_member' => (bool) $user->legacy_member,
            'limits' => $items,
            'plans' => Plan::query()
                ->active()
                ->with('entitlements')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Plan $candidate) => [
                    ...$this->planPayload($candidate),
                    'is_current' => $plan && $candidate->id === $plan->id,
                    'upgrade_available' => $this->payments->configured()
                        && $candidate->hasConfiguredPrice(),
                    'pricing_configured' => $candidate->hasConfiguredPrice(),
                ])
                ->values()
                ->all(),
            'provider' => [
                'name' => $this->payments->provider(),
                'configured' => $this->payments->configured(),
            ],
            'upgrade_url' => '/dashboard/plan',
            'billing_url' => '/dashboard/billing',
        ];
    }

    /**
     * @return array<string, int>
     */
    public function usageFor(User $user): array
    {
        $profileIds = $user->profiles()->pluck('id')->all();

        $activeSignals = fn (string $type) => $profileIds === []
            ? 0
            : ProfileSignal::whereIn('profile_id', $profileIds)
                ->whereNull('catalog_item_id')
                ->where('type', $type)
                ->where('is_active', true)
                ->count();

        $catalogActive = $profileIds === []
            ? 0
            : CatalogItem::whereIn('profile_id', $profileIds)->where('is_active', true)->count();

        $ownedOrganizationIds = Community::where('owner_user_id', $user->id)
            ->where('type', Community::TYPE_ORGANIZATION)
            ->pluck('id');

        $orgSeats = $ownedOrganizationIds->isEmpty()
            ? 0
            : \App\Models\CommunityMember::whereIn('community_id', $ownedOrganizationIds)
                ->where('status', \App\Models\CommunityMember::STATUS_ACTIVE)
                ->count();

        $rewardEntries = Referral::where('referrer_user_id', $user->id)
            ->whereIn('status', [
                Referral::STATUS_QUALIFIED,
                Referral::STATUS_AVAILABLE,
                Referral::STATUS_PAID,
            ])
            ->where('qualified_at', '>=', now()->startOfMonth())
            ->whereHas('rewardEntries')
            ->count();

        return [
            PlanLimitKey::PROFILE_COUNT => $user->profiles()->count(),
            PlanLimitKey::ACTIVE_SEEKING_ITEMS => $activeSignals(ProfileSignal::TYPE_SEEKING),
            PlanLimitKey::ACTIVE_OFFERING_ITEMS => $activeSignals(ProfileSignal::TYPE_OFFERING),
            PlanLimitKey::CATALOG_ITEMS => $catalogActive,
            PlanLimitKey::SEARCHES_PER_DAY => $this->usage->used($user, PlanLimitKey::SEARCHES_PER_DAY),
            PlanLimitKey::CONNECTION_REQUESTS_PER_DAY => Connection::query()
                ->where('request_type', Connection::TYPE_MEMBER)
                ->where('guest_user_id', $user->id)
                ->whereDate('created_at', now()->toDateString())
                ->count(),
            PlanLimitKey::RECOMMENDATIONS_SHOWN => 0,
            PlanLimitKey::AI_CALLS_PER_DAY => $this->usage->used($user, PlanLimitKey::AI_CALLS_PER_DAY),
            PlanLimitKey::COMMUNITIES_JOINED => $user->communityMemberships()
                ->where('status', \App\Models\CommunityMember::STATUS_ACTIVE)
                ->count(),
            PlanLimitKey::ORG_MEMBER_SEATS => $orgSeats,
            PlanLimitKey::SIGNATURE_CARD_TEMPLATES => $this->entitlements
                ->limit($user, PlanLimitKey::SIGNATURE_CARD_TEMPLATES) === 0
                ? 0
                : count(SignatureCard::templates()),
            PlanLimitKey::REFERRAL_REWARDS => $rewardEntries,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function planPayload(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'key' => $plan->key,
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'billing_interval' => $plan->billing_interval,
            'verification_mark' => (bool) $plan->verification_mark,
            'sort_order' => $plan->sort_order,
            'entitlements' => $plan->entitlements->pluck('limit_value', 'limit_key')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function subscriptionPayload(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'plan' => $subscription->plan?->only(['id', 'key', 'name']),
            'provider' => $subscription->provider,
            'current_period_start' => $subscription->current_period_start,
            'current_period_end' => $subscription->current_period_end,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'cancelled_at' => $subscription->cancelled_at,
            'ended_at' => $subscription->ended_at,
        ];
    }
}
