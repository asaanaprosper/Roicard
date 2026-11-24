<?php

namespace Tests\Feature;

use App\Models\BillingEvent;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PlanLimitKey;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6BillingTest extends TestCase
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

    private function profile(User $user, array $attributes = []): Profile
    {
        return $user->profiles()->create(array_merge([
            'slug' => 'billing-' . $user->id . '-' . uniqid(),
            'title' => 'Member',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
        ], $attributes));
    }

    private function paidPlan(bool $verified = true): Plan
    {
        return Plan::create([
            'key' => 'paid-' . uniqid(),
            'name' => 'Paid Plan',
            'is_active' => true,
            'is_default' => false,
            'price' => 100,
            'currency' => 'GHS',
            'billing_interval' => 'month',
            'verification_mark' => $verified,
        ]);
    }

    public function test_subscription_activation_cancellation_and_lapse_return_to_free(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = $this->paidPlan();
        $user = $this->member();
        $profile = $this->profile($user);
        $token = $user->createToken('test')->plainTextToken;

        $initiate = $this->using($token)
            ->postJson('/api/billing/subscribe', ['plan_id' => $plan->id])
            ->assertCreated();

        $reference = $initiate->json('payment.provider_reference');
        $this->assertSame(Payment::PURPOSE_SUBSCRIPTION, $initiate->json('payment.purpose'));

        $this->using($token)
            ->postJson('/api/payments/mock/confirm', ['reference' => $reference])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $user->refresh();
        $this->assertSame($plan->id, $user->plan_id);
        $this->assertTrue(
            Subscription::where('user_id', $user->id)->where('status', Subscription::STATUS_ACTIVE)->first()?->isActive() ?? false
        );
        $this->assertTrue(app(\App\Services\EntitlementService::class)->hasVerificationMark($user));

        $public = $this->getJson('/api/public/' . $profile->slug)->assertOk();
        $this->assertTrue($public->json('verified_member'));

        $this->using($token)
            ->postJson('/api/billing/cancel')
            ->assertOk()
            ->assertJsonPath('subscription.cancel_at_period_end', true);

        $this->travel(32)->days();
        $this->artisan('billing:process')->assertSuccessful();

        $user->refresh();
        $this->assertSame('free', $user->plan?->key);
        $this->assertFalse(app(\App\Services\EntitlementService::class)->hasVerificationMark($user));
        $this->assertDatabaseHas('profiles', ['id' => $profile->id]);

        $this->getJson('/api/public/' . $profile->slug)
            ->assertOk()
            ->assertJsonPath('verified_member', false);
    }

    public function test_webhook_is_verified_and_replay_is_idempotent(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = $this->paidPlan();
        $user = $this->member();
        $token = $user->createToken('test')->plainTextToken;

        $initiate = $this->using($token)
            ->postJson('/api/billing/subscribe', ['plan_id' => $plan->id])
            ->assertCreated();
        $reference = $initiate->json('payment.provider_reference');

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'id' => 'evt_phase6_replay',
                'reference' => $reference,
            ],
        ];

        $this->postJson('/api/payments/webhook/mock', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', false);

        $this->postJson('/api/payments/webhook/mock', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, BillingEvent::where('provider_event_id', 'evt_phase6_replay')->count());
        $this->assertSame(1, Subscription::where('user_id', $user->id)->count());
        $this->assertSame($plan->id, $user->fresh()->plan_id);
    }

    public function test_renewal_webhook_extends_the_subscription_period(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = $this->paidPlan();
        $user = $this->member();
        $token = $user->createToken('test')->plainTextToken;

        $initiate = $this->using($token)
            ->postJson('/api/billing/subscribe', ['plan_id' => $plan->id])
            ->assertCreated();
        $this->using($token)->postJson('/api/payments/mock/confirm', [
            'reference' => $initiate->json('payment.provider_reference'),
        ])->assertOk();

        $subscription = Subscription::where('user_id', $user->id)->firstOrFail();
        $subscription->forceFill(['provider_subscription_id' => 'sub_phase6_renew'])->save();
        $periodEnd = $subscription->current_period_end;

        $this->postJson('/api/payments/webhook/mock', [
            'event' => 'invoice.payment_success',
            'data' => [
                'id' => 'evt_phase6_renewal',
                'reference' => 'renewal-reference-1',
                'subscription_code' => 'sub_phase6_renew',
                'amount' => 10000,
            ],
        ])->assertOk()->assertJsonPath('duplicate', false);

        $this->assertTrue(
            Subscription::findOrFail($subscription->id)->current_period_end->greaterThan($periodEnd)
        );
        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'purpose' => 'subscription',
            'provider_reference' => 'renewal-reference-1',
            'status' => 'success',
        ]);
        $this->assertSame(1, BillingEvent::where('event_type', 'subscription.renewed')->count());
        $this->assertSame(1, BillingEvent::where('event_type', 'subscription.activated')->count());
    }

    public function test_cancellation_ends_as_cancelled_at_period_end_and_preserves_member_data(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = $this->paidPlan();
        $user = $this->member();
        $profile = $this->profile($user);
        $token = $user->createToken('test')->plainTextToken;

        $initiate = $this->using($token)
            ->postJson('/api/billing/subscribe', ['plan_id' => $plan->id])
            ->assertCreated();
        $this->using($token)->postJson('/api/payments/mock/confirm', [
            'reference' => $initiate->json('payment.provider_reference'),
        ])->assertOk();

        $this->using($token)
            ->postJson('/api/billing/cancel')
            ->assertOk()
            ->assertJsonPath('subscription.cancel_at_period_end', true);

        $this->travel(32)->days();
        $this->artisan('billing:process')->assertSuccessful();

        $subscription = Subscription::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->status);
        $this->assertNotNull($subscription->ended_at);
        $this->assertFalse($subscription->cancel_at_period_end);

        $user->refresh();
        $this->assertSame('free', $user->plan?->key);
        $this->assertDatabaseHas('profiles', ['id' => $profile->id]);
        $this->assertDatabaseHas('billing_events', ['event_type' => 'subscription.cancelled']);
    }

    public function test_provider_expiry_event_does_not_revoke_paid_time_early(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = $this->paidPlan();
        $user = $this->member();
        $token = $user->createToken('test')->plainTextToken;

        $initiate = $this->using($token)
            ->postJson('/api/billing/subscribe', ['plan_id' => $plan->id])
            ->assertCreated();
        $this->using($token)->postJson('/api/payments/mock/confirm', [
            'reference' => $initiate->json('payment.provider_reference'),
        ])->assertOk();

        $subscription = Subscription::where('user_id', $user->id)->firstOrFail();
        $subscription->forceFill(['provider_subscription_id' => 'sub_provider_expired'])->save();
        $periodEnd = $subscription->current_period_end;

        $this->postJson('/api/payments/webhook/mock', [
            'event' => 'subscription.expired',
            'data' => [
                'id' => 'evt_provider_expired',
                'subscription_code' => 'sub_provider_expired',
            ],
        ])->assertOk()->assertJsonPath('duplicate', false);

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertTrue($subscription->current_period_end->equalTo($periodEnd));
        $this->assertSame($plan->id, $user->fresh()->plan_id);

        $this->travel(32)->days();
        $this->artisan('billing:process')->assertSuccessful();

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->status);
        $this->assertSame('free', $user->fresh()->plan?->key);
    }

    public function test_grace_period_keeps_access_then_expires_to_free_without_deleting_data(): void
    {
        config(['roicard.billing.grace_days' => 3]);
        $this->seed(PlanSeeder::class);
        $plan = $this->paidPlan();
        $user = $this->member();
        $profile = $this->profile($user);
        $token = $user->createToken('test')->plainTextToken;

        $initiate = $this->using($token)
            ->postJson('/api/billing/subscribe', ['plan_id' => $plan->id])
            ->assertCreated();
        $this->using($token)->postJson('/api/payments/mock/confirm', [
            'reference' => $initiate->json('payment.provider_reference'),
        ])->assertOk();

        $this->travel(32)->days();
        $this->artisan('billing:process')->assertSuccessful();

        $subscription = Subscription::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
        $this->assertNotNull($subscription->metadata['past_due_at'] ?? null);
        $this->assertSame($plan->id, $user->fresh()->plan_id);
        $this->assertTrue(app(\App\Services\EntitlementService::class)->hasVerificationMark($user->fresh()));
        $this->assertDatabaseMissing('billing_events', ['event_type' => 'subscription.expired']);

        $this->travel(4)->days();
        $this->artisan('billing:process')->assertSuccessful();

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_EXPIRED, $subscription->status);
        $this->assertNotNull($subscription->ended_at);

        $user->refresh();
        $this->assertSame('free', $user->plan?->key);
        $this->assertFalse(app(\App\Services\EntitlementService::class)->hasVerificationMark($user));
        $this->assertDatabaseHas('profiles', ['id' => $profile->id]);
        $this->assertDatabaseHas('billing_events', ['event_type' => 'subscription.grace_expired']);
    }

    public function test_failed_renewal_enters_grace_and_a_verified_recovery_renews_the_subscription(): void
    {
        config(['roicard.billing.grace_days' => 3]);
        $this->seed(PlanSeeder::class);
        $plan = $this->paidPlan();
        $user = $this->member();
        $token = $user->createToken('test')->plainTextToken;

        $initiate = $this->using($token)
            ->postJson('/api/billing/subscribe', ['plan_id' => $plan->id])
            ->assertCreated();
        $this->using($token)->postJson('/api/payments/mock/confirm', [
            'reference' => $initiate->json('payment.provider_reference'),
        ])->assertOk();

        $subscription = Subscription::where('user_id', $user->id)->firstOrFail();
        $subscription->forceFill(['provider_subscription_id' => 'sub_failed_renewal'])->save();

        $this->postJson('/api/payments/webhook/mock', [
            'event' => 'invoice.payment_failed',
            'data' => [
                'id' => 'evt_failed_renewal',
                'reference' => 'renewal-fail-1',
                'subscription_code' => 'sub_failed_renewal',
                'amount' => 10000,
            ],
        ])->assertOk()->assertJsonPath('duplicate', false);

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
        $this->assertSame($plan->id, $user->fresh()->plan_id);
        $this->assertDatabaseHas('payments', [
            'provider_reference' => 'renewal-fail-1',
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('billing_events', ['event_type' => 'subscription.payment_failed']);

        $this->postJson('/api/payments/webhook/mock', [
            'event' => 'invoice.payment_success',
            'data' => [
                'id' => 'evt_recovered_renewal',
                'reference' => 'renewal-fail-1',
                'subscription_code' => 'sub_failed_renewal',
                'amount' => 10000,
            ],
        ])->assertOk()->assertJsonPath('duplicate', false);

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertArrayNotHasKey('past_due_at', $subscription->metadata ?? []);
        $this->assertSame($plan->id, $user->fresh()->plan_id);
        $this->assertDatabaseHas('payments', [
            'provider_reference' => 'renewal-fail-1',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('billing_events', ['event_type' => 'subscription.renewed']);
    }

    public function test_failed_initial_subscription_payment_does_not_activate_and_can_be_retried(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = $this->paidPlan();
        $user = $this->member();
        $token = $user->createToken('test')->plainTextToken;

        $initiate = $this->using($token)
            ->postJson('/api/billing/subscribe', ['plan_id' => $plan->id])
            ->assertCreated();
        $reference = $initiate->json('payment.provider_reference');

        $this->postJson('/api/payments/webhook/mock', [
            'event' => 'charge.failed',
            'data' => ['id' => 'evt_failed_initial', 'reference' => $reference],
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'provider_reference' => $reference,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('billing_events', ['event_type' => 'payment.failed']);
        $this->assertSame(0, Subscription::where('user_id', $user->id)->count());
        $this->assertNotSame($plan->id, $user->fresh()->plan_id);

        $retry = $this->using($token)
            ->postJson('/api/billing/subscribe', ['plan_id' => $plan->id])
            ->assertCreated();
        $this->assertSame('pending', $retry->json('payment.status'));
        $retryReference = $retry->json('payment.provider_reference');
        $this->assertNotSame($reference, $retryReference);

        $this->using($token)->postJson('/api/payments/mock/confirm', [
            'reference' => $retryReference,
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame(1, Subscription::where('user_id', $user->id)->count());
        $this->assertSame($plan->id, $user->fresh()->plan_id);
    }

    public function test_webhook_from_a_different_provider_is_rejected(): void
    {
        config(['app.payment_provider' => 'mock']);

        $this->postJson('/api/payments/webhook/paystack', [
            'event' => 'charge.success',
            'data' => ['id' => 'evt_wrong_provider', 'reference' => 'none'],
        ])->assertStatus(403);

        $this->assertDatabaseMissing('billing_events', [
            'provider_event_id' => 'evt_wrong_provider',
        ]);
    }

    public function test_verification_mark_limit_key_can_withhold_the_mark_on_a_paid_plan(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = $this->paidPlan(true);
        $user = $this->member();
        $profile = $this->profile($user);

        PlanEntitlement::create([
            'plan_id' => $plan->id,
            'limit_key' => PlanLimitKey::VERIFICATION_MARK,
            'limit_value' => 0,
        ]);
        $plan->forceFill(['verification_mark' => false])->save();
        $user->forceFill(['plan_id' => $plan->id])->save();

        $this->assertFalse(app(\App\Services\EntitlementService::class)->hasVerificationMark($user->fresh()));
        $this->getJson('/api/public/' . $profile->slug)->assertOk()->assertJsonPath('verified_member', false);

        PlanEntitlement::where('plan_id', $plan->id)
            ->where('limit_key', PlanLimitKey::VERIFICATION_MARK)
            ->update(['limit_value' => 1]);

        $user = $user->fresh();
        $user->unsetRelation('plan');
        $profile->bustPublicCache();

        $this->assertTrue(app(\App\Services\EntitlementService::class)->hasVerificationMark($user));
        $this->getJson('/api/public/' . $profile->slug)->assertOk()->assertJsonPath('verified_member', true);
    }

    public function test_legacy_member_keeps_configured_access_after_a_subscription_lapses(): void
    {
        config(['roicard.plans.limits.PROFILE_COUNT' => 9]);
        $this->seed(PlanSeeder::class);

        $free = Plan::where('key', 'free')->firstOrFail();
        PlanEntitlement::create([
            'plan_id' => $free->id,
            'limit_key' => PlanLimitKey::PROFILE_COUNT,
            'limit_value' => 1,
        ]);

        $plan = $this->paidPlan();
        $user = $this->member(['legacy_member' => true, 'legacy_member_since' => now()]);
        $profile = $this->profile($user);
        $token = $user->createToken('test')->plainTextToken;

        $initiate = $this->using($token)
            ->postJson('/api/billing/subscribe', ['plan_id' => $plan->id])
            ->assertCreated();
        $this->using($token)->postJson('/api/payments/mock/confirm', [
            'reference' => $initiate->json('payment.provider_reference'),
        ])->assertOk();

        $this->using($token)->postJson('/api/billing/cancel')->assertOk();
        $this->travel(32)->days();
        $this->artisan('billing:process')->assertSuccessful();

        $user->refresh();
        $this->assertSame('free', $user->plan?->key);
        $this->assertTrue($user->legacy_member);
        $this->assertSame(9, app(\App\Services\EntitlementService::class)
            ->limit($user, PlanLimitKey::PROFILE_COUNT));
        $this->assertDatabaseHas('profiles', ['id' => $profile->id]);
    }

    public function test_verification_mark_applies_to_personal_and_business_profiles_only_when_plan_permits(): void
    {
        $this->seed(PlanSeeder::class);
        $verifiedPlan = $this->paidPlan(true);
        $user = $this->member();
        $user->forceFill(['plan_id' => $verifiedPlan->id])->save();

        $personal = $this->profile($user);
        $business = $this->profile($user, [
            'slug' => 'verified-business-' . $user->id,
            'profile_type' => 'business',
            'display_name' => 'Verified Business',
        ]);

        $this->getJson('/api/public/' . $personal->slug)->assertOk()->assertJsonPath('verified_member', true);
        $this->getJson('/api/public/' . $business->slug)->assertOk()->assertJsonPath('verified_member', true);

        $free = Plan::where('key', 'free')->firstOrFail();
        $user->forceFill(['plan_id' => $free->id])->save();
        $personal->bustPublicCache();
        $business->bustPublicCache();

        $this->getJson('/api/public/' . $personal->slug)->assertOk()->assertJsonPath('verified_member', false);
        $this->getJson('/api/public/' . $business->slug)->assertOk()->assertJsonPath('verified_member', false);
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        config([
            'app.payment_provider' => 'paystack',
            'services.paystack.secret_key' => 'test-secret',
        ]);

        $this->postJson('/api/payments/webhook/paystack', [
            'event' => 'charge.success',
            'data' => ['id' => 'bad-signature-event', 'reference' => 'none'],
        ], ['X-Paystack-Signature' => 'not-valid'])->assertStatus(403);

        $this->assertDatabaseMissing('billing_events', [
            'provider_event_id' => 'bad-signature-event',
        ]);
    }

    public function test_unconfigured_plan_pricing_cannot_start_a_charge(): void
    {
        $plan = Plan::create([
            'key' => 'unpriced-' . uniqid(),
            'name' => 'Unpriced',
            'is_active' => true,
            'price' => null,
            'currency' => null,
            'billing_interval' => null,
        ]);
        $user = $this->member();

        $this->using($user->createToken('test')->plainTextToken)
            ->postJson('/api/billing/subscribe', ['plan_id' => $plan->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan']);
    }

    public function test_billing_history_and_admin_review_endpoints(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = $this->paidPlan();
        $user = $this->member();
        $token = $user->createToken('test')->plainTextToken;
        $admin = $this->member(['role' => 'admin']);

        $this->using($token)
            ->postJson('/api/billing/subscribe', ['plan_id' => $plan->id])
            ->assertCreated();

        $this->using($token)
            ->getJson('/api/billing/history')
            ->assertOk()
            ->assertJsonPath('payments.0.purpose', 'subscription');

        $adminToken = $admin->createToken('test')->plainTextToken;
        $this->using($adminToken)
            ->getJson('/api/admin/billing/payments')
            ->assertOk()
            ->assertJsonPath('data.0.purpose', 'subscription');

        $this->using($adminToken)
            ->getJson('/api/admin/billing/subscriptions')
            ->assertOk();
    }
}
