<?php

namespace Tests\Feature;

use App\Exceptions\EntitlementExceededException;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\User;
use App\Services\EntitlementService;
use App\Support\PlanLimitKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Mvp2EntitlementTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        return User::factory()->create(['status' => 'active']);
    }

    public function test_config_fallback_lookup_and_enforcement(): void
    {
        config([
            'roicard.plans.limits.PROFILE_COUNT' => 2,
            'roicard.plans.limits.AI_CALLS_PER_DAY' => 0,
        ]);

        $user = $this->member();
        $service = app(EntitlementService::class);

        $this->assertSame(2, $service->limit($user, PlanLimitKey::PROFILE_COUNT));
        $this->assertTrue($service->allows($user, PlanLimitKey::PROFILE_COUNT, 1, 1));
        $this->assertFalse($service->allows($user, PlanLimitKey::PROFILE_COUNT, 2, 1));
        $this->assertSame(0, $service->limit($user, PlanLimitKey::AI_CALLS_PER_DAY));
        $this->assertFalse($service->allows($user, PlanLimitKey::AI_CALLS_PER_DAY));

        $this->expectException(EntitlementExceededException::class);
        $service->ensureWithinLimit($user, PlanLimitKey::PROFILE_COUNT, 2, 1);
    }

    public function test_plan_entitlements_override_config_and_null_means_unlimited(): void
    {
        config([
            'roicard.plans.limits.PROFILE_COUNT' => 10,
            'roicard.plans.limits.SEARCHES_PER_DAY' => 5,
        ]);

        $plan = Plan::create([
            'key' => 'starter',
            'name' => 'Starter',
            'is_active' => true,
        ]);

        PlanEntitlement::create([
            'plan_id' => $plan->id,
            'limit_key' => PlanLimitKey::PROFILE_COUNT,
            'limit_value' => 3,
        ]);

        PlanEntitlement::create([
            'plan_id' => $plan->id,
            'limit_key' => PlanLimitKey::SEARCHES_PER_DAY,
            'limit_value' => null,
        ]);

        $user = $this->member();
        $user->forceFill(['plan_id' => $plan->id])->save();

        $service = app(EntitlementService::class);

        $this->assertSame(3, $service->limit($user, PlanLimitKey::PROFILE_COUNT));
        $this->assertNull($service->limit($user, PlanLimitKey::SEARCHES_PER_DAY));
        $this->assertTrue($service->allows($user, PlanLimitKey::SEARCHES_PER_DAY, 9999, 1));
    }

    public function test_default_plan_is_used_when_user_has_no_assignment(): void
    {
        $plan = Plan::create([
            'key' => 'house',
            'name' => 'House Plan',
            'is_active' => true,
            'is_default' => true,
        ]);

        PlanEntitlement::create([
            'plan_id' => $plan->id,
            'limit_key' => PlanLimitKey::RECOMMENDATIONS_SHOWN,
            'limit_value' => 7,
        ]);

        $service = app(EntitlementService::class);
        $user = $this->member();

        $this->assertSame(7, $service->limit($user, PlanLimitKey::RECOMMENDATIONS_SHOWN));
        $this->assertSame($plan->id, $service->planFor($user)?->id);
    }

    public function test_every_specification_limit_key_is_supported(): void
    {
        $expected = [
            'ACTIVE_SEEKING_ITEMS',
            'ACTIVE_OFFERING_ITEMS',
            'PROFILE_COUNT',
            'CATALOG_ITEMS',
            'SEARCHES_PER_DAY',
            'CONNECTION_REQUESTS_PER_DAY',
            'RECOMMENDATIONS_SHOWN',
            'AI_CALLS_PER_DAY',
            'VERIFICATION_MARK',
            'COMMUNITIES_JOINED',
            'ORG_MEMBER_SEATS',
            'SIGNATURE_CARD_TEMPLATES',
            'REFERRAL_REWARDS',
        ];

        $this->assertSame($expected, PlanLimitKey::all());

        config(['roicard.plans.limits' => array_fill_keys($expected, 4)]);
        $service = app(EntitlementService::class);
        $user = $this->member();

        foreach ($expected as $key) {
            $this->assertSame(4, $service->limit($user, $key), $key);
        }
    }

    public function test_exception_exposes_limit_context_for_api_responses(): void
    {
        $exception = new EntitlementExceededException(PlanLimitKey::AI_CALLS_PER_DAY, 2, 2);

        $this->assertSame(PlanLimitKey::AI_CALLS_PER_DAY, $exception->limitKey);
        $this->assertSame(2, $exception->limit);
        $this->assertSame(2, $exception->currentUsage);

        $response = $exception->render(request());
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('AI_CALLS_PER_DAY', $response->getData(true)['limit_key']);
    }
}
