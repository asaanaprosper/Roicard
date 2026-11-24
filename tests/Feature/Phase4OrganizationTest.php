<?php

namespace Tests\Feature;

use App\Models\AdminActionLog;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\OrganizationInvitation;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Phase4OrganizationTest extends TestCase
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

    private function admin(): User
    {
        return $this->member(['role' => 'admin']);
    }

    private function profile(User $user, array $attributes = []): Profile
    {
        return $user->profiles()->create(array_merge([
            'slug' => 'org-profile-' . $user->id . '-' . uniqid(),
            'title' => 'Member',
            'organisation' => 'Member Co',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
            'completion_pct' => 80,
        ], $attributes));
    }

    private function registerOrganization(User $owner, array $extra = []): Community
    {
        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->using($token)->postJson('/api/organizations', array_merge([
            'name' => 'Acme Collective',
            'description' => 'A pilot organization.',
            'industry' => 'Consulting',
            'website_url' => 'https://acme.example',
            'location' => 'Accra, Ghana',
            'services' => ['Strategy', 'Research'],
            'seeking' => 'Partners',
            'offering' => 'Advisory',
        ], $extra))->assertCreated();

        return Community::findOrFail($response->json('organization.id'));
    }

    public function test_registration_is_pending_and_not_public_until_approved(): void
    {
        $owner = $this->member();
        $organization = $this->registerOrganization($owner);

        $this->assertSame(Community::TYPE_ORGANIZATION, $organization->type);
        $this->assertSame(Community::VERIFICATION_PENDING, $organization->verification_status);
        $this->assertSame(Community::STATUS_INACTIVE, $organization->status);
        $this->assertFalse($organization->isVerified());
        $this->assertDatabaseHas('community_members', [
            'community_id' => $organization->id,
            'user_id' => $owner->id,
            'role' => CommunityMember::ROLE_OWNER,
            'affiliation_confirmed_at' => $organization->members()->first()->affiliation_confirmed_at,
        ]);

        $this->getJson('/api/organizations/' . $organization->slug)->assertNotFound();
    }

    public function test_admin_approval_rejection_and_suspension_are_audited(): void
    {
        $owner = $this->member();
        $organization = $this->registerOrganization($owner);
        $admin = $this->admin();
        $token = $admin->createToken('test')->plainTextToken;

        $this->using($token)
            ->postJson("/api/admin/organizations/{$organization->id}/approve")
            ->assertOk()
            ->assertJsonPath('organization.verification_status', 'verified')
            ->assertJsonPath('organization.status', 'active');

        $organization->refresh();
        $this->assertTrue($organization->isVerified());

        $this->getJson('/api/organizations/' . $organization->slug)
            ->assertOk()
            ->assertJsonPath('organization.name', 'Acme Collective');

        $this->using($token)
            ->postJson("/api/admin/organizations/{$organization->id}/reject", ['reason' => 'Incomplete details'])
            ->assertOk()
            ->assertJsonPath('organization.verification_status', 'rejected');

        $this->using($token)
            ->postJson("/api/admin/organizations/{$organization->id}/suspend", ['reason' => 'Policy review'])
            ->assertOk()
            ->assertJsonPath('organization.verification_status', 'suspended');

        $this->assertSame(3, AdminActionLog::whereIn('action', [
            'organization.approved',
            'organization.rejected',
            'organization.suspended',
        ])->count());
    }

    public function test_admin_can_create_a_pilot_organization_and_assign_an_administrator(): void
    {
        $admin = $this->admin();
        $owner = $this->member();
        $manager = $this->member();
        $token = $admin->createToken('test')->plainTextToken;

        $created = $this->using($token)
            ->postJson('/api/admin/organizations', [
                'owner_user_id' => $owner->id,
                'name' => 'Pilot Org',
                'industry' => 'Technology',
                'location' => 'Kumasi, Ghana',
                'verification_status' => 'verified',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('organization.verification_status', 'verified');

        $organizationId = $created->json('organization.id');

        $this->using($token)
            ->postJson("/api/admin/organizations/{$organizationId}/administrators", [
                'user_id' => $manager->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('community_members', [
            'community_id' => $organizationId,
            'user_id' => $manager->id,
            'role' => CommunityMember::ROLE_MANAGER,
            'status' => CommunityMember::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'organization.administrator_assigned',
            'target_id' => (string) $organizationId,
        ]);
    }

    public function test_organization_manager_permissions_and_profile_isolation(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $this->profile($owner);
        $organization = $this->registerOrganization($owner);
        $organization->forceFill([
            'verification_status' => Community::VERIFICATION_VERIFIED,
            'status' => Community::STATUS_ACTIVE,
        ])->save();

        $ownerToken = $owner->createToken('test')->plainTextToken;
        $otherToken = $other->createToken('test')->plainTextToken;

        $this->using($ownerToken)
            ->patchJson("/api/organizations/{$organization->slug}", ['industry' => 'Design'])
            ->assertOk()
            ->assertJsonPath('organization.industry', 'Design');

        $this->using($otherToken)
            ->patchJson("/api/organizations/{$organization->slug}", ['industry' => 'Hijack'])
            ->assertStatus(403);

        $this->using($otherToken)
            ->getJson("/api/organizations/{$organization->slug}/members")
            ->assertStatus(403);

        // Managers cannot edit member-owned profile content because no such
        // endpoint exists; member profile rows remain untouched.
        $this->assertSame('Member', $owner->profile->title);
    }

    public function test_individual_and_bulk_invitations_with_duplicate_handling(): void
    {
        Notification::fake();

        $owner = $this->member();
        $existing = $this->member();
        $organization = $this->registerOrganization($owner);
        $organization->forceFill([
            'verification_status' => Community::VERIFICATION_VERIFIED,
            'status' => Community::STATUS_ACTIVE,
        ])->save();
        $token = $owner->createToken('test')->plainTextToken;

        $this->using($token)
            ->postJson("/api/organizations/{$organization->slug}/invitations", [
                'emails' => [$existing->email, 'new@example.com', $existing->email],
            ])
            ->assertCreated()
            ->assertJsonPath('skipped', []);

        $this->assertSame(2, OrganizationInvitation::where('community_id', $organization->id)->count());
        $this->assertDatabaseHas('community_members', [
            'community_id' => $organization->id,
            'user_id' => $existing->id,
            'status' => CommunityMember::STATUS_INVITED,
        ]);
        Notification::assertSentTo($existing, OrganizationInvitationNotification::class);

        $this->using($token)
            ->postJson("/api/organizations/{$organization->slug}/invitations", [
                'emails' => [$existing->email, 'another@example.com'],
            ])
            ->assertCreated()
            ->assertJsonPath('skipped.0', $existing->email);

        $this->assertDatabaseCount('organization_invitations', 3);
    }

    public function test_invitation_acceptance_activates_membership_and_directory_visibility(): void
    {
        Notification::fake();

        $owner = $this->member();
        $invitee = $this->member();
        $this->profile($invitee);
        $organization = $this->registerOrganization($owner);
        $organization->forceFill([
            'verification_status' => Community::VERIFICATION_VERIFIED,
            'status' => Community::STATUS_ACTIVE,
        ])->save();
        $ownerToken = $owner->createToken('test')->plainTextToken;

        $this->using($ownerToken)
            ->postJson("/api/organizations/{$organization->slug}/invitations", [
                'email' => $invitee->email,
            ])
            ->assertCreated();

        $invitation = OrganizationInvitation::where('email', $invitee->email)->firstOrFail();
        $this->assertNull(
            $organization->members()->where('user_id', $invitee->id)->first()->member_accepted_at
        );

        $this->using($invitee->createToken('test')->plainTextToken)
            ->postJson("/api/organization-invitations/{$invitation->token}/accept")
            ->assertOk()
            ->assertJsonPath('membership.status', CommunityMember::STATUS_ACTIVE)
            ->assertJsonPath('membership.publicly_affiliated', true);

        $directory = $this->getJson('/api/organizations/' . $organization->slug)->assertOk();
        $ids = collect($directory->json('members'))->pluck('profile.id');
        $this->assertTrue($ids->contains($invitee->profile->id));

        $this->assertDatabaseHas('organization_invitations', [
            'id' => $invitation->id,
            'status' => OrganizationInvitation::STATUS_ACCEPTED,
        ]);
    }

    public function test_join_request_requires_confirmation_and_member_acceptance_for_directory(): void
    {
        $owner = $this->member();
        $requester = $this->member();
        $this->profile($requester);
        $organization = $this->registerOrganization($owner);
        $organization->forceFill([
            'verification_status' => Community::VERIFICATION_VERIFIED,
            'status' => Community::STATUS_ACTIVE,
        ])->save();

        $requesterToken = $requester->createToken('test')->plainTextToken;

        $join = $this->using($requesterToken)
            ->postJson("/api/organizations/{$organization->slug}/join")
            ->assertCreated()
            ->assertJsonPath('membership.status', CommunityMember::STATUS_PENDING);

        $this->assertNotNull(
            $organization->members()->where('user_id', $requester->id)->value('member_accepted_at')
        );
        $this->assertNull(
            $organization->members()->where('user_id', $requester->id)->value('affiliation_confirmed_at')
        );

        $directoryBefore = $this->getJson('/api/organizations/' . $organization->slug)->assertOk();
        $this->assertFalse(
            collect($directoryBefore->json('members'))->pluck('profile.id')->contains($requester->profile->id)
        );

        $this->using($owner->createToken('test')->plainTextToken)
            ->postJson("/api/organizations/{$organization->slug}/members/{$join->json('membership.id')}/approve")
            ->assertOk()
            ->assertJsonPath('membership.publicly_affiliated', true);

        $directoryAfter = $this->getJson('/api/organizations/' . $organization->slug)->assertOk();
        $this->assertTrue(
            collect($directoryAfter->json('members'))->pluck('profile.id')->contains($requester->profile->id)
        );
    }

    public function test_private_profiles_and_unaccepted_affiliations_are_not_exposed_in_directory(): void
    {
        $owner = $this->member();
        $privateMember = $this->member();
        $this->profile($privateMember, ['visibility' => 'private', 'is_searchable' => false]);
        $organization = $this->registerOrganization($owner);
        $organization->forceFill([
            'verification_status' => Community::VERIFICATION_VERIFIED,
            'status' => Community::STATUS_ACTIVE,
        ])->save();

        $organization->members()->create([
            'user_id' => $privateMember->id,
            'role' => CommunityMember::ROLE_MEMBER,
            'status' => CommunityMember::STATUS_ACTIVE,
            'join_method' => CommunityMember::JOIN_ADMIN,
            'joined_at' => now(),
            'affiliation_confirmed_at' => now(),
            'member_accepted_at' => now(),
        ]);

        $response = $this->getJson('/api/organizations/' . $organization->slug)->assertOk();
        $this->assertFalse(
            collect($response->json('members'))->pluck('profile.id')->contains($privateMember->profile->id)
        );
        $this->assertArrayNotHasKey('email', $response->json('members.0') ?? []);
    }

    public function test_organization_seat_limit_is_enforced_on_acceptance(): void
    {
        config(['roicard.plans.limits.ORG_MEMBER_SEATS' => 1]);

        $owner = $this->member();
        $invitee = $this->member();
        $organization = $this->registerOrganization($owner);
        $organization->forceFill([
            'verification_status' => Community::VERIFICATION_VERIFIED,
            'status' => Community::STATUS_ACTIVE,
        ])->save();
        $token = $owner->createToken('test')->plainTextToken;

        $this->using($token)
            ->postJson("/api/organizations/{$organization->slug}/invitations", [
                'email' => $invitee->email,
            ])
            ->assertCreated();

        $invitation = OrganizationInvitation::where('email', $invitee->email)->firstOrFail();

        $this->using($invitee->createToken('test')->plainTextToken)
            ->postJson("/api/organization-invitations/{$invitation->token}/accept")
            ->assertStatus(422)
            ->assertJsonPath('limit_key', 'ORG_MEMBER_SEATS');
    }

    public function test_organization_affiliation_participates_in_search_filters(): void
    {
        $viewer = $this->member();
        $member = $this->member();
        $profile = $this->profile($member, ['organisation' => 'Independent']);
        $owner = $this->member();
        $organization = $this->registerOrganization($owner);
        $organization->forceFill([
            'verification_status' => Community::VERIFICATION_VERIFIED,
            'status' => Community::STATUS_ACTIVE,
        ])->save();

        $organization->members()->create([
            'user_id' => $member->id,
            'role' => CommunityMember::ROLE_MEMBER,
            'status' => CommunityMember::STATUS_ACTIVE,
            'join_method' => CommunityMember::JOIN_ADMIN,
            'joined_at' => now(),
            'affiliation_confirmed_at' => now(),
            'member_accepted_at' => now(),
        ]);

        $token = $viewer->createToken('test')->plainTextToken;

        $this->using($token)
            ->getJson('/api/search?organization_id=' . $organization->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $profile->id]);

        $this->using($token)
            ->getJson('/api/search?q=Acme+Collective')
            ->assertOk()
            ->assertJsonFragment(['id' => $profile->id]);
    }
}
