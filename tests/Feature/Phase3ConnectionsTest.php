<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Connection;
use App\Models\MemberReport;
use App\Models\Profile;
use App\Models\ProfileSignal;
use App\Models\User;
use App\Notifications\MemberConnectionAcceptedNotification;
use App\Notifications\MemberConnectionRequestNotification;
use App\Services\BlockService;
use App\Services\CommunityMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Phase3ConnectionsTest extends TestCase
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
            'slug' => 'connect-' . $user->id . '-' . uniqid(),
            'title' => 'Product Designer',
            'organisation' => 'Acme',
            'location' => 'Accra, Ghana',
            'contact_email' => $user->email,
            'contact_visibility' => ['email' => 'connections', 'phone' => 'connections', 'website' => 'connections', 'whatsapp' => 'connections'],
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
        ], $attributes));
    }

    private function sendRequest(User $sender, Profile $recipient, array $extra = [])
    {
        if (!$sender->defaultProfile()) {
            $this->profile($sender);
        }

        return $this->using($sender->createToken('test')->plainTextToken)
            ->postJson('/api/member-connections', array_merge([
                'profile_id' => $recipient->id,
                'note' => 'Would love to connect.',
                'match_reason' => 'You share a design category.',
                'reason_codes' => ['offering_meets_seeking'],
            ], $extra));
    }

    public function test_member_connection_lifecycle_unlocks_contact_and_appears_for_both_members(): void
    {
        Notification::fake();

        $sender = $this->member();
        $recipient = $this->member();
        $senderProfile = $this->profile($sender);
        $recipientProfile = $this->profile($recipient);
        $senderToken = $sender->createToken('test')->plainTextToken;
        $recipientToken = $recipient->createToken('test')->plainTextToken;

        $this->using($senderToken)
            ->getJson('/api/public/' . $recipientProfile->slug . '?_metadata=1')
            ->assertOk()
            ->assertJsonPath('contact_email', null);

        $response = $this->sendRequest($sender, $recipientProfile)->assertCreated();
        $connectionId = $response->json('connection.id');
        $response->assertJsonPath('connection.status', Connection::STATUS_PENDING)
            ->assertJsonPath('connection.member_note', 'Would love to connect.');

        Notification::assertSentTo($recipient, MemberConnectionRequestNotification::class);

        $this->using($recipientToken)
            ->patchJson("/api/member-connections/{$connectionId}/accept")
            ->assertOk()
            ->assertJsonPath('connection.status', Connection::STATUS_APPROVED);

        Notification::assertSentTo($sender, MemberConnectionAcceptedNotification::class);

        $this->using($senderToken)
            ->getJson('/api/public/' . $recipientProfile->slug . '?_metadata=1')
            ->assertOk()
            ->assertJsonPath('contact_email', $recipient->email);

        $this->using($senderToken)
            ->getJson('/api/connections')
            ->assertOk()
            ->assertJsonFragment(['id' => $connectionId, 'status' => Connection::STATUS_APPROVED]);

        $this->using($recipientToken)
            ->getJson('/api/connections')
            ->assertOk()
            ->assertJsonFragment(['id' => $connectionId, 'status' => Connection::STATUS_APPROVED]);
    }

    public function test_duplicate_pending_and_duplicate_connection_pairs_are_prevented(): void
    {
        $sender = $this->member();
        $recipient = $this->member();
        $recipientProfile = $this->profile($recipient);

        $this->sendRequest($sender, $recipientProfile)->assertCreated();
        $this->sendRequest($sender, $recipientProfile)->assertStatus(422);

        $connection = Connection::firstOrFail();
        $this->using($recipient->createToken('test')->plainTextToken)
            ->patchJson("/api/member-connections/{$connection->id}/accept")
            ->assertOk();

        $this->sendRequest($sender, $recipientProfile)->assertStatus(422);
        $this->assertSame(1, Connection::where('pair_key', Connection::pairKey($sender->id, $recipient->id))->count());
    }

    public function test_declined_request_has_a_30_day_cooldown_and_no_decline_notification(): void
    {
        Notification::fake();

        $sender = $this->member();
        $recipient = $this->member();
        $recipientProfile = $this->profile($recipient);
        $request = $this->sendRequest($sender, $recipientProfile)->assertCreated();
        $connection = Connection::findOrFail($request->json('connection.id'));

        $this->using($recipient->createToken('test')->plainTextToken)
            ->patchJson("/api/member-connections/{$connection->id}/decline")
            ->assertOk();

        Notification::assertNotSentTo($sender, MemberConnectionAcceptedNotification::class);

        $this->sendRequest($sender, $recipientProfile)->assertStatus(422);

        $connection->forceFill(['responded_at' => now()->subDays(31)])->save();
        $this->sendRequest($sender, $recipientProfile)->assertCreated();

        // The member who declined may still initiate their own request; the
        // cooldown applies only to the sender whose request was declined.
        $otherSender = $this->member();
        $otherRecipient = $this->member();
        $otherRecipientProfile = $this->profile($otherRecipient);
        $otherRequest = $this->sendRequest($otherSender, $otherRecipientProfile)->assertCreated();
        $otherConnection = Connection::findOrFail($otherRequest->json('connection.id'));

        $this->using($otherRecipient->createToken('test')->plainTextToken)
            ->patchJson("/api/member-connections/{$otherConnection->id}/decline")
            ->assertOk();

        $this->using($otherRecipient->createToken('test')->plainTextToken)
            ->postJson('/api/member-connections', [
                'profile_id' => $otherSender->defaultProfile()->id,
                'note' => 'I would like to connect instead.',
            ])
            ->assertCreated();
    }

    public function test_daily_weekly_and_new_account_request_limits_are_configurable(): void
    {
        config([
            'roicard.plans.limits.CONNECTION_REQUESTS_PER_DAY' => 1,
            'roicard.connections.weekly_limit' => null,
            'roicard.connections.new_account_days' => null,
            'roicard.connections.new_account_daily_limit' => null,
        ]);

        $sender = $this->member();
        $first = $this->profile($this->member());
        $second = $this->profile($this->member());

        $this->sendRequest($sender, $first)->assertCreated();
        $this->sendRequest($sender, $second)->assertStatus(422)
            ->assertJsonPath('limit_key', 'CONNECTION_REQUESTS_PER_DAY');

        config([
            'roicard.plans.limits.CONNECTION_REQUESTS_PER_DAY' => null,
            'roicard.connections.weekly_limit' => 1,
        ]);
        $weeklySender = $this->member();
        $this->sendRequest($weeklySender, $first)->assertCreated();
        $this->sendRequest($weeklySender, $second)->assertStatus(422)
            ->assertJsonPath('limit_key', 'CONNECTION_REQUESTS_PER_WEEK');

        config([
            'roicard.plans.limits.CONNECTION_REQUESTS_PER_DAY' => null,
            'roicard.connections.weekly_limit' => null,
            'roicard.connections.new_account_days' => 7,
            'roicard.connections.new_account_daily_limit' => 1,
        ]);
        $newSender = $this->member(['created_at' => now(), 'email_verified_at' => null]);
        $this->sendRequest($newSender, $first)->assertCreated();
        $this->sendRequest($newSender, $second)->assertStatus(422)
            ->assertJsonPath('limit_key', 'CONNECTION_REQUESTS_PER_DAY');
    }

    public function test_connection_request_policy_communities_matching_and_none(): void
    {
        $sender = $this->member();
        $recipient = $this->member();
        $recipientProfile = $this->profile($recipient, [
            'connection_request_policy' => 'none',
        ]);

        $this->sendRequest($sender, $recipientProfile)->assertStatus(422);

        $recipientProfile->forceFill(['connection_request_policy' => 'communities'])->save();
        $this->sendRequest($sender, $recipientProfile)->assertStatus(422);

        $community = Community::create([
            'owner_user_id' => $recipient->id,
            'name' => 'Privacy Test Community',
            'slug' => 'privacy-test-community',
            'type' => Community::TYPE_GROUP,
            'join_method' => Community::JOIN_OPEN,
            'status' => Community::STATUS_ACTIVE,
        ]);
        $memberships = app(CommunityMembershipService::class);
        $memberships->addMember($community, $recipient, CommunityMember::ROLE_OWNER);
        $memberships->addMember($community, $sender);

        $this->sendRequest($sender, $recipientProfile)->assertCreated();

        // Matching-only policy.
        $matchingRecipient = $this->member();
        $matchingProfile = $this->profile($matchingRecipient, [
            'connection_request_policy' => 'matching',
        ]);
        $matchingSender = $this->member();
        $matchingSenderProfile = $this->profile($matchingSender);
        $this->sendRequest($matchingSender, $matchingProfile)->assertStatus(422);

        $seeking = Category::create([
            'kind' => Category::KIND_SEEKING,
            'name' => 'Privacy Match',
            'slug' => 'privacy-match-seeking',
            'is_active' => true,
        ]);
        $offering = Category::create([
            'kind' => Category::KIND_OFFERING,
            'name' => 'Privacy Match',
            'slug' => 'privacy-match-offering',
            'is_active' => true,
        ]);

        $need = ProfileSignal::create([
            'profile_id' => $matchingProfile->id,
            'type' => ProfileSignal::TYPE_SEEKING,
            'description' => 'Need a privacy match.',
            'is_active' => true,
            'source' => 'member',
        ]);
        $need->categories()->sync([$seeking->id]);

        $offer = ProfileSignal::create([
            'profile_id' => $matchingSenderProfile->id,
            'type' => ProfileSignal::TYPE_OFFERING,
            'description' => 'Offer a privacy match.',
            'is_active' => true,
            'source' => 'member',
        ]);
        $offer->categories()->sync([$offering->id]);

        $this->sendRequest($matchingSender, $matchingProfile)->assertCreated();
    }

    public function test_established_new_account_is_not_held_to_reduced_limit(): void
    {
        config([
            'roicard.plans.limits.CONNECTION_REQUESTS_PER_DAY' => null,
            'roicard.connections.weekly_limit' => null,
            'roicard.connections.new_account_days' => 7,
            'roicard.connections.new_account_daily_limit' => 1,
        ]);

        $sender = $this->member(['created_at' => now()]);
        $first = $this->profile($this->member());
        $second = $this->profile($this->member());

        $this->sendRequest($sender, $first)->assertCreated();
        $this->sendRequest($sender, $second)->assertCreated();
    }

    public function test_withdraw_and_remove_connection_actions(): void
    {
        $sender = $this->member();
        $recipient = $this->member();
        $recipientProfile = $this->profile($recipient);
        $senderToken = $sender->createToken('test')->plainTextToken;
        $recipientToken = $recipient->createToken('test')->plainTextToken;

        $created = $this->sendRequest($sender, $recipientProfile)->assertCreated();
        $connectionId = $created->json('connection.id');

        $this->using($senderToken)
            ->patchJson("/api/member-connections/{$connectionId}/withdraw")
            ->assertOk()
            ->assertJsonPath('connection.status', Connection::STATUS_WITHDRAWN);

        $resent = $this->sendRequest($sender, $recipientProfile)->assertCreated();
        $connectionId = $resent->json('connection.id');

        $this->using($recipientToken)
            ->patchJson("/api/member-connections/{$connectionId}/accept")
            ->assertOk();

        $this->using($senderToken)
            ->deleteJson("/api/member-connections/{$connectionId}")
            ->assertOk()
            ->assertJsonPath('connection.status', Connection::STATUS_REMOVED);
    }

    public function test_blocking_prevents_requests_and_reporting_creates_moderation_record(): void
    {
        $sender = $this->member();
        $recipient = $this->member();
        $recipientProfile = $this->profile($recipient);

        app(BlockService::class)->block($sender, $recipient->id);

        $this->sendRequest($sender, $recipientProfile)->assertStatus(422);

        $reporter = $this->member();
        $target = $this->member();
        $targetProfile = $this->profile($target);

        $this->using($reporter->createToken('test')->plainTextToken)
            ->postJson('/api/reports', [
                'profile_id' => $targetProfile->id,
                'reason_category' => 'spam',
                'description' => 'Repeated unsolicited messages.',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('member_reports', [
            'reporter_id' => $reporter->id,
            'reported_user_id' => $target->id,
            'reason_category' => 'spam',
            'status' => MemberReport::STATUS_PENDING,
        ]);

        $this->using($reporter->createToken('test')->plainTextToken)
            ->postJson('/api/reports', [
                'profile_id' => $this->profile($reporter)->id,
                'reason_category' => 'other',
            ])
            ->assertStatus(422);
    }

    public function test_member_request_records_the_selected_sender_profile(): void
    {
        $sender = $this->member();
        $recipient = $this->member();
        $recipientProfile = $this->profile($recipient);
        $personal = $this->profile($sender, ['slug' => 'sender-personal']);
        $business = $this->profile($sender, [
            'slug' => 'sender-business',
            'profile_type' => 'business',
            'organisation' => 'Sender Co',
        ]);

        $response = $this->using($sender->createToken('test')->plainTextToken)
            ->postJson('/api/member-connections', [
                'profile_id' => $recipientProfile->id,
                'sender_profile_id' => $business->id,
                'note' => 'Sent from my business profile.',
            ])
            ->assertCreated();

        $this->assertSame($business->id, $response->json('connection.sender_profile_id'));
        $this->assertSame($recipientProfile->id, $response->json('connection.recipient_profile_id'));
        $this->assertDatabaseHas('connections', [
            'id' => $response->json('connection.id'),
            'sender_profile_id' => $business->id,
        ]);

        $this->assertNotSame($personal->id, $response->json('connection.sender_profile_id'));
    }

    public function test_guest_connect_flow_still_works(): void
    {
        $recipient = $this->member();
        $recipientProfile = $this->profile($recipient);

        $response = $this->postJson('/api/connections', [
            'slug' => $recipientProfile->slug,
            'guest_name' => 'Guest Person',
            'guest_email' => 'guest@example.com',
            'guest_intent' => 'Wants to collaborate.',
        ])->assertCreated();

        $connection = Connection::findOrFail($response->json('connection.id'));
        $this->assertSame('guest', $connection->request_type);

        $this->using($recipient->createToken('test')->plainTextToken)
            ->patchJson("/api/connections/{$connection->id}", ['action' => 'approve'])
            ->assertOk();

        $this->assertSame(Connection::STATUS_APPROVED, $connection->fresh()->status);
    }
}
