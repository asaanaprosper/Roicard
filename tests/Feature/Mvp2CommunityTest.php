<?php

namespace Tests\Feature;

use App\Exceptions\EntitlementExceededException;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\User;
use App\Services\CommunityMembershipService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class Mvp2CommunityTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['status' => 'active'], $attributes));
    }

    private function community(array $attributes = []): Community
    {
        $owner = $attributes['owner_user_id'] ?? null;
        $owner ??= $this->member()->id;

        return Community::create(array_merge([
            'owner_user_id' => $owner,
            'name' => 'Community ' . uniqid(),
            'slug' => 'community-' . uniqid(),
            'type' => Community::TYPE_GROUP,
            'join_method' => Community::JOIN_OPEN,
            'status' => Community::STATUS_ACTIVE,
        ], $attributes));
    }

    public function test_joining_an_open_community_is_idempotent(): void
    {
        $user = $this->member();
        $community = $this->community();
        $service = app(CommunityMembershipService::class);

        $first = $service->join($community, $user);
        $second = $service->join($community, $user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(CommunityMember::STATUS_ACTIVE, $first->status);
        $this->assertSame(1, CommunityMember::where('community_id', $community->id)->where('user_id', $user->id)->count());
    }

    public function test_community_member_uniqueness_is_enforced_at_database_level(): void
    {
        $user = $this->member();
        $community = $this->community();

        CommunityMember::create([
            'community_id' => $community->id,
            'user_id' => $user->id,
            'role' => CommunityMember::ROLE_MEMBER,
            'status' => CommunityMember::STATUS_ACTIVE,
            'join_method' => CommunityMember::JOIN_OPEN,
        ]);

        $this->expectException(QueryException::class);

        CommunityMember::create([
            'community_id' => $community->id,
            'user_id' => $user->id,
            'role' => CommunityMember::ROLE_MEMBER,
            'status' => CommunityMember::STATUS_ACTIVE,
            'join_method' => CommunityMember::JOIN_OPEN,
        ]);
    }

    public function test_request_and_invite_join_methods_are_respected(): void
    {
        $user = $this->member();
        $service = app(CommunityMembershipService::class);

        $requestCommunity = $this->community(['join_method' => Community::JOIN_REQUEST]);
        $request = $service->join($requestCommunity, $user);
        $this->assertSame(CommunityMember::STATUS_PENDING, $request->status);

        $activate = $service->activate($request);
        $this->assertSame(CommunityMember::STATUS_ACTIVE, $activate->status);

        $inviteCommunity = $this->community(['join_method' => Community::JOIN_INVITE]);

        $this->expectException(AccessDeniedHttpException::class);
        $service->join($inviteCommunity, $this->member());
    }

    public function test_communities_joined_entitlement_is_enforced(): void
    {
        config(['roicard.plans.limits.COMMUNITIES_JOINED' => 1]);

        $user = $this->member();
        $service = app(CommunityMembershipService::class);

        $service->join($this->community(), $user);

        $this->expectException(EntitlementExceededException::class);

        $service->join($this->community(), $user);
    }

    public function test_member_can_join_and_leave_through_the_api(): void
    {
        $user = $this->member();
        $community = $this->community();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/communities/{$community->id}/join")
            ->assertCreated()
            ->assertJsonPath('membership.status', CommunityMember::STATUS_ACTIVE);

        $this->withToken($token)
            ->getJson('/api/communities/mine')
            ->assertOk()
            ->assertJsonCount(1, 'memberships');

        $this->withToken($token)
            ->deleteJson("/api/communities/{$community->id}/leave")
            ->assertOk()
            ->assertJsonPath('membership.status', CommunityMember::STATUS_LEFT);
    }

    public function test_organization_community_seat_limit_is_enforced(): void
    {
        $owner = $this->member();
        $community = $this->community([
            'owner_user_id' => $owner->id,
            'type' => Community::TYPE_ORGANIZATION,
            'member_seat_limit' => 1,
        ]);

        $service = app(CommunityMembershipService::class);
        $service->addMember($community, $owner, CommunityMember::ROLE_OWNER);

        // One seat is occupied by the owner.
        $this->expectException(EntitlementExceededException::class);
        $service->join($community, $this->member());
    }
}
