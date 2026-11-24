<?php

namespace Tests\Feature;

use App\Models\AdminActionLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\ReferralRewardEntry;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6ReferralTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'roicard.referrals.enabled' => true,
            'roicard.referrals.attribution_days' => 30,
            'roicard.referrals.qualification_trigger' => 'membership_active',
            'roicard.referrals.reward_amount' => 50,
            'roicard.referrals.currency' => 'GHS',
            'roicard.referrals.monthly_reward_cap' => null,
            'roicard.referrals.total_budget' => null,
            'roicard.referrals.budget_alert_threshold' => null,
            'roicard.referrals.refund_window_days' => null,
            'roicard.referrals.payout_enabled' => false,
        ]);
    }

    public function test_registration_with_a_referral_code_attributes_the_invitee(): void
    {
        $referrer = $this->member();
        $service = app(ReferralService::class);
        $code = $service->codeFor($referrer);

        $this->postJson('/api/auth/register', [
            'first_name' => 'Referral',
            'last_name' => 'Invitee',
            'email' => 'referral-invitee@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'privacy_consent' => true,
            'referral_code' => $code,
        ])->assertCreated();

        $invitee = User::where('email', 'referral-invitee@example.test')->firstOrFail();
        $referral = Referral::where('invitee_user_id', $invitee->id)->firstOrFail();

        $this->assertSame($referrer->id, $referral->referrer_user_id);
        $this->assertSame(Referral::STATUS_ATTRIBUTED, $referral->status);
        $this->assertSame($referrer->id, $invitee->fresh()->referred_by_user_id);
        $this->assertNotNull($invitee->fresh()->registration_ip);
    }

    public function test_attribution_qualification_and_append_only_reward_ledger(): void
    {
        $referrer = $this->member();
        $invitee = $this->member();
        $service = app(ReferralService::class);

        $referral = $service->attribute($invitee, $service->codeFor($referrer), '203.0.113.5');

        $this->assertNotNull($referral);
        $this->assertSame($referrer->id, $referral->referrer_user_id);
        $this->assertTrue($referral->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertSame($referrer->id, $invitee->fresh()->referred_by_user_id);

        $qualified = $service->qualifyInvitee($invitee, 'membership_active');
        $this->assertSame(Referral::STATUS_QUALIFIED, $qualified->status);
        $this->assertDatabaseHas('referral_reward_entries', [
            'referral_id' => $referral->id,
            'status' => ReferralRewardEntry::STATUS_PENDING,
            'amount' => 50,
        ]);

        // Repeated qualification never creates a second reward.
        $service->qualifyInvitee($invitee, 'membership_active');
        $this->assertSame(1, ReferralRewardEntry::where('referral_id', $referral->id)->count());

        $admin = $this->member(['role' => 'admin']);
        $service->markAvailable($qualified, $admin, 'Approved');
        $summary = $service->summary($referrer);
        $this->assertSame(50.0, $summary['balance']['available']);
        $this->assertSame(0.0, $summary['balance']['pending']);
        $this->assertSame(50.0, $summary['balance']['ledger_balance']);

        $voided = $service->void($qualified->fresh(), $admin, 'Fraudulent invite');
        $this->assertSame(ReferralRewardEntry::STATUS_VOIDED, $voided->status);
        $summaryAfter = $service->summary($referrer);
        $this->assertSame(0.0, $summaryAfter['balance']['ledger_balance']);
        $this->assertSame(0.0, $summaryAfter['balance']['available']);
        $this->assertSame(Referral::STATUS_VOIDED, $qualified->fresh()->status);
    }

    public function test_self_referrals_are_rejected_and_attribution_expires(): void
    {
        $referrer = $this->member();
        $service = app(ReferralService::class);
        $code = $service->codeFor($referrer);

        $this->assertNull($service->attribute($referrer, $code));

        $invitee = $this->member();
        $referral = $service->attribute($invitee, $code);
        $this->assertNotNull($referral);

        $this->travel(31)->days();
        $result = $service->qualifyInvitee($invitee, 'membership_active');

        $this->assertSame(Referral::STATUS_EXPIRED, $result->status);
        $this->assertDatabaseMissing('referral_reward_entries', ['referral_id' => $referral->id]);
    }

    public function test_monthly_cap_and_duplicate_payment_before_attribution_are_blocked(): void
    {
        config(['roicard.referrals.monthly_reward_cap' => 1]);

        $referrer = $this->member();
        $service = app(ReferralService::class);
        $code = $service->codeFor($referrer);

        $first = $this->member();
        $service->attribute($first, $code);
        $service->qualifyInvitee($first, 'membership_active');
        $this->assertSame(1, $referrer->referralRewardEntries()->count());

        $second = $this->member();
        $secondReferral = $service->attribute($second, $code);
        $capped = $service->qualifyInvitee($second, 'membership_active');
        $this->assertSame(Referral::STATUS_CAPPED, $capped->status);

        config(['roicard.referrals.monthly_reward_cap' => null]);
        $third = $this->member();
        $prior = Payment::create([
            'user_id' => $third->id,
            'amount' => 350,
            'currency' => 'GHS',
            'status' => Payment::STATUS_SUCCESS,
            'purpose' => Payment::PURPOSE_MEMBERSHIP,
            'provider_reference' => 'PRIOR-' . $third->id,
            'provider' => 'mock',
        ]);
        $prior->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->saveQuietly();
        $service->attribute($third, $code);
        $blocked = $service->qualifyInvitee($third, 'membership_active');
        $this->assertSame(Referral::STATUS_VOIDED, $blocked->status);
        $this->assertContains('payment_before_attribution', $blocked->risk_flags ?? []);
        $this->assertDatabaseMissing('referral_reward_entries', [
            'referral_id' => $secondReferral->id,
        ]);
    }

    public function test_payout_ledger_moves_available_balance_to_paid_without_double_counting(): void
    {
        config(['roicard.referrals.payout_enabled' => true]);

        $referrer = $this->member();
        $invitee = $this->member();
        $admin = $this->member(['role' => 'admin']);
        $service = app(ReferralService::class);

        $referral = $service->attribute($invitee, $service->codeFor($referrer));
        $qualified = $service->qualifyInvitee($invitee, 'membership_active');
        $service->markAvailable($qualified, $admin, 'Approved');
        $paid = $service->markPaid($qualified->fresh(), $admin, 'Manual mobile-money payout');

        $summary = $service->summary($referrer);
        $this->assertSame(ReferralRewardEntry::STATUS_PAID, $paid->status);
        $this->assertSame(0.0, $summary['balance']['available']);
        $this->assertSame(0.0, $summary['balance']['pending']);
        $this->assertSame(50.0, $summary['balance']['paid']);
        $this->assertSame(0.0, $summary['balance']['ledger_balance']);

        $adminSummary = $service->ledgerSummary();
        $this->assertSame(0, $adminSummary['available']);
        $this->assertSame(1, $adminSummary['paid']);
        $this->assertSame(0.0, $adminSummary['available_value']);
        $this->assertSame(50.0, $adminSummary['paid_value']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->markPaid($qualified->fresh(), $admin, 'Duplicate payout');
    }

    public function test_reward_release_waits_for_the_configured_refund_window(): void
    {
        config(['roicard.referrals.refund_window_days' => 30]);

        $referrer = $this->member();
        $invitee = $this->member();
        $admin = $this->member(['role' => 'admin']);
        $service = app(ReferralService::class);

        $referral = $service->attribute($invitee, $service->codeFor($referrer));
        $qualified = $service->qualifyInvitee($invitee, 'membership_active');

        try {
            $service->markAvailable($qualified, $admin, 'Early approval');
            $this->fail('Expected the refund window to block early release.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('referral', $exception->errors());
        }

        $this->travel(31)->days();
        $entry = $service->markAvailable($qualified->fresh(), $admin, 'Window elapsed');

        $this->assertSame(ReferralRewardEntry::STATUS_AVAILABLE, $entry->status);
        $this->assertSame(50.0, (float) $entry->amount);
    }

    public function test_total_budget_caps_new_rewards_and_admin_summary_alerts(): void
    {
        config([
            'roicard.referrals.total_budget' => 50,
            'roicard.referrals.budget_alert_threshold' => 40,
        ]);

        $referrer = $this->member();
        $admin = $this->member(['role' => 'admin']);
        $service = app(ReferralService::class);
        $code = $service->codeFor($referrer);

        $first = $this->member();
        $service->attribute($first, $code);
        $firstReferral = $service->qualifyInvitee($first, 'membership_active');
        $this->assertSame(Referral::STATUS_QUALIFIED, $firstReferral->status);

        $second = $this->member();
        $service->attribute($second, $code);
        $secondReferral = $service->qualifyInvitee($second, 'membership_active');
        $this->assertSame(Referral::STATUS_CAPPED, $secondReferral->status);
        $this->assertDatabaseMissing('referral_reward_entries', [
            'referral_id' => $secondReferral->id,
        ]);

        $this->using($admin->createToken('test')->plainTextToken)
            ->getJson('/api/admin/referrals')
            ->assertOk()
            ->assertJsonPath('summary.committed_value', 50)
            ->assertJsonPath('summary.budget_alert', true)
            ->assertJsonPath('summary.available', 0)
            ->assertJsonPath('summary.pending', 1);
    }

    public function test_same_registration_ip_referral_is_flagged(): void
    {
        config(['roicard.referrals.same_ip_block' => true]);

        $referrer = $this->member(['registration_ip' => '203.0.113.5']);
        $invitee = $this->member(['registration_ip' => '203.0.113.5']);
        $service = app(ReferralService::class);

        $referral = $service->attribute($invitee, $service->codeFor($referrer), '203.0.113.5');

        $this->assertSame(Referral::STATUS_VOIDED, $referral->status);
        $this->assertContains('same_ip_as_referrer', $referral->risk_flags ?? []);
        $this->assertDatabaseMissing('referral_reward_entries', ['referral_id' => $referral->id]);
    }

    public function test_same_phone_and_payment_method_are_flagged_at_qualification(): void
    {
        $referrer = $this->member();
        $invitee = $this->member();
        $referrer->profile()->create(['phone' => '+233 55 123 4567', 'whatsapp_phone' => '+233 55 123 4567']);
        $invitee->profile()->create(['phone' => '+233 55 123 4567', 'whatsapp_phone' => '+233 55 123 4567']);

        $service = app(ReferralService::class);
        $referral = $service->attribute($invitee, $service->codeFor($referrer));

        foreach ([$referrer, $invitee] as $index => $user) {
            Payment::create([
                'user_id' => $user->id,
                'amount' => 75,
                'currency' => 'GHS',
                'status' => Payment::STATUS_SUCCESS,
                'purpose' => Payment::PURPOSE_CARD_ACTIVATION,
                'provider_reference' => 'SAME-MOMO-' . $index . '-' . $user->id,
                'provider' => 'mock',
                'momo_number' => '0551234567',
            ]);
        }

        $blocked = $service->qualifyInvitee($invitee, 'membership_active');

        $this->assertSame(Referral::STATUS_VOIDED, $blocked->status);
        $this->assertContains('same_phone', $blocked->risk_flags ?? []);
        $this->assertContains('same_payment_method', $blocked->risk_flags ?? []);
        $this->assertDatabaseMissing('referral_reward_entries', ['referral_id' => $referral->id]);
    }

    public function test_configured_subscription_trigger_qualifies_from_a_verified_payment(): void
    {
        config(['roicard.referrals.qualification_trigger' => 'subscription_active']);

        $referrer = $this->member();
        $invitee = $this->member();
        $service = app(ReferralService::class);
        $referral = $service->attribute($invitee, $service->codeFor($referrer));

        $plan = Plan::create([
            'key' => 'referral-subscription',
            'name' => 'Referral Subscription',
            'is_active' => true,
            'is_default' => false,
            'price' => 100,
            'currency' => 'GHS',
            'billing_interval' => 'month',
            'verification_mark' => false,
        ]);

        $payment = Payment::create([
            'user_id' => $invitee->id,
            'amount' => 100,
            'currency' => 'GHS',
            'status' => Payment::STATUS_PENDING,
            'purpose' => Payment::PURPOSE_SUBSCRIPTION,
            'plan_id' => $plan->id,
            'provider_reference' => 'REFERRAL-SUB-' . $invitee->id,
            'provider' => 'mock',
        ]);

        $payment->update(['status' => Payment::STATUS_SUCCESS]);

        $this->assertSame(Referral::STATUS_QUALIFIED, $referral->fresh()->status);
        $this->assertDatabaseHas('referral_reward_entries', [
            'referral_id' => $referral->id,
            'status' => ReferralRewardEntry::STATUS_PENDING,
        ]);
        $this->assertSame($plan->id, $invitee->fresh()->plan_id);
        $this->assertSame(
            Subscription::STATUS_ACTIVE,
            Subscription::where('user_id', $invitee->id)->value('status'),
        );
    }

    public function test_stale_attributions_expire_via_the_scheduled_command(): void
    {
        $referrer = $this->member();
        $invitee = $this->member();
        $service = app(ReferralService::class);
        $referral = $service->attribute($invitee, $service->codeFor($referrer));

        $this->travel(31)->days();
        $this->artisan('referrals:expire')->assertSuccessful();

        $this->assertSame(Referral::STATUS_EXPIRED, $referral->fresh()->status);
    }

    public function test_referral_screen_and_admin_void_are_authorized_and_audited(): void
    {
        $referrer = $this->member();
        $invitee = $this->member();
        $service = app(ReferralService::class);
        $referral = $service->attribute($invitee, $service->codeFor($referrer));
        $qualified = $service->qualifyInvitee($invitee, 'membership_active');
        $admin = $this->member(['role' => 'admin']);

        $this->using($referrer->createToken('test')->plainTextToken)
            ->getJson('/api/referrals')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('reward.amount', 50)
            ->assertJsonPath('referrals.0.status', 'qualified');

        $this->using($admin->createToken('test')->plainTextToken)
            ->postJson("/api/admin/referrals/{$referral->id}/void", [
                'reason' => 'Duplicate account pattern',
            ])
            ->assertOk()
            ->assertJsonPath('referral.status', 'voided');

        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'referral.reward_voided',
            'target_id' => (string) $referral->id,
        ]);
        $this->assertSame(Referral::STATUS_VOIDED, $qualified->fresh()->status);
    }
}
