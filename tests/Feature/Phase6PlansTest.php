<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Profile;
use App\Models\User;
use App\Support\PlanLimitKey;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6PlansTest extends TestCase
{
    use RefreshDatabase;

    protected function using(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function plan(array $attributes = [], array $entitlements = []): Plan
    {
        $plan = Plan::create(array_merge([
            'key' => 'phase6-' . uniqid(),
            'name' => 'Phase 6 Plan',
            'is_active' => true,
            'is_default' => false,
        ], $attributes));

        foreach ($entitlements as $key => $value) {
            PlanEntitlement::create([
                'plan_id' => $plan->id,
                'limit_key' => $key,
                'limit_value' => $value,
            ]);
        }

        return $plan;
    }

    public function test_plan_usage_snapshot_includes_limits_usage_and_upgrade_action(): void
    {
        $user = $this->member();
        $plan = $this->plan([
            'price' => 50,
            'currency' => 'GHS',
            'billing_interval' => 'month',
            'verification_mark' => true,
        ], [
            PlanLimitKey::PROFILE_COUNT => 2,
            PlanLimitKey::CATALOG_ITEMS => 0,
            PlanLimitKey::SIGNATURE_CARD_TEMPLATES => 1,
        ]);
        $user->forceFill(['plan_id' => $plan->id])->save();

        $response = $this->using($user->createToken('test')->plainTextToken)
            ->getJson('/api/plan')
            ->assertOk()
            ->assertJsonPath('current_plan_key', $plan->key)
            ->assertJsonPath('limits.PROFILE_COUNT.limit', 2)
            ->assertJsonPath('limits.CATALOG_ITEMS.disabled', true)
            ->assertJsonPath('upgrade_url', '/dashboard/plan');

        $this->assertTrue($response->json('verification.active'));
        $this->assertArrayHasKey('PROFILE_COUNT', $response->json('limits'));
        $this->assertArrayHasKey('SIGNATURE_CARD_TEMPLATES', $response->json('limits'));
    }

    public function test_entitlement_exception_includes_one_upgrade_action(): void
    {
        $user = $this->member();
        $plan = $this->plan([], [
            PlanLimitKey::CATALOG_ITEMS => 0,
        ]);
        $user->forceFill(['plan_id' => $plan->id])->save();

        $profile = $user->profiles()->create([
            'slug' => 'catalog-limit-profile',
            'profile_type' => 'business',
            'title' => 'Business',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
        ]);

        $this->using($user->createToken('test')->plainTextToken)
            ->postJson("/api/profiles/{$profile->id}/catalog", [
                'title' => 'Blocked by plan',
                'type' => 'service',
                'price_mode' => 'on_request',
                'cta_type' => 'enquire',
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('limit_key', 'CATALOG_ITEMS')
            ->assertJsonPath('upgrade.url', '/dashboard/plan');
    }

    public function test_daily_search_entitlement_is_enforced_and_usage_is_recorded(): void
    {
        $user = $this->member();
        $plan = $this->plan([], [
            PlanLimitKey::SEARCHES_PER_DAY => 1,
        ]);
        $user->forceFill(['plan_id' => $plan->id])->save();
        $token = $user->createToken('test')->plainTextToken;

        $this->using($token)->getJson('/api/search?q=one')->assertOk();

        $this->using($token)
            ->getJson('/api/search?q=two')
            ->assertStatus(422)
            ->assertJsonPath('limit_key', 'SEARCHES_PER_DAY')
            ->assertJsonPath('upgrade.url', '/dashboard/plan');

        $this->using($token)
            ->getJson('/api/plan')
            ->assertOk()
            ->assertJsonPath('limits.SEARCHES_PER_DAY.used', 1);
    }

    public function test_seeded_plans_have_no_invented_prices_or_limits(): void
    {
        $this->seed(PlanSeeder::class);

        $this->assertDatabaseHas('plans', ['key' => 'free', 'is_default' => true]);
        $this->assertDatabaseHas('plans', ['key' => 'standard']);
        $this->assertDatabaseHas('plans', ['key' => 'professional']);
        $this->assertDatabaseHas('plans', ['key' => 'organization']);

        $this->assertSame(0, PlanEntitlement::whereIn('plan_id', Plan::pluck('id'))->count());
        $this->assertTrue(Plan::whereIn('key', ['free', 'standard', 'professional', 'organization'])
            ->whereNotNull('price')
            ->doesntExist());
    }

    public function test_legacy_member_keeps_configured_access_when_free_plan_is_restrictive(): void
    {
        config(['roicard.plans.limits.PROFILE_COUNT' => 9]);
        $this->seed(PlanSeeder::class);

        $free = Plan::where('key', 'free')->firstOrFail();
        PlanEntitlement::create([
            'plan_id' => $free->id,
            'limit_key' => PlanLimitKey::PROFILE_COUNT,
            'limit_value' => 1,
        ]);

        $user = $this->member(['legacy_member' => true, 'legacy_member_since' => now()]);

        $this->assertSame(9, app(\App\Services\EntitlementService::class)
            ->limit($user, PlanLimitKey::PROFILE_COUNT));
    }
}
