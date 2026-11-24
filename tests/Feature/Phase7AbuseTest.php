<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Phase7AbuseTest extends TestCase
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

    private function businessProfile(User $user): Profile
    {
        return $user->profiles()->create([
            'slug' => 'abuse-' . $user->id . '-' . uniqid(),
            'profile_type' => 'business',
            'display_name' => 'Abuse Test Business',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
        ]);
    }

    public function test_catalog_enquiry_endpoint_is_rate_limited(): void
    {
        Notification::fake();

        $owner = $this->member();
        $profile = $this->businessProfile($owner);
        $item = CatalogItem::create([
            'profile_id' => $profile->id,
            'type' => 'service',
            'title' => 'Rate limited item',
            'price_mode' => 'on_request',
            'currency' => 'GHS',
            'cta_type' => 'enquire',
            'is_active' => true,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/catalog/{$item->id}/enquiries", [
                'name' => 'Guest ' . $i,
                'email' => "guest{$i}@example.com",
                'message' => 'Interested',
            ])->assertCreated();
        }

        $this->postJson("/api/catalog/{$item->id}/enquiries", [
            'name' => 'Guest 6',
            'email' => 'guest6@example.com',
            'message' => 'Interested',
        ])->assertStatus(429);
    }

    public function test_signature_generation_and_enquiry_reads_are_owner_scoped(): void
    {
        $owner = $this->member();
        $intruder = $this->member();
        $profile = $this->businessProfile($owner);
        $ownerToken = $owner->createToken('test')->plainTextToken;
        $intruderToken = $intruder->createToken('test')->plainTextToken;

        $this->using($intruderToken)
            ->postJson("/api/profiles/{$profile->id}/signature-cards/square")
            ->assertStatus(403);

        $this->using($intruderToken)
            ->getJson("/api/profiles/{$profile->id}/catalog-enquiries")
            ->assertStatus(403);

        $this->using($ownerToken)
            ->getJson("/api/profiles/{$profile->id}/catalog-enquiries")
            ->assertOk();
    }

    public function test_billing_and_card_mutations_require_authentication(): void
    {
        $this->postJson('/api/billing/subscribe', ['plan_id' => 1])->assertStatus(401);
        $this->postJson('/api/cards/activate', [
            'profile_id' => 1,
            'card_id' => 'RC-FAKE',
        ])->assertStatus(401);
        $this->patchJson('/api/referrals/settings', [
            'signature_referral_enabled' => true,
        ])->assertStatus(401);
    }
}
