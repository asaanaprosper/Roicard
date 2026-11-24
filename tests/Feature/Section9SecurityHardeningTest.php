<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class Section9SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'draft',
            'email_verified_at' => now(),
        ], $attributes));
    }

    public function test_idempotency_keys_are_scoped_to_the_authenticated_member(): void
    {
        $first = $this->member();
        $second = $this->member();

        $firstResponse = $this->withToken($first->createToken('test')->plainTextToken)
            ->postJson('/api/payments/initiate', [], ['Idempotency-Key' => 'same-client-key'])
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $secondResponse = $this->withToken($second->createToken('test')->plainTextToken)
            ->postJson('/api/payments/initiate', [], ['Idempotency-Key' => 'same-client-key'])
            ->assertOk();

        $this->assertNotSame(
            $firstResponse->json('payment.id'),
            $secondResponse->json('payment.id'),
        );
        $this->assertSame($first->id, $firstResponse->json('payment.user_id'));
        $this->assertSame($second->id, $secondResponse->json('payment.user_id'));
        $this->assertSame(2, Payment::count());
    }

    public function test_idempotency_replay_for_the_same_member_returns_the_same_result(): void
    {
        $user = $this->member();
        $token = $user->createToken('test')->plainTextToken;

        $first = $this->withToken($token)
            ->postJson('/api/payments/initiate', [], ['Idempotency-Key' => 'replay-key'])
            ->assertOk();

        $second = $this->withToken($token)
            ->postJson('/api/payments/initiate', [], ['Idempotency-Key' => 'replay-key'])
            ->assertOk()
            ->assertHeader('X-Idempotent-Replayed', 'true');

        $this->assertSame($first->json('payment.id'), $second->json('payment.id'));
        $this->assertSame(1, Payment::count());
    }

    public function test_idempotency_key_validation_rejects_unsafe_values(): void
    {
        $user = $this->member();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/payments/initiate', [], ['Idempotency-Key' => 'bad key with spaces'])
            ->assertStatus(400);
    }

    public function test_security_headers_are_present_on_api_responses(): void
    {
        $this->getJson('/api/categories')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_password_reset_revokes_existing_sessions_and_requires_strong_password(): void
    {
        $user = $this->member([
            'email' => 'reset-security@example.com',
            'password' => bcrypt('OldPass123!'),
        ]);
        $token = $user->createToken('test')->plainTextToken;
        $resetToken = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => 'weakpass',
            'password_confirmation' => 'weakpass',
        ])->assertStatus(422);

        $this->postJson('/api/auth/reset-password', [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => 'NewStrong123!',
            'password_confirmation' => 'NewStrong123!',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewStrong123!', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertStatus(401);
    }

    public function test_forgot_password_does_not_reveal_unknown_email(): void
    {
        $known = $this->member(['email' => 'known-reset@example.com']);

        $knownResponse = $this->postJson('/api/auth/forgot-password', [
            'email' => $known->email,
        ])->assertOk();

        $unknownResponse = $this->postJson('/api/auth/forgot-password', [
            'email' => 'missing-reset@example.com',
        ])->assertOk();

        $this->assertSame(
            $knownResponse->json('message'),
            $unknownResponse->json('message'),
        );
    }

    public function test_email_verification_code_does_not_reveal_missing_accounts(): void
    {
        $this->postJson('/api/auth/verify-email/code', [
            'email' => 'missing-verify@example.com',
            'code' => '123456',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Invalid or expired verification code');
    }

    public function test_email_change_requires_the_current_password(): void
    {
        $user = $this->member(['email' => 'email-change@example.com']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/auth/account', ['email' => 'changed@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->withToken($token)
            ->putJson('/api/auth/account', [
                'email' => 'changed@example.com',
                'current_password' => 'wrong-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->withToken($token)
            ->putJson('/api/auth/account', [
                'email' => 'changed@example.com',
                'current_password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('email_changed', true);

        $this->assertSame('changed@example.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_two_factor_pending_token_cannot_use_general_authenticated_routes(): void
    {
        $user = $this->member([
            'password' => bcrypt('Pass123'),
            'two_factor_enabled' => true,
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->generateSecret(),
        ]);

        $pendingToken = $user
            ->createToken('two-factor-pending', ['two-factor'], now()->addMinutes(10))
            ->plainTextToken;

        $this->withToken($pendingToken)
            ->getJson('/api/me')
            ->assertStatus(403)
            ->assertJsonPath('error', 'two_factor_required');

        $this->withToken($pendingToken)
            ->getJson('/api/auth/account/export')
            ->assertStatus(403)
            ->assertJsonPath('error', 'two_factor_required');
    }

    public function test_two_factor_pending_token_cannot_read_restricted_profiles(): void
    {
        $owner = $this->member(['status' => 'active']);
        $viewer = $this->member(['status' => 'active', 'two_factor_enabled' => true]);

        $profile = $owner->profiles()->create([
            'slug' => 'pending-2fa-profile',
            'title' => 'Restricted Profile',
            'visibility' => 'members',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
        ]);

        $pendingToken = $viewer
            ->createToken('two-factor-pending', ['two-factor'], now()->addMinutes(10))
            ->plainTextToken;

        $this->withToken($pendingToken)
            ->getJson('/api/public/' . $profile->slug)
            ->assertStatus(403);

        $this->app['auth']->forgetGuards();

        $fullToken = $viewer->createToken('auth-token', ['*'], now()->addDays(1))->plainTextToken;

        $this->withToken($fullToken)
            ->getJson('/api/public/' . $profile->slug)
            ->assertOk();
    }

    public function test_paystack_webhook_rejects_amount_mismatch_before_activation(): void
    {
        config([
            'app.payment_provider' => 'paystack',
            'services.paystack.secret_key' => 'test-secret',
        ]);

        $user = $this->member();
        $payment = Payment::create([
            'user_id' => $user->id,
            'amount' => 100,
            'currency' => 'GHS',
            'status' => Payment::STATUS_PENDING,
            'purpose' => Payment::PURPOSE_SUBSCRIPTION,
            'provider_reference' => 'AMOUNT-CHECK-1',
            'provider' => 'paystack',
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'id' => 'evt_amount_mismatch',
                'reference' => $payment->provider_reference,
                'amount' => 1,
                'currency' => 'GHS',
            ],
        ];
        $raw = json_encode($payload);
        $signature = hash_hmac('sha512', $raw, 'test-secret');

        $this->postJson('/api/payments/webhook/paystack', $payload, [
            'X-Paystack-Signature' => $signature,
        ])->assertStatus(403);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }
}
