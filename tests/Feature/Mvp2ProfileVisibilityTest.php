<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Connection;
use App\Models\MeetingType;
use App\Models\Profile;
use App\Models\User;
use App\Services\BlockService;
use App\Services\CommunityMembershipService;
use App\Services\ProfileVisibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Mvp2ProfileVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['status' => 'active'], $attributes));
    }

    private function publishedProfile(User $user, array $attributes = []): Profile
    {
        return $user->profiles()->create(array_merge([
            'slug' => 'card-' . $user->id,
            'is_live' => true,
            'visibility' => 'public',
            'contact_details_visibility' => 'connections',
            'phone' => '+233500000001',
            'whatsapp_phone' => '+233500000002',
        ], $attributes));
    }

    public function test_public_profile_redacts_contact_details_for_guests(): void
    {
        $owner = $this->member();
        $this->publishedProfile($owner);

        $this->getJson('/api/public/card-' . $owner->id . '?_metadata=1')
            ->assertOk()
            ->assertJsonPath('phone', null)
            ->assertJsonPath('whatsapp_phone', null)
            ->assertJsonPath('user.email', null)
            ->assertJsonPath('contact_details_visible', false)
            ->assertJsonPath('can_contact', true);
    }

    public function test_connection_viewers_see_contact_details_but_non_connections_do_not(): void
    {
        $owner = $this->member();
        $viewer = $this->member();
        $this->publishedProfile($owner, ['visibility' => 'connections']);
        $token = $viewer->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/public/card-' . $owner->id . '?_metadata=1')
            ->assertStatus(403);

        Connection::create([
            'member_id' => $owner->id,
            'guest_user_id' => $viewer->id,
            'guest_name' => 'Viewer',
            'guest_email' => $viewer->email,
            'status' => 'approved',
        ]);

        $this->withToken($token)
            ->getJson('/api/public/card-' . $owner->id . '?_metadata=1')
            ->assertOk()
            ->assertJsonPath('phone', '+233500000001')
            ->assertJsonPath('whatsapp_phone', '+233500000002')
            ->assertJsonPath('contact_details_visible', true);
    }

    public function test_members_and_connection_visibility_rules(): void
    {
        $owner = $this->member();
        $viewer = $this->member();
        $profile = $this->publishedProfile($owner, ['visibility' => 'members']);
        $token = $viewer->createToken('test')->plainTextToken;

        $this->getJson('/api/public/card-' . $owner->id . '?_metadata=1')
            ->assertStatus(403);

        $this->withToken($token)
            ->getJson('/api/public/card-' . $owner->id . '?_metadata=1')
            ->assertOk();

        $profile->update(['visibility' => 'private']);

        $this->withToken($token)
            ->getJson('/api/public/card-' . $owner->id . '?_metadata=1')
            ->assertStatus(403);

        $ownerToken = $owner->createToken('owner')->plainTextToken;
        $this->withToken($ownerToken)
            ->getJson('/api/public/card-' . $owner->id . '?_metadata=1')
            ->assertOk();
    }

    public function test_shared_community_members_can_view_community_scoped_profiles(): void
    {
        $owner = $this->member();
        $viewer = $this->member();
        $profile = $this->publishedProfile($owner, [
            'visibility' => 'communities',
            'contact_details_visibility' => 'communities',
        ]);

        $community = Community::create([
            'owner_user_id' => $owner->id,
            'name' => 'Shared Circle',
            'slug' => 'shared-circle',
            'type' => Community::TYPE_GROUP,
            'join_method' => Community::JOIN_OPEN,
            'status' => Community::STATUS_ACTIVE,
        ]);

        $service = app(CommunityMembershipService::class);
        $service->addMember($community, $owner, CommunityMember::ROLE_OWNER);
        $service->addMember($community, $viewer);

        $token = $viewer->createToken('test')->plainTextToken;

        $this->getJson('/api/public/card-' . $owner->id . '?_metadata=1')
            ->assertStatus(403);

        $this->withToken($token)
            ->getJson('/api/public/card-' . $owner->id . '?_metadata=1')
            ->assertOk()
            ->assertJsonPath('contact_details_visible', true)
            ->assertJsonPath('phone', '+233500000001');

        // Contact visibility "connections" must not leak to a community peer.
        $profile->update(['contact_details_visibility' => 'connections']);
        $this->withToken($token)
            ->getJson('/api/public/card-' . $owner->id . '?_metadata=1')
            ->assertOk()
            ->assertJsonPath('contact_details_visible', false)
            ->assertJsonPath('phone', null);
    }

    public function test_searchability_is_independent_from_visibility(): void
    {
        $viewer = $this->member();
        $searchableOwner = $this->member();
        $hiddenOwner = $this->member();

        $visible = $this->publishedProfile($searchableOwner, [
            'slug' => 'discoverable-card',
            'is_searchable' => true,
        ]);
        $notSearchable = $this->publishedProfile($hiddenOwner, [
            'slug' => 'hidden-card',
            'is_searchable' => false,
        ]);

        $token = $viewer->createToken('test')->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/profiles/discover')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($notSearchable->id));

        // Direct URL access still works when only searchability is off.
        $this->withToken($token)
            ->getJson('/api/public/hidden-card?_metadata=1')
            ->assertOk();
    }

    public function test_contactability_setting_blocks_connection_requests(): void
    {
        $owner = $this->member();
        $this->publishedProfile($owner, ['is_contactable' => false, 'slug' => 'closed-card']);

        $this->postJson('/api/connections', [
            'slug' => 'closed-card',
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
        ])->assertStatus(403);
    }

    public function test_connection_requests_per_day_entitlement_is_enforced(): void
    {
        config(['roicard.plans.limits.CONNECTION_REQUESTS_PER_DAY' => 1]);

        $owner = $this->member();
        $this->publishedProfile($owner, ['slug' => 'daily-card']);

        $requester = $this->member(['email' => 'requester@example.com']);

        $payload = [
            'slug' => 'daily-card',
            'guest_name' => 'Requester',
            'guest_email' => $requester->email,
        ];

        $this->postJson('/api/connections', $payload)->assertCreated();

        $this->postJson('/api/connections', $payload)
            ->assertStatus(422)
            ->assertJsonPath('limit_key', 'CONNECTION_REQUESTS_PER_DAY');
    }

    public function test_restricted_profile_does_not_expose_meeting_booking_endpoints(): void
    {
        $owner = $this->member();
        $profile = $this->publishedProfile($owner, ['visibility' => 'private']);

        MeetingType::create([
            'user_id' => $owner->id,
            'name' => 'Private Call',
            'duration_minutes' => 30,
            'format' => MeetingType::FORMAT_GOOGLE_MEET,
            'buffer_minutes' => 0,
            'capacity' => 1,
            'is_active' => true,
            'sort_order' => 0,
            'min_notice_hours' => 2,
            'advance_booking_days' => 30,
        ]);

        $this->getJson('/api/public/' . $profile->slug . '/meeting-types')
            ->assertStatus(403);
    }

    public function test_blocked_members_cannot_bypass_direct_profile_or_search_access(): void
    {
        $owner = $this->member();
        $viewer = $this->member();
        $profile = $this->publishedProfile($owner, [
            'slug' => 'blocked-profile',
            'title' => 'Blocked Search Target',
            'contact_details_visibility' => 'public',
            'contact_visibility' => [
                'phone' => 'public',
                'whatsapp' => 'public',
                'email' => 'public',
                'website' => 'public',
            ],
        ]);
        $token = $viewer->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/public/blocked-profile?_metadata=1')
            ->assertOk();

        app(BlockService::class)->block($viewer, $owner->id);

        $this->withToken($token)
            ->getJson('/api/public/blocked-profile?_metadata=1')
            ->assertStatus(403);

        $search = $this->withToken($token)
            ->getJson('/api/search?q=Blocked%20Search%20Target')
            ->assertOk();

        $this->assertFalse(collect($search->json('data'))->pluck('profile.id')->contains($profile->id));

        $service = app(ProfileVisibilityService::class);
        $this->assertFalse($service->canView($viewer->fresh(), $profile));
        $this->assertSame(
            ['phone' => false, 'whatsapp' => false, 'email' => false, 'website' => false],
            $service->visibleContactFields($viewer->fresh(), $profile),
        );
    }

    public function test_community_discoverability_can_be_disabled_per_community_without_hiding_direct_url(): void
    {
        $owner = $this->member();
        $viewer = $this->member();
        $profile = $this->publishedProfile($owner, [
            'slug' => 'community-discoverability',
            'title' => 'Community Discoverability Target',
            'visibility' => 'communities',
            'is_searchable' => true,
        ]);

        $community = Community::create([
            'owner_user_id' => $owner->id,
            'name' => 'Discoverability Circle',
            'slug' => 'discoverability-circle',
            'type' => Community::TYPE_GROUP,
            'join_method' => Community::JOIN_OPEN,
            'status' => Community::STATUS_ACTIVE,
        ]);
        $memberships = app(CommunityMembershipService::class);
        $ownerMembership = $memberships->addMember($community, $owner, CommunityMember::ROLE_OWNER);
        $memberships->addMember($community, $viewer);
        $token = $viewer->createToken('test')->plainTextToken;

        $first = $this->withToken($token)
            ->getJson('/api/search?q=Community%20Discoverability%20Target')
            ->assertOk();
        $this->assertTrue(collect($first->json('data'))->pluck('profile.id')->contains($profile->id));

        $ownerMembership->forceFill(['discoverable' => false])->save();

        $second = $this->withToken($token)
            ->getJson('/api/search?q=Community%20Discoverability%20Target')
            ->assertOk();
        $this->assertFalse(collect($second->json('data'))->pluck('profile.id')->contains($profile->id));

        // Discoverability is not visibility: direct access still follows the
        // profile's communities visibility rule.
        $this->withToken($token)
            ->getJson('/api/public/community-discoverability?_metadata=1')
            ->assertOk();
    }

    public function test_searchability_toggle_removes_profile_from_search_immediately(): void
    {
        $owner = $this->member();
        $viewer = $this->member();
        $profile = $this->publishedProfile($owner, [
            'slug' => 'search-toggle-profile',
            'title' => 'Search Toggle Target',
            'is_searchable' => true,
        ]);
        $token = $viewer->createToken('test')->plainTextToken;

        $first = $this->withToken($token)
            ->getJson('/api/search?q=Search%20Toggle%20Target')
            ->assertOk();
        $this->assertTrue(collect($first->json('data'))->pluck('profile.id')->contains($profile->id));

        $profile->update(['is_searchable' => false]);

        $second = $this->withToken($token)
            ->getJson('/api/search?q=Search%20Toggle%20Target')
            ->assertOk();
        $this->assertFalse(collect($second->json('data'))->pluck('profile.id')->contains($profile->id));

        // Direct URL remains available because searchability is separate.
        $this->withToken($token)
            ->getJson('/api/public/search-toggle-profile?_metadata=1')
            ->assertOk();
    }

    public function test_visibility_service_denies_non_owner_private_and_deactivated_viewers(): void
    {
        $owner = $this->member();
        $viewer = $this->member();
        $private = $this->publishedProfile($owner, ['visibility' => 'private']);

        $service = app(ProfileVisibilityService::class);

        $this->assertFalse($service->canView($viewer, $private));
        $this->assertFalse($service->canView(null, $private));
        $this->assertTrue($service->canView($owner, $private));

        $memberProfile = $this->publishedProfile($owner, [
            'slug' => 'members-only',
            'visibility' => 'members',
        ]);

        $viewer->forceFill(['deactivated_at' => now()])->save();
        $this->assertFalse($service->canView($viewer->fresh(), $memberProfile));
    }
}
