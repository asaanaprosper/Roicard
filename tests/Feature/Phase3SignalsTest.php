<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Profile;
use App\Models\ProfileSignal;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase3SignalsTest extends TestCase
{
    use RefreshDatabase;

    protected function using(string $token): static
    {
        // Sanctum caches the resolved guard user for the app instance; reset it
        // when a test switches between member tokens.
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
            'slug' => 'signals-' . $user->id . '-' . uniqid(),
            'title' => 'Product Designer',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
        ], $attributes));
    }

    public function test_seeking_offering_crud_keeps_free_text_when_categories_change(): void
    {
        $user = $this->member();
        $profile = $this->profile($user);
        $token = $user->createToken('test')->plainTextToken;
        $seeking = Category::create([
            'kind' => Category::KIND_SEEKING,
            'name' => 'Investors',
            'slug' => 'investors',
            'is_active' => true,
        ]);
        $tag = Tag::create(['name' => 'Fintech', 'slug' => 'fintech', 'is_active' => true]);

        $created = $this->using($token)->postJson("/api/profiles/{$profile->id}/signals", [
            'type' => 'seeking',
            'description' => 'Looking for early-stage fintech investors.',
            'category_ids' => [$seeking->id],
            'tag_ids' => [$tag->id],
        ])->assertCreated();

        $signalId = $created->json('signal.id');
        $created->assertJsonPath('signal.description', 'Looking for early-stage fintech investors.');

        $this->using($token)
            ->patchJson("/api/profiles/{$profile->id}/signals/{$signalId}", [
                'category_ids' => [],
                'tag_ids' => [],
            ])
            ->assertOk()
            ->assertJsonPath('signal.description', 'Looking for early-stage fintech investors.');

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/signals/{$signalId}/pause")
            ->assertOk()
            ->assertJsonPath('signal.is_active', false);

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/signals/{$signalId}/resume")
            ->assertOk()
            ->assertJsonPath('signal.is_active', true);

        $this->using($token)
            ->deleteJson("/api/profiles/{$profile->id}/signals/{$signalId}")
            ->assertOk();

        $this->assertDatabaseMissing('profile_signals', ['id' => $signalId]);
    }

    public function test_active_signal_limits_are_enforced_and_paused_items_do_not_count(): void
    {
        config(['roicard.plans.limits.ACTIVE_SEEKING_ITEMS' => 1]);

        $user = $this->member();
        $profile = $this->profile($user);
        $token = $user->createToken('test')->plainTextToken;

        $first = $this->using($token)->postJson("/api/profiles/{$profile->id}/signals", [
            'type' => 'seeking',
            'description' => 'First active seek.',
        ])->assertCreated();

        $this->using($token)->postJson("/api/profiles/{$profile->id}/signals", [
            'type' => 'seeking',
            'description' => 'Second active seek.',
        ])->assertStatus(422)
            ->assertJsonPath('limit_key', 'ACTIVE_SEEKING_ITEMS');

        $paused = $this->using($token)->postJson("/api/profiles/{$profile->id}/signals", [
            'type' => 'seeking',
            'description' => 'Paused seek.',
            'is_active' => false,
        ])->assertCreated();

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/signals/{$paused->json('signal.id')}/resume")
            ->assertStatus(422)
            ->assertJsonPath('limit_key', 'ACTIVE_SEEKING_ITEMS');
    }

    public function test_signals_are_independent_per_profile_and_ownership_is_enforced(): void
    {
        $owner = $this->member();
        $intruder = $this->member();
        $personal = $this->profile($owner, ['slug' => 'personal-signals']);
        $business = $this->profile($owner, ['slug' => 'business-signals', 'profile_type' => 'business']);
        $token = $owner->createToken('test')->plainTextToken;

        $this->using($token)->postJson("/api/profiles/{$personal->id}/signals", [
            'type' => 'offering',
            'description' => 'Personal offering.',
        ])->assertCreated();

        $this->using($token)->postJson("/api/profiles/{$business->id}/signals", [
            'type' => 'offering',
            'description' => 'Business offering.',
        ])->assertCreated();

        $this->assertSame(1, $personal->signals()->count());
        $this->assertSame(1, $business->signals()->count());

        $otherToken = $intruder->createToken('test')->plainTextToken;
        $this->using($otherToken)->postJson("/api/profiles/{$personal->id}/signals", [
            'type' => 'seeking',
            'description' => 'Unauthorized.',
        ])->assertStatus(403);
    }

    public function test_signal_categories_must_match_the_signal_type(): void
    {
        $user = $this->member();
        $profile = $this->profile($user);
        $token = $user->createToken('test')->plainTextToken;
        $offering = Category::create([
            'kind' => Category::KIND_OFFERING,
            'name' => 'Consulting',
            'slug' => 'consulting',
            'is_active' => true,
        ]);

        $this->using($token)->postJson("/api/profiles/{$profile->id}/signals", [
            'type' => 'seeking',
            'description' => 'A mismatch.',
            'category_ids' => [$offering->id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['category_ids']);
    }
}
