<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Connection;
use App\Models\Profile;
use App\Models\Tag;
use App\Models\User;
use App\Services\MatchmakingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase3MatchmakingTest extends TestCase
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
            'slug' => 'match-' . $user->id . '-' . uniqid(),
            'title' => 'Product Designer',
            'organisation' => 'Roicard',
            'location' => 'Accra, Ghana',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
            'completion_pct' => 80,
        ], $attributes));
    }

    private function category(string $kind, string $name): Category
    {
        return Category::create([
            'kind' => $kind,
            'name' => $name,
            'slug' => strtolower($name) . '-' . uniqid(),
            'is_active' => true,
        ]);
    }

    private function signal(Profile $profile, string $type, string $description, array $categoryIds = [], array $tagIds = []): void
    {
        $signal = $profile->signals()->create([
            'type' => $type,
            'description' => $description,
            'is_active' => true,
        ]);
        $signal->categories()->sync($categoryIds);
        $signal->tags()->sync($tagIds);
    }

    public function test_two_sided_match_scores_reasons_and_explanation(): void
    {
        $viewer = $this->member();
        $candidateUser = $this->member();
        $viewerProfile = $this->profile($viewer);
        $candidateProfile = $this->profile($candidateUser);

        $investors = $this->category(Category::KIND_SEEKING, 'Investment');
        $funding = $this->category(Category::KIND_OFFERING, 'Investment');
        $design = $this->category(Category::KIND_SEEKING, 'Product design');
        $designServices = $this->category(Category::KIND_OFFERING, 'Product design');

        $this->signal($viewerProfile, 'seeking', 'Seeking early-stage investors.', [$investors->id]);
        $this->signal($viewerProfile, 'offering', 'Offering product design services.', [$designServices->id]);
        $this->signal($candidateProfile, 'offering', 'Offering early-stage technology investment.', [$funding->id]);
        $this->signal($candidateProfile, 'seeking', 'Seeking product design help.', [$design->id]);

        $payload = app(MatchmakingService::class)->recommendations($viewer, $viewerProfile);

        $item = collect($payload['items'])->firstWhere('profile.id', $candidateProfile->id);
        $this->assertNotNull($item);
        $this->assertContains('offering_meets_seeking', $item['reason_codes']);
        $this->assertContains('seeking_meets_offering', $item['reason_codes']);
        $this->assertContains('mutual_match', $item['reason_codes']);
        $this->assertNotEmpty($item['explanation']);
        $this->assertNotEmpty($item['matched_items']);
        $this->assertDatabaseHas('recommendation_events', [
            'profile_id' => $viewerProfile->id,
            'recommended_profile_id' => $candidateProfile->id,
            'event_type' => 'impression',
        ]);
    }

    public function test_candidate_must_be_searchable_visible_unblocked_and_not_connected(): void
    {
        $viewer = $this->member();
        $candidateUser = $this->member();
        $viewerProfile = $this->profile($viewer);
        $candidateProfile = $this->profile($candidateUser);
        $seekingCategory = $this->category(Category::KIND_SEEKING, 'Partners');
        $offeringCategory = $this->category(Category::KIND_OFFERING, 'Partners');

        $this->signal($viewerProfile, 'seeking', 'Seeking partners.', [$seekingCategory->id]);
        $this->signal($candidateProfile, 'offering', 'Offering partnership.', [$offeringCategory->id]);

        $service = app(MatchmakingService::class);
        $this->assertNotEmpty($service->recommendations($viewer, $viewerProfile)['items']);

        $candidateProfile->update(['is_searchable' => false]);
        $this->assertEmpty($service->recommendations($viewer, $viewerProfile)['items']);

        $candidateProfile->update(['is_searchable' => true]);
        app(\App\Services\BlockService::class)->block($viewer, $candidateUser->id);
        $this->assertEmpty($service->recommendations($viewer, $viewerProfile)['items']);
        app(\App\Services\BlockService::class)->unblock($viewer, $candidateUser->id);

        Connection::create([
            'member_id' => $candidateUser->id,
            'guest_user_id' => $viewer->id,
            'guest_name' => $viewer->first_name . ' ' . $viewer->last_name,
            'guest_email' => $viewer->email,
            'status' => Connection::STATUS_APPROVED,
            'pair_key' => Connection::pairKey($viewer->id, $candidateUser->id),
        ]);
        $this->assertEmpty($service->recommendations($viewer, $viewerProfile)['items']);
    }

    public function test_dismissal_and_not_relevant_feedback_are_scoped_and_expire(): void
    {
        $viewer = $this->member();
        $candidateUser = $this->member();
        $viewerProfile = $this->profile($viewer);
        $candidateProfile = $this->profile($candidateUser);
        $seekingCategory = $this->category(Category::KIND_SEEKING, 'Mentors');
        $offeringCategory = $this->category(Category::KIND_OFFERING, 'Mentors');
        $this->signal($viewerProfile, 'seeking', 'Seeking mentors.', [$seekingCategory->id]);
        $this->signal($candidateProfile, 'offering', 'Offering mentorship.', [$offeringCategory->id]);

        $service = app(MatchmakingService::class);
        $items = $service->recommendations($viewer, $viewerProfile)['items'];
        $this->assertNotEmpty($items);
        $pairKeys = $items[0]['category_pair_keys'];

        $service->notRelevant($viewerProfile, $candidateProfile, $pairKeys);

        $this->assertDatabaseHas('recommendation_feedback', [
            'profile_id' => $viewerProfile->id,
            'recommended_profile_id' => $candidateProfile->id,
        ]);
        $this->assertEmpty($service->recommendations($viewer, $viewerProfile)['items']);

        $this->travel(91)->days();
        $service->invalidate();
        $this->assertNotEmpty($service->recommendations($viewer, $viewerProfile)['items']);
    }

    public function test_minimum_score_and_diversity_cap_are_configurable(): void
    {
        config([
            'roicard.matchmaking.minimum_score' => 10000,
            'roicard.matchmaking.diversity_cap_per_organization' => 1,
        ]);

        $viewer = $this->member();
        $viewerProfile = $this->profile($viewer);
        $seekingCategory = $this->category(Category::KIND_SEEKING, 'Developers');
        $offeringCategory = $this->category(Category::KIND_OFFERING, 'Developers');
        $this->signal($viewerProfile, 'seeking', 'Seeking developers.', [$seekingCategory->id]);

        foreach (['Acme', 'Acme', 'Beta'] as $index => $org) {
            $user = $this->member();
            $profile = $this->profile($user, ['organisation' => $org]);
            $this->signal($profile, 'offering', 'Offering development services ' . $index, [$offeringCategory->id]);
        }

        $this->assertEmpty(app(MatchmakingService::class)->recommendations($viewer, $viewerProfile)['items']);

        config(['roicard.matchmaking.minimum_score' => 1]);
        app(MatchmakingService::class)->invalidate();
        $items = app(MatchmakingService::class)->recommendations($viewer, $viewerProfile)['items'];
        $this->assertCount(2, $items);
        $orgs = collect($items)->pluck('profile.organisation')->all();
        $this->assertSame(1, collect($orgs)->filter(fn ($org) => $org === 'Acme')->count());
    }

    public function test_matching_is_per_profile(): void
    {
        $viewer = $this->member();
        $designCandidate = $this->profile($this->member(), ['organisation' => 'Design Co']);
        $financeCandidate = $this->profile($this->member(), ['organisation' => 'Finance Co']);
        $designSeeking = $this->category(Category::KIND_SEEKING, 'Design collaboration');
        $designOffering = $this->category(Category::KIND_OFFERING, 'Design collaboration');
        $financeSeeking = $this->category(Category::KIND_SEEKING, 'Finance advice');
        $financeOffering = $this->category(Category::KIND_OFFERING, 'Finance advice');

        $this->signal($designCandidate, 'offering', 'Offering design collaboration.', [$designOffering->id]);
        $this->signal($financeCandidate, 'offering', 'Offering finance advice.', [$financeOffering->id]);

        $personal = $this->profile($viewer, ['slug' => 'personal-match-profile']);
        $business = $this->profile($viewer, ['slug' => 'business-match-profile', 'profile_type' => 'business']);

        $this->signal($personal, 'seeking', 'Seeking design collaboration.', [$designSeeking->id]);
        $this->signal($business, 'seeking', 'Seeking finance advice.', [$financeSeeking->id]);

        $service = app(MatchmakingService::class);
        $personalIds = collect($service->recommendations($viewer, $personal)['items'])->pluck('profile.id');
        $businessIds = collect($service->recommendations($viewer, $business)['items'])->pluck('profile.id');

        $this->assertTrue($personalIds->contains($designCandidate->id));
        $this->assertFalse($personalIds->contains($financeCandidate->id));
        $this->assertTrue($businessIds->contains($financeCandidate->id));
        $this->assertFalse($businessIds->contains($designCandidate->id));
    }

    public function test_missing_signals_returns_honest_empty_state(): void
    {
        $viewer = $this->member();
        $profile = $this->profile($viewer);

        $payload = app(MatchmakingService::class)->recommendations($viewer, $profile);

        $this->assertSame([], $payload['items']);
        $this->assertTrue($payload['needs_signals']);
        $this->assertNotEmpty($payload['message']);
    }
}
