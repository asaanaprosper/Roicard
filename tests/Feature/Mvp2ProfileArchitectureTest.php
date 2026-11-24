<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Mvp2ProfileArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['status' => 'active'], $attributes));
    }

    public function test_multiple_profiles_belong_to_one_user_and_first_is_default(): void
    {
        $user = $this->member();
        $token = $user->createToken('test')->plainTextToken;

        $first = $this->withToken($token)->postJson('/api/profiles', [
            'profile_type' => 'personal',
            'display_name' => 'Ada Personal',
            'title' => 'Engineer',
        ]);

        $first->assertCreated()
            ->assertJsonPath('profile.user_id', $user->id)
            ->assertJsonPath('profile.profile_type', 'personal');

        $personal = Profile::findOrFail($first->json('profile.id'));

        $second = $this->withToken($token)->postJson('/api/profiles', [
            'profile_type' => 'business',
            'display_name' => 'Ada Studio',
            'organisation' => 'Ada Studio Ltd',
        ]);

        $second->assertCreated()
            ->assertJsonPath('profile.user_id', $user->id)
            ->assertJsonPath('profile.profile_type', 'business');

        $business = Profile::findOrFail($second->json('profile.id'));

        $this->assertSame($user->id, $personal->user_id);
        $this->assertSame($user->id, $business->user_id);
        $this->assertTrue($personal->fresh()->is_default);
        $this->assertFalse($business->fresh()->is_default);

        $list = $this->withToken($token)->getJson('/api/profiles')->assertOk();
        $this->assertCount(2, $list->json('profiles'));
        $this->assertSame($personal->id, $list->json('default_profile_id'));

        // MVP 1 singular endpoint still resolves the account's default profile.
        $this->withToken($token)
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('profile.id', $personal->id);
    }

    public function test_default_profile_can_be_changed_and_removed_default_is_promoted(): void
    {
        $user = $this->member();
        $token = $user->createToken('test')->plainTextToken;

        $first = $user->profiles()->create(['title' => 'First']);
        $second = $user->profiles()->create(['title' => 'Second']);

        $this->assertTrue($first->fresh()->is_default);
        $this->assertFalse($second->fresh()->is_default);

        $this->withToken($token)
            ->postJson("/api/profiles/{$second->id}/default")
            ->assertOk()
            ->assertJsonPath('profile.id', $second->id);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);

        $this->withToken($token)
            ->deleteJson("/api/profiles/{$second->id}")
            ->assertOk()
            ->assertJsonPath('default_profile_id', $first->id);

        $this->assertTrue($first->fresh()->is_default);
    }

    public function test_users_cannot_read_update_or_delete_another_users_profiles(): void
    {
        $owner = $this->member();
        $intruder = $this->member();
        $profile = $owner->profiles()->create(['title' => 'Owner profile']);
        $token = $intruder->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/profiles/{$profile->id}")
            ->assertStatus(403);

        $this->withToken($token)
            ->patchJson("/api/profiles/{$profile->id}", ['title' => 'Hijacked'])
            ->assertStatus(403);

        $this->withToken($token)
            ->deleteJson("/api/profiles/{$profile->id}")
            ->assertStatus(403);

        $this->assertSame('Owner profile', $profile->fresh()->title);
    }

    public function test_profile_creation_respects_profile_count_entitlement(): void
    {
        config(['roicard.plans.limits.PROFILE_COUNT' => 1]);

        $user = $this->member();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/profiles', [
            'display_name' => 'Only Profile',
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/profiles', [
            'display_name' => 'Too Many',
        ])->assertStatus(422)
            ->assertJsonPath('limit_key', 'PROFILE_COUNT');
    }
}
