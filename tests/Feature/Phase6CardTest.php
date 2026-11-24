<?php

namespace Tests\Feature;

use App\Models\AdminActionLog;
use App\Models\Profile;
use App\Models\SmartCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6CardTest extends TestCase
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

    private function profile(User $user): Profile
    {
        return $user->profiles()->create([
            'slug' => 'card-profile-' . $user->id . '-' . uniqid(),
            'title' => 'Member',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
        ]);
    }

    private function card(string $id): SmartCard
    {
        return SmartCard::create([
            'card_id' => $id,
            'inventory_status' => SmartCard::STATUS_AVAILABLE,
        ]);
    }

    public function test_card_activation_requires_verified_payment_and_binds_one_profile(): void
    {
        $user = $this->member();
        $profile = $this->profile($user);
        $card = $this->card('RC-PHASE6-001');
        $token = $user->createToken('test')->plainTextToken;

        $initiate = $this->using($token)
            ->postJson('/api/cards/activate', [
                'profile_id' => $profile->id,
                'card_id' => $card->card_id,
            ])
            ->assertCreated()
            ->assertJsonPath('payment.purpose', 'card_activation')
            ->assertJsonPath('payment.amount', '75.00');

        $this->assertNull($card->fresh()->binding_key);

        $reference = $initiate->json('payment.provider_reference');
        $this->using($token)
            ->postJson('/api/payments/mock/confirm', ['reference' => $reference])
            ->assertOk();

        $card->refresh();
        $this->assertSame($profile->id, $card->profile_id);
        $this->assertSame('profile:' . $profile->id, $card->binding_key);
        $this->assertSame(SmartCard::STATUS_ACTIVE, $card->inventory_status);

        $second = $this->card('RC-PHASE6-002');
        $this->using($token)
            ->postJson('/api/cards/activate', [
                'profile_id' => $profile->id,
                'card_id' => $second->card_id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_id']);
    }

    public function test_member_deactivation_unbinds_and_admin_replacement_rebinds(): void
    {
        $user = $this->member();
        $profile = $this->profile($user);
        $old = $this->card('RC-PHASE6-003');
        $new = $this->card('RC-PHASE6-004');
        $token = $user->createToken('test')->plainTextToken;

        $initiate = $this->using($token)
            ->postJson('/api/cards/activate', [
                'profile_id' => $profile->id,
                'card_id' => $old->card_id,
            ])
            ->assertCreated();
        $this->using($token)->postJson('/api/payments/mock/confirm', [
            'reference' => $initiate->json('payment.provider_reference'),
        ])->assertOk();

        $this->using($token)
            ->postJson("/api/cards/{$old->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('card.binding_key', null);

        $this->assertNull($old->fresh()->binding_key);

        $admin = $this->member(['role' => 'admin']);
        $this->using($admin->createToken('test')->plainTextToken)
            ->postJson("/api/admin/smart-cards/{$old->id}/replace", [
                'new_card_id' => $new->card_id,
            ])
            ->assertOk()
            ->assertJsonPath('new_card.binding_key', 'profile:' . $profile->id);

        $this->assertSame(SmartCard::STATUS_DEACTIVATED, $old->fresh()->inventory_status);
        $this->assertSame($profile->id, $new->fresh()->profile_id);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'smart_card.replaced',
            'target_id' => (string) $new->id,
        ]);
    }

    public function test_card_cannot_be_bound_by_another_member(): void
    {
        $owner = $this->member();
        $intruder = $this->member();
        $profile = $this->profile($owner);
        $card = $this->card('RC-PHASE6-005');

        $this->using($intruder->createToken('test')->plainTextToken)
            ->postJson('/api/cards/activate', [
                'profile_id' => $profile->id,
                'card_id' => $card->card_id,
            ])
            ->assertStatus(404);

        $intruderProfile = $this->profile($intruder);
        $this->using($intruder->createToken('test')->plainTextToken)
            ->postJson('/api/cards/activate', [
                'profile_id' => $intruderProfile->id,
                'card_id' => $card->card_id,
            ])
            ->assertCreated();

        $initiate = \App\Models\Payment::where('user_id', $intruder->id)
            ->where('purpose', 'card_activation')
            ->firstOrFail();
        $initiate->update(['status' => 'success']);

        $this->assertSame($intruderProfile->id, $card->fresh()->profile_id);
    }
}
