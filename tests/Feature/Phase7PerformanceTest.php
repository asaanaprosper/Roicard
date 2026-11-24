<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Profile;
use App\Models\User;
use App\Services\MatchmakingService;
use App\Services\OrganizationService;
use App\Services\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase7PerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function profile(User $user, string $suffix, array $attributes = []): Profile
    {
        return $user->profiles()->create(array_merge([
            'slug' => 'perf-' . $suffix,
            'title' => 'Product Designer',
            'organisation' => 'Pilot Co',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
            'completion_pct' => 80,
        ], $attributes));
    }

    public function test_search_and_recommendations_use_bounded_queries_for_a_small_pilot_set(): void
    {
        $category = Category::create([
            'kind' => Category::KIND_SEEKING,
            'name' => 'Design partners',
            'slug' => 'design-partners',
            'is_active' => true,
        ]);
        $offering = Category::create([
            'kind' => Category::KIND_OFFERING,
            'name' => 'Design partners',
            'slug' => 'design-partners-offer',
            'is_active' => true,
        ]);

        $viewer = $this->member();
        $viewerProfile = $this->profile($viewer, 'viewer');
        $need = $viewerProfile->signals()->create([
            'type' => 'seeking',
            'description' => 'Seeking design partners.',
            'is_active' => true,
        ]);
        $need->categories()->sync([$category->id]);

        for ($i = 0; $i < 8; $i++) {
            $candidate = $this->member();
            $profile = $this->profile($candidate, 'candidate-' . $i, [
                'display_name' => 'Performance Candidate ' . $i,
                'bio' => 'Design partner profile for performance testing.',
            ]);
            $signal = $profile->signals()->create([
                'type' => 'offering',
                'description' => 'Offering design partnership.',
                'is_active' => true,
            ]);
            $signal->categories()->sync([$offering->id]);
        }

        $searchQueries = 0;
        DB::listen(function () use (&$searchQueries) {
            $searchQueries++;
        });
        app(SearchService::class)->search($viewer, ['q' => 'Performance', 'per_page' => 20]);
        DB::flushQueryLog();
        $this->assertLessThan(60, $searchQueries, 'Search performed too many queries.');

        \Illuminate\Support\Facades\Cache::flush();
        $recommendationQueries = 0;
        DB::listen(function () use (&$recommendationQueries) {
            $recommendationQueries++;
        });
        app(MatchmakingService::class)->recommendations($viewer, $viewerProfile);
        $this->assertLessThan(80, $recommendationQueries, 'Recommendations performed too many queries.');
    }

    public function test_organization_directory_query_count_is_bounded(): void
    {
        $owner = $this->member();
        $organization = Community::create([
            'owner_user_id' => $owner->id,
            'name' => 'Performance Organization',
            'slug' => 'performance-organization',
            'type' => Community::TYPE_ORGANIZATION,
            'join_method' => Community::JOIN_REQUEST,
            'status' => Community::STATUS_ACTIVE,
            'verification_status' => Community::VERIFICATION_VERIFIED,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $member = $this->member();
            $this->profile($member, 'org-member-' . $i);
            CommunityMember::create([
                'community_id' => $organization->id,
                'user_id' => $member->id,
                'role' => CommunityMember::ROLE_MEMBER,
                'status' => CommunityMember::STATUS_ACTIVE,
                'join_method' => CommunityMember::JOIN_ADMIN,
                'joined_at' => now(),
                'affiliation_confirmed_at' => now(),
                'member_accepted_at' => now(),
            ]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        app(OrganizationService::class)->publicDirectory($organization, null);

        $this->assertLessThan(60, $queries, 'Organization directory performed too many queries.');
    }
}
