<?php

namespace Tests\Feature;

use App\Models\AdminActionLog;
use App\Models\AiUsageLog;
use App\Models\Category;
use App\Models\Connection;
use App\Models\MemberReport;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\ProfileSignal;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\MemberConnectionRequestNotification;
use App\Notifications\WeeklyRecommendationsNotification;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\PushDeliveryService;
use App\Services\Notifications\WhatsAppDeliveryService;
use App\Services\ReferralService;
use App\Support\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AppendixCNotificationsTest extends TestCase
{
    use RefreshDatabase;

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
            'slug' => 'notify-' . $user->id . '-' . uniqid(),
            'title' => 'Product Designer',
            'organisation' => 'Roicard',
            'location' => 'Accra, Ghana',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
            'is_contactable' => true,
            'completion_pct' => 80,
        ], $attributes));
    }

    private function connection(User $recipient, User $sender): Connection
    {
        return Connection::create([
            'member_id' => $recipient->id,
            'guest_user_id' => $sender->id,
            'guest_name' => 'Sender',
            'guest_email' => $sender->email,
            'status' => Connection::STATUS_PENDING,
            'request_type' => Connection::TYPE_MEMBER,
            'pair_key' => Connection::pairKey($sender->id, $recipient->id),
        ]);
    }

    private function hasNotification(User $user, string $type): bool
    {
        return $user->notifications()
            ->get()
            ->contains(fn ($notification) => ($notification->data['type'] ?? null) === $type);
    }

    public function test_notification_preferences_expose_defaults_and_can_be_toggled(): void
    {
        $user = $this->member();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/notifications/preferences')
            ->assertOk()
            ->assertJsonPath('types.0.type', NotificationType::CONNECTION_REQUEST)
            ->assertJsonPath('types.0.channels.database', true);

        $this->withToken($token)
            ->patchJson('/api/notifications/preferences', [
                'preferences' => [
                    NotificationType::CONNECTION_REQUEST => ['mail' => false],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preferences.' . NotificationType::CONNECTION_REQUEST . '.mail', false);

        $connection = $this->connection($user, $this->member());
        $channels = (new MemberConnectionRequestNotification($connection))->via($user->fresh());

        $this->assertContains('database', $channels);
        $this->assertNotContains('mail', $channels);
    }

    public function test_daily_cap_suppresses_non_mandatory_notifications_after_the_configured_limit(): void
    {
        config(['roicard.notifications.daily_cap' => 1]);

        $user = $this->member();
        $profile = $this->profile($user);
        $notification = new WeeklyRecommendationsNotification($profile, [
            ['profile' => ['id' => $profile->id, 'display_name' => 'Someone']],
        ]);

        $user->notify($notification);
        $user->notify($notification);

        $this->assertSame(1, $user->notifications()
            ->get()
            ->filter(fn ($item) => ($item->data['type'] ?? null) === NotificationType::WEEKLY_RECOMMENDATIONS)
            ->count());
    }

    public function test_signed_unsubscribe_link_disables_one_channel(): void
    {
        $user = $this->member();

        $url = URL::temporarySignedRoute('notifications.unsubscribe', now()->addDay(), [
            'user' => $user->id,
            'type' => NotificationType::WEEKLY_RECOMMENDATIONS,
            'channel' => 'mail',
        ]);

        $this->getJson($url)->assertOk();

        $this->assertFalse(
            app(NotificationPreferenceService::class)->preferences($user->fresh())[NotificationType::WEEKLY_RECOMMENDATIONS]['mail']
        );
    }

    public function test_push_tokens_can_be_registered_and_removed(): void
    {
        $user = $this->member();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/notifications/push-tokens', [
                'token' => 'device-token-1',
                'platform' => 'web',
            ])
            ->assertCreated()
            ->assertJsonPath('push_token.platform', 'web');

        $this->assertDatabaseHas('push_tokens', [
            'user_id' => $user->id,
            'token' => 'device-token-1',
        ]);

        $this->withToken($token)
            ->deleteJson('/api/notifications/push-tokens', ['token' => 'device-token-1'])
            ->assertOk()
            ->assertJsonPath('removed', true);

        // Another account cannot claim a token that already belongs to someone.
        $other = $this->member();
        $otherToken = $other->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/notifications/push-tokens', ['token' => 'device-token-1'])
            ->assertCreated();

        $this->app['auth']->forgetGuards();

        $this->withToken($otherToken)
            ->postJson('/api/notifications/push-tokens', ['token' => 'device-token-1'])
            ->assertStatus(422);
    }

    public function test_push_delivery_uses_only_the_configured_provider_adapter(): void
    {
        $user = $this->member();
        $user->pushTokens()->create(['token' => 'push-token', 'platform' => 'web']);

        config([
            'services.push.driver' => 'webhook',
            'services.push.endpoint' => 'https://push.example.test/send',
            'services.push.token' => 'push-secret',
        ]);

        Http::fake([
            'https://push.example.test/send' => Http::response(['ok' => true], 200),
        ]);

        $sent = app(PushDeliveryService::class)->send($user, [
            'type' => NotificationType::CONNECTION_REQUEST,
            'title' => 'Hello',
            'body' => 'There is a request.',
        ]);

        $this->assertTrue($sent);
        Http::assertSent(fn ($request) => $request->url() === 'https://push.example.test/send'
            && $request->hasHeader('Authorization', 'Bearer push-secret'));
    }

    public function test_whatsapp_onboarding_delivery_is_disabled_until_the_business_api_is_configured(): void
    {
        $user = $this->member();
        $this->profile($user, ['whatsapp_phone' => '+233 50 000 0000']);

        $this->assertFalse(app(WhatsAppDeliveryService::class)->send($user, ['day' => 3]));

        config([
            'services.whatsapp.endpoint' => 'https://whatsapp.example.test/send',
            'services.whatsapp.token' => 'wa-secret',
            'services.whatsapp.template' => 'onboarding_reminder',
        ]);

        Http::fake([
            'https://whatsapp.example.test/send' => Http::response(['ok' => true], 200),
        ]);

        $this->assertTrue(app(WhatsAppDeliveryService::class)->send($user, ['day' => 3]));
        Http::assertSent(fn ($request) => $request->url() === 'https://whatsapp.example.test/send'
            && $request['template'] === 'onboarding_reminder');
    }

    public function test_referral_notifications_cover_signup_qualification_credit_and_payout(): void
    {
        config([
            'roicard.referrals.enabled' => true,
            'roicard.referrals.qualification_trigger' => 'membership_active',
            'roicard.referrals.reward_amount' => 25,
            'roicard.referrals.currency' => 'GHS',
            'roicard.referrals.payout_enabled' => true,
            'roicard.referrals.refund_window_days' => null,
            'roicard.referrals.monthly_reward_cap' => null,
            'roicard.referrals.total_budget' => null,
        ]);

        $referrer = $this->member();
        $invitee = $this->member();
        $admin = $this->member(['role' => 'admin']);

        $service = app(ReferralService::class);
        $referral = $service->attribute($invitee, $service->codeFor($referrer), '127.0.0.1', 'signup');

        $this->assertNotNull($referral);
        $this->assertTrue($this->hasNotification($referrer->fresh(), NotificationType::REFERRAL_SIGNUP));

        $service->qualifyInvitee($invitee, 'membership_active');
        $this->assertTrue($this->hasNotification($referrer->fresh(), NotificationType::REFERRAL_QUALIFIED));

        $service->markAvailable($referral->fresh(), $admin, 'Approved.');
        $this->assertTrue($this->hasNotification($referrer->fresh(), NotificationType::REFERRAL_REWARD_CREDITED));

        $service->markPaid($referral->fresh(), $admin, 'Paid by bank transfer.');
        $this->assertTrue($this->hasNotification($referrer->fresh(), NotificationType::REFERRAL_REWARD_PAID));
    }

    public function test_subscription_lapse_warning_and_lapsed_notifications(): void
    {
        config(['roicard.notifications.subscription_lapse_warning_days' => 7]);

        $user = $this->member();
        $plan = Plan::create(['key' => 'notify-plan', 'name' => 'Notify Plan', 'price' => 10, 'currency' => 'GHS']);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->subDays(25),
            'current_period_end' => now()->addDays(2),
        ]);

        $this->artisan('notifications:subscription-lapse-reminders')->assertSuccessful();
        $this->assertTrue($this->hasNotification($user->fresh(), NotificationType::SUBSCRIPTION_ABOUT_TO_LAPSE));

        app(\App\Services\BillingService::class)->expire($subscription->fresh());
        $this->assertTrue($this->hasNotification($user->fresh(), NotificationType::SUBSCRIPTION_LAPSED));
    }

    public function test_unpublished_profile_reminder_uses_the_configured_delay(): void
    {
        config(['roicard.notifications.unpublished_profile_reminder_days' => 2]);

        $user = $this->member(['onboarding_completed_at' => now()]);
        $profile = $this->profile($user, [
            'is_published' => false,
            'is_live' => false,
        ]);

        DB::table('profiles')->where('id', $profile->id)->update([
            'updated_at' => now()->subDays(3),
        ]);

        $this->artisan('notifications:unpublished-profile-reminders')->assertSuccessful();

        $this->assertTrue($this->hasNotification($user->fresh(), NotificationType::UNPUBLISHED_PROFILE_REMINDER));
    }

    public function test_report_outcome_notifies_the_reporter_without_moderator_notes(): void
    {
        $admin = $this->member(['role' => 'admin']);
        $reporter = $this->member();
        $reported = $this->member();
        $profile = $this->profile($reported);

        $report = MemberReport::create([
            'reporter_id' => $reporter->id,
            'reported_user_id' => $reported->id,
            'profile_id' => $profile->id,
            'reason_category' => 'spam',
            'status' => MemberReport::STATUS_PENDING,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/admin/reports/{$report->id}", [
                'status' => 'dismissed',
                'action' => 'dismiss',
                'admin_notes' => 'Private moderator note.',
            ])
            ->assertOk();

        $notification = $reporter->notifications()
            ->get()
            ->first(fn ($item) => ($item->data['type'] ?? null) === NotificationType::REPORT_OUTCOME);

        $this->assertNotNull($notification);
        $this->assertArrayNotHasKey('admin_notes', $notification->data);
        $this->assertStringNotContainsString('Private moderator note', json_encode($notification->data));
    }

    public function test_search_scraping_alert_notifies_admins(): void
    {
        config(['roicard.search.abuse_alert_threshold' => 1]);

        $admin = $this->member(['role' => 'admin']);
        $viewer = $this->member();
        $this->profile($this->member());

        $token = $viewer->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/search?q=design')->assertOk();
        $this->withToken($token)->getJson('/api/search?q=design')->assertOk();

        $this->assertTrue($this->hasNotification($admin->fresh(), NotificationType::ADMIN_SCRAPING));
    }

    public function test_referral_cap_alert_notifies_admins(): void
    {
        config([
            'roicard.referrals.enabled' => true,
            'roicard.referrals.qualification_trigger' => 'membership_active',
            'roicard.referrals.reward_amount' => 25,
            'roicard.referrals.currency' => 'GHS',
            'roicard.referrals.monthly_reward_cap' => 0,
            'roicard.referrals.total_budget' => null,
            'roicard.plans.limits.REFERRAL_REWARDS' => null,
        ]);

        $admin = $this->member(['role' => 'admin']);
        $referrer = $this->member();
        $invitee = $this->member();
        $service = app(ReferralService::class);

        $service->attribute($invitee, $service->codeFor($referrer), '127.0.0.1', 'signup');
        $service->qualifyInvitee($invitee, 'membership_active');

        $this->assertTrue($this->hasNotification($admin->fresh(), NotificationType::ADMIN_REFERRAL_REWARD_CAP));
    }

    public function test_ai_budget_alert_notifies_admins_once_per_month(): void
    {
        config([
            'roicard.ai.monthly_cost_alert_usd' => 0,
            'roicard.ai.input_price_per_million_usd' => 1,
            'roicard.ai.output_price_per_million_usd' => 1,
        ]);

        $admin = $this->member(['role' => 'admin']);
        $usage = app(\App\Services\Ai\AiUsageService::class);

        AiUsageLog::create([
            'user_id' => $admin->id,
            'feature' => 'profile_builder',
            'provider' => 'gemini',
            'outcome' => 'success',
            'estimated_cost_usd' => 0.01,
        ]);

        $usage->maybeAlertMonthlyCost();
        $usage->maybeAlertMonthlyCost();

        $this->assertSame(1, $admin->notifications()
            ->get()
            ->filter(fn ($item) => ($item->data['type'] ?? null) === NotificationType::ADMIN_AI_BUDGET)
            ->count());
    }

    public function test_weekly_recommendation_command_sends_a_digest(): void
    {
        $viewer = $this->member();
        $candidate = $this->member();
        $viewerProfile = $this->profile($viewer);
        $candidateProfile = $this->profile($candidate, ['slug' => 'notify-candidate']);

        $seeking = Category::create([
            'kind' => Category::KIND_SEEKING,
            'name' => 'Digest Need',
            'slug' => 'digest-need',
            'is_active' => true,
        ]);
        $offering = Category::create([
            'kind' => Category::KIND_OFFERING,
            'name' => 'Digest Need',
            'slug' => 'digest-offer',
            'is_active' => true,
        ]);

        $need = ProfileSignal::create([
            'profile_id' => $viewerProfile->id,
            'type' => ProfileSignal::TYPE_SEEKING,
            'description' => 'Need a digest need.',
            'is_active' => true,
            'source' => 'member',
        ]);
        $need->categories()->sync([$seeking->id]);

        $offer = ProfileSignal::create([
            'profile_id' => $candidateProfile->id,
            'type' => ProfileSignal::TYPE_OFFERING,
            'description' => 'Offer a digest need.',
            'is_active' => true,
            'source' => 'member',
        ]);
        $offer->categories()->sync([$offering->id]);

        $this->artisan('notifications:weekly-recommendations')->assertSuccessful();

        $this->assertTrue($this->hasNotification($viewer->fresh(), NotificationType::WEEKLY_RECOMMENDATIONS));
    }

    public function test_search_typo_tolerance_still_respects_privacy_scopes(): void
    {
        $viewer = $this->member();
        $candidate = $this->member();
        $profile = $this->profile($candidate, [
            'display_name' => 'Designer Person',
            'title' => 'Product Designer',
        ]);

        $token = $viewer->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/search?q=Desginer')
            ->assertOk();

        $this->assertTrue(collect($response->json('data'))->pluck('profile.id')->contains($profile->id));
    }
}
