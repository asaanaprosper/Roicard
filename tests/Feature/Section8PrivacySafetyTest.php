<?php

namespace Tests\Feature;

use App\Models\AdminActionLog;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Connection;
use App\Models\MemberReport;
use App\Models\Profile;
use App\Models\User;
use App\Services\CommunityMembershipService;
use App\Services\ProfileManager;
use App\Services\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Section8PrivacySafetyTest extends TestCase
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
            'slug' => 'section8-' . $user->id . '-' . uniqid(),
            'title' => 'Privacy Target',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
            'is_contactable' => true,
            'completion_pct' => 80,
        ], $attributes));
    }

    private function community(User $owner): Community
    {
        return Community::create([
            'owner_user_id' => $owner->id,
            'name' => 'Section 8 Community ' . uniqid(),
            'slug' => 'section-8-community-' . uniqid(),
            'type' => Community::TYPE_GROUP,
            'join_method' => Community::JOIN_OPEN,
            'status' => Community::STATUS_ACTIVE,
        ]);
    }

    public function test_community_discoverability_is_enforced_for_community_scoped_search(): void
    {
        $owner = $this->member();
        $viewer = $this->member();
        $profile = $this->profile($owner, [
            'title' => 'Community Scoped Target',
            'visibility' => 'members',
        ]);

        $community = $this->community($owner);
        $memberships = app(CommunityMembershipService::class);
        $ownerMembership = $memberships->addMember($community, $owner, CommunityMember::ROLE_OWNER);
        $memberships->addMember($community, $viewer);

        $token = $viewer->createToken('test')->plainTextToken;

        // A global search still finds a searchable members-only profile.
        $global = $this->withToken($token)
            ->getJson('/api/search?q=' . urlencode('Community Scoped Target'))
            ->assertOk();
        $this->assertTrue(collect($global->json('data'))->pluck('profile.id')->contains($profile->id));

        // Inside that community, the per-membership opt-out wins.
        $ownerMembership->forceFill(['discoverable' => false])->save();

        $scoped = $this->withToken($token)
            ->getJson('/api/search?community=' . $community->slug)
            ->assertOk();
        $this->assertFalse(collect($scoped->json('data'))->pluck('profile.id')->contains($profile->id));

        $communityPeople = app(SearchService::class)->search($viewer, [
            'community_ids' => [$community->id],
        ]);
        $this->assertFalse(
            collect($communityPeople->items())->pluck('profile.id')->contains($profile->id)
        );

        $ownerMembership->forceFill(['discoverable' => true])->save();

        $visibleAgain = $this->withToken($token)
            ->getJson('/api/search?community=' . $community->slug)
            ->assertOk();
        $this->assertTrue(collect($visibleAgain->json('data'))->pluck('profile.id')->contains($profile->id));
    }

    public function test_community_membership_changes_invalidate_discovery_and_public_caches(): void
    {
        $owner = $this->member();
        $viewer = $this->member();
        $profile = $this->profile($viewer);
        $community = $this->community($owner);

        $memberships = app(CommunityMembershipService::class);
        $memberships->addMember($community, $owner, CommunityMember::ROLE_OWNER);
        $memberships->addMember($community, $viewer);

        Cache::put('matchmaking:version', 10, now()->addHour());
        Cache::put(Profile::PUBLIC_CACHE_PREFIX . $profile->slug, ['stale' => true], now()->addHour());

        $memberships->leave($community, $viewer);

        $this->assertGreaterThan(10, (int) Cache::get('matchmaking:version'));
        $this->assertNull(Cache::get(Profile::PUBLIC_CACHE_PREFIX . $profile->slug));
    }

    public function test_public_guest_requests_respect_decline_cooldown_and_no_request_policy(): void
    {
        $owner = $this->member();
        $profile = $this->profile($owner, ['slug' => 'guest-cooldown-card']);
        $ownerToken = $owner->createToken('test')->plainTextToken;

        $payload = [
            'slug' => $profile->slug,
            'guest_name' => 'Guest Person',
            'guest_email' => 'guest-cooldown@example.com',
        ];

        $created = $this->postJson('/api/connections', $payload)->assertCreated();
        $connection = Connection::findOrFail($created->json('connection.id'));

        $this->app['auth']->forgetGuards();
        $this->withToken($ownerToken)
            ->patchJson("/api/connections/{$connection->id}", ['action' => 'decline'])
            ->assertOk();

        // Public guest requests carry no bearer token; clear the header left
        // by the owner's decline request.
        $this->withHeaders(['Authorization' => '']);
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/connections', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This member declined a recent request. You can try again after '
                . $connection->fresh()->responded_at->copy()->addDays(30)->toFormattedDateString() . '.');

        $connection->forceFill(['responded_at' => now()->subDays(31)])->save();
        $this->postJson('/api/connections', $payload)->assertCreated();

        $profile->forceFill(['connection_request_policy' => 'none'])->save();

        $this->postJson('/api/connections', $payload)->assertStatus(403);
    }

    public function test_authenticated_member_cannot_bypass_request_policy_through_the_guest_endpoint(): void
    {
        $owner = $this->member();
        $viewer = $this->member();
        $profile = $this->profile($owner, [
            'slug' => 'guest-endpoint-policy-card',
            'connection_request_policy' => 'none',
        ]);
        $this->profile($viewer, ['slug' => 'guest-endpoint-sender']);

        $token = $viewer->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/connections', [
                'slug' => $profile->slug,
                'guest_name' => 'Member Using Guest Form',
                'guest_email' => $viewer->email,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile']);
    }

    public function test_report_from_a_connection_records_the_counterpart_and_rejects_mismatched_targets(): void
    {
        $sender = $this->member();
        $recipient = $this->member();
        $senderProfile = $this->profile($sender, ['slug' => 'report-sender']);
        $recipientProfile = $this->profile($recipient, ['slug' => 'report-recipient']);
        $unrelated = $this->profile($this->member(), ['slug' => 'report-unrelated']);

        $connection = Connection::create([
            'member_id' => $recipient->id,
            'guest_user_id' => $sender->id,
            'guest_name' => 'Sender',
            'guest_email' => $sender->email,
            'status' => Connection::STATUS_PENDING,
            'request_type' => Connection::TYPE_MEMBER,
            'sender_profile_id' => $senderProfile->id,
            'recipient_profile_id' => $recipientProfile->id,
            'pair_key' => Connection::pairKey($sender->id, $recipient->id),
        ]);

        $token = $recipient->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/reports', [
                'connection_id' => $connection->id,
                'reason_category' => 'harassment',
                'description' => 'Reported from the connection inbox.',
            ])
            ->assertCreated();

        $this->assertSame($sender->id, $response->json('report.reported_user_id'));
        $this->assertSame($senderProfile->id, $response->json('report.profile_id'));
        $this->assertSame(MemberReport::STATUS_PENDING, $response->json('report.status'));

        $this->withToken($token)
            ->postJson('/api/reports', [
                'connection_id' => $connection->id,
                'profile_id' => $unrelated->id,
                'reason_category' => 'spam',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The report target does not match this connection.');
    }

    public function test_blocked_member_list_does_not_expose_email_addresses(): void
    {
        $blocker = $this->member();
        $blocked = $this->member(['email' => 'blocked-private@example.com']);
        $this->profile($blocked, ['display_name' => 'Blocked Member']);

        $token = $blocker->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/blocks', ['user_id' => $blocked->id])
            ->assertCreated();

        $response = $this->withToken($token)->getJson('/api/blocks')->assertOk();

        $this->assertArrayNotHasKey('email', $response->json('blocked.0'));
        $this->assertSame('Blocked Member', $response->json('blocked.0.profile.display_name'));
    }

    public function test_deleting_a_profile_removes_its_media_files_and_rows(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $user = $this->member();
        $keep = $this->profile($user, ['slug' => 'keep-profile']);
        $remove = $this->profile($user, ['slug' => 'remove-profile']);

        $media = $remove->addMedia(UploadedFile::fake()->image('avatar.jpg'))
            ->toMediaCollection('avatar');
        $path = $media->getPathRelativeToRoot();

        Storage::disk($media->disk)->assertExists($path);

        app(ProfileManager::class)->delete($user, $remove);

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertDatabaseMissing('profiles', ['id' => $remove->id]);
        Storage::disk($media->disk)->assertMissing($path);
        $this->assertDatabaseHas('profiles', ['id' => $keep->id]);
    }

    public function test_account_purge_removes_profile_media_after_the_retention_window(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        config(['app.data_retention_days' => 30]);

        $user = $this->member();
        $profile = $this->profile($user, ['slug' => 'purge-media-profile']);
        $media = $profile->addMedia(UploadedFile::fake()->image('avatar.jpg'))
            ->toMediaCollection('avatar');
        $path = $media->getPathRelativeToRoot();

        $user->delete();
        User::withTrashed()->findOrFail($user->id)
            ->forceFill(['deleted_at' => now()->subDays(60)])
            ->save();

        $this->artisan('users:purge')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        Storage::disk($media->disk)->assertMissing($path);
    }

    public function test_account_export_includes_privacy_settings_and_community_discoverability(): void
    {
        $user = $this->member(['email' => 'privacy-export@example.com']);
        $profile = $this->profile($user, [
            'slug' => 'privacy-export-profile',
            'privacy_confirmed_at' => now(),
        ]);
        $community = $this->community($user);
        $membership = app(CommunityMembershipService::class)
            ->addMember($community, $user, CommunityMember::ROLE_OWNER);
        $membership->forceFill(['discoverable' => false])->save();

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/auth/account/export')
            ->assertOk()
            ->assertJsonPath('profiles.0.id', $profile->id)
            ->assertJsonPath('communities.0.discoverable', false)
            ->assertJsonPath('profiles.0.connection_request_policy', null);

        $this->assertNotNull($response->json('profiles.0.privacy_confirmed_at'));
    }

    public function test_deleting_an_account_clears_secondary_profile_caches(): void
    {
        $user = $this->member([
            'password' => bcrypt('Pass123'),
        ]);
        $user->profiles()->create(['slug' => 'delete-default-profile', 'is_live' => true]);
        $secondary = $user->profiles()->create([
            'slug' => 'delete-secondary-profile',
            'is_live' => true,
        ]);

        Cache::put('matchmaking:version', 4, now()->addHour());
        Cache::put(Profile::PUBLIC_CACHE_PREFIX . $secondary->slug, ['stale' => true], now()->addHour());

        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->deleteJson('/api/auth/account', ['current_password' => 'Pass123'])
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertGreaterThan(4, (int) Cache::get('matchmaking:version'));
        $this->assertNull(Cache::get(Profile::PUBLIC_CACHE_PREFIX . $secondary->slug));
    }

    public function test_legacy_discovery_endpoint_respects_the_candidate_cap(): void
    {
        config(['roicard.search.max_candidates' => 2]);

        $viewer = $this->member();
        $token = $viewer->createToken('test')->plainTextToken;

        for ($i = 0; $i < 4; $i++) {
            $this->profile($this->member(), [
                'slug' => 'discovery-cap-' . $i,
                'display_name' => 'Discovery Cap ' . $i,
            ]);
        }

        $response = $this->withToken($token)
            ->getJson('/api/profiles/discover?per_page=50')
            ->assertOk();

        $this->assertSame(2, $response->json('total'));
        $this->assertCount(2, $response->json('data'));
    }

    public function test_moderation_suspension_is_written_to_the_audit_trail(): void
    {
        $admin = $this->member(['role' => 'admin']);
        $reporter = $this->member();
        $reported = $this->member();
        $reportedProfile = $this->profile($reported);

        $report = MemberReport::create([
            'reporter_id' => $reporter->id,
            'reported_user_id' => $reported->id,
            'profile_id' => $reportedProfile->id,
            'reason_category' => 'harassment',
            'status' => MemberReport::STATUS_PENDING,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/admin/reports/{$report->id}", [
                'status' => 'actioned',
                'action' => 'suspend_user',
                'admin_notes' => 'Confirmed and suspended.',
            ])
            ->assertOk();

        $log = AdminActionLog::where('action', 'moderation.member_suspended')->firstOrFail();

        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('User', $log->target_type);
        $this->assertSame((string) $reported->id, $log->target_id);
        $this->assertSame($report->id, $log->metadata['report_id']);
    }
}
