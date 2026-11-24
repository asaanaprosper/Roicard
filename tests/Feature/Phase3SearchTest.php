<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Profile;
use App\Models\User;
use App\Services\BlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\TestCase;

class Phase3SearchTest extends TestCase
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
            'slug' => 'search-' . $user->id . '-' . uniqid(),
            'title' => 'Product Designer',
            'organisation' => 'Acme Studio',
            'location' => 'Accra, Ghana',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
            'completion_pct' => 80,
        ], $attributes));
    }

    public function test_search_requires_authentication(): void
    {
        $this->getJson('/api/search?q=design')->assertStatus(401);
    }

    public function test_search_filters_and_does_not_expose_contact_details(): void
    {
        $viewer = $this->member();
        $token = $viewer->createToken('test')->plainTextToken;

        $business = $this->profile($this->member(), [
            'profile_type' => 'business',
            'display_name' => 'Acme Design Studio',
            'title' => 'Brand design studio',
            'organisation' => 'Acme Studio',
            'location' => 'Kumasi, Ghana',
            'contact_email' => 'private@example.com',
            'phone' => '+233500000000',
        ]);

        $personal = $this->profile($this->member(), [
            'display_name' => 'Ama Designer',
            'title' => 'Product designer',
            'organisation' => 'Other Co',
            'location' => 'Accra, Ghana',
        ]);

        $businessResult = $this->using($token)
            ->getJson('/api/search?result_type=business&organization=Acme&location=Kumasi')
            ->assertOk();
        $ids = collect($businessResult->json('data'))->pluck('profile.id');
        $this->assertTrue($ids->contains($business->id));
        $this->assertFalse($ids->contains($personal->id));
        $this->assertArrayNotHasKey('email', $businessResult->json('data.0.profile'));
        $this->assertArrayNotHasKey('phone', $businessResult->json('data.0.profile'));

        $this->using($token)
            ->getJson('/api/search?verified=1')
            ->assertOk();
    }

    public function test_search_supports_category_community_and_relevance_ranking(): void
    {
        $viewer = $this->member();
        $token = $viewer->createToken('test')->plainTextToken;
        $community = Community::create([
            'owner_user_id' => $viewer->id,
            'name' => 'Design Network',
            'slug' => 'design-network',
            'type' => Community::TYPE_GROUP,
            'join_method' => Community::JOIN_OPEN,
            'status' => Community::STATUS_ACTIVE,
        ]);
        CommunityMember::create([
            'community_id' => $community->id,
            'user_id' => $viewer->id,
            'role' => CommunityMember::ROLE_OWNER,
            'status' => CommunityMember::STATUS_ACTIVE,
            'join_method' => CommunityMember::JOIN_ADMIN,
        ]);

        $category = Category::create([
            'kind' => Category::KIND_OFFERING,
            'name' => 'Brand design',
            'slug' => 'brand-design',
            'is_active' => true,
        ]);
        $exact = $this->profile($this->member(), [
            'display_name' => 'Ada Lovelace',
            'title' => 'Designer',
        ]);
        $exact->categories()->attach($category->id);
        $exact->user->communityMemberships()->create([
            'community_id' => $community->id,
            'role' => CommunityMember::ROLE_MEMBER,
            'status' => CommunityMember::STATUS_ACTIVE,
            'join_method' => CommunityMember::JOIN_OPEN,
        ]);

        $bioOnly = $this->profile($this->member(), [
            'display_name' => 'Someone Else',
            'bio' => 'I once worked with Ada on a project.',
        ]);

        $response = $this->using($token)->getJson('/api/search?q=Ada')->assertOk();
        $first = $response->json('data.0.profile.id');
        $this->assertSame($exact->id, $first);

        $this->using($token)
            ->getJson('/api/search?category=' . $category->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $exact->id]);

        $this->using($token)
            ->getJson('/api/search?community=' . $community->slug)
            ->assertOk()
            ->assertJsonFragment(['id' => $exact->id]);
    }

    public function test_searchability_removal_and_blocking_exclude_profiles(): void
    {
        $viewer = $this->member();
        $candidate = $this->member();
        $profile = $this->profile($candidate, ['display_name' => 'Searchable Person']);
        $token = $viewer->createToken('test')->plainTextToken;

        $this->using($token)->getJson('/api/search?q=Searchable')->assertOk()
            ->assertJsonFragment(['id' => $profile->id]);

        $profile->update(['is_searchable' => false]);
        $this->using($token)->getJson('/api/search?q=Searchable')->assertOk()
            ->assertJsonMissing(['id' => $profile->id]);

        $profile->update(['is_searchable' => true]);
        app(BlockService::class)->block($viewer, $candidate->id);
        $this->using($token)->getJson('/api/search?q=Searchable')->assertOk()
            ->assertJsonMissing(['id' => $profile->id]);
    }

    public function test_paused_signal_text_is_excluded_from_search(): void
    {
        $viewer = $this->member();
        $token = $viewer->createToken('test')->plainTextToken;
        $profile = $this->profile($this->member(), ['display_name' => 'Signal Owner']);

        $signal = $profile->signals()->create([
            'type' => 'offering',
            'description' => 'Offering quantum logistics consulting.',
            'is_active' => true,
        ]);

        $this->using($token)
            ->getJson('/api/search?q=' . urlencode('quantum logistics'))
            ->assertOk()
            ->assertJsonFragment(['id' => $profile->id]);

        $signal->forceFill(['is_active' => false, 'paused_at' => now()])->save();

        $this->using($token)
            ->getJson('/api/search?q=' . urlencode('quantum logistics'))
            ->assertOk()
            ->assertJsonMissing(['id' => $profile->id]);
    }

    public function test_unusual_search_volume_emits_a_configurable_admin_alert(): void
    {
        config(['roicard.search.abuse_alert_threshold' => 2]);

        Log::spy();

        $viewer = $this->member();
        $target = $this->member();
        $this->profile($target);
        $token = $viewer->createToken('test')->plainTextToken;

        $this->using($token)->getJson('/api/search?q=design')->assertOk();
        $this->using($token)->getJson('/api/search?q=design')->assertOk();
        $this->using($token)->getJson('/api/search?q=design')->assertOk();

        Log::shouldHaveReceived('warning')
            ->with('search.abuse_alert', Mockery::on(
                fn (array $context) => $context['member_id'] === $viewer->id
                    && $context['searches_last_hour'] >= 2
                    && $context['threshold'] === 2
            ))
            ->once();

        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'search.abuse_alert',
            'target_user_id' => $viewer->id,
        ]);
    }

    public function test_search_pagination_and_rate_limit(): void
    {
        $viewer = $this->member();
        $token = $viewer->createToken('test')->plainTextToken;

        for ($i = 0; $i < 12; $i++) {
            $this->profile($this->member(), [
                'display_name' => 'Paged Person ' . $i,
                'title' => 'Paged Role',
            ]);
        }

        $page = $this->using($token)->getJson('/api/search?q=Paged&per_page=5')->assertOk();
        $this->assertSame(5, count($page->json('data')));
        $this->assertSame(12, $page->json('total'));

        RateLimiter::clear('search:' . $viewer->id);
        // The rate limiter keys by auth id; make enough calls to trip the
        // configured 60/minute ceiling.
        $lastStatus = 200;
        for ($i = 0; $i < 61; $i++) {
            $lastStatus = $this->using($token)->getJson('/api/search?q=Nobody')->status();
        }
        $this->assertSame(429, $lastStatus);
    }
}
