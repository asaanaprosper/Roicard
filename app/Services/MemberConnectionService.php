<?php

namespace App\Services;

use App\Exceptions\EntitlementExceededException;
use App\Models\Connection;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\MemberConnectionAcceptedNotification;
use App\Notifications\MemberConnectionRequestNotification;
use App\Support\PlanLimitKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MemberConnectionService
{
    public function __construct(
        protected EntitlementService $entitlements,
        protected ProfileVisibilityService $visibility,
        protected BlockService $blocks,
        protected MatchmakingService $matchmaking,
    ) {}

    public function sendRequest(
        User $sender,
        Profile $recipientProfile,
        ?string $note = null,
        ?string $matchReason = null,
        array $reasonCodes = [],
        ?Profile $senderProfile = null,
    ): Connection {
        $recipient = $recipientProfile->user;

        if (!$recipient) {
            throw ValidationException::withMessages(['profile' => 'This profile is not available.']);
        }

        if ((string) $sender->getKey() === (string) $recipient->getKey()) {
            throw ValidationException::withMessages(['profile' => 'You cannot connect to your own profile.']);
        }

        $this->blocks->assertNotBlocked($sender, (int) $recipient->getKey());

        if (!$this->visibility->canRequestConnection($sender, $recipientProfile)) {
            throw ValidationException::withMessages([
                'profile' => 'This member is not accepting connection requests right now.',
            ]);
        }

        if ($senderProfile !== null) {
            abort_unless((int) $senderProfile->user_id === (int) $sender->getKey(), 403);
        }

        $senderProfile ??= $sender->defaultProfile();
        if (!$senderProfile) {
            throw ValidationException::withMessages([
                'profile' => 'Create a profile before sending connection requests.',
            ]);
        }

        if ($recipientProfile->connection_request_policy === 'matching'
            && ! $this->matchmaking->profilesHaveMatchingSignals($senderProfile, $recipientProfile)) {
            throw ValidationException::withMessages([
                'profile' => 'This member only accepts requests that match their Seeking or Offering.',
            ]);
        }

        $pairKey = Connection::pairKey((int) $sender->getKey(), (int) $recipient->getKey());
        $existing = $this->findPair($sender, $recipient, $pairKey);

        if ($existing && $existing->status === Connection::STATUS_APPROVED) {
            throw ValidationException::withMessages(['profile' => 'You are already connected with this member.']);
        }

        if ($existing && $existing->status === Connection::STATUS_PENDING) {
            throw ValidationException::withMessages(['profile' => 'A connection request is already pending.']);
        }

        if (
            $existing
            && $existing->status === Connection::STATUS_DECLINED
            && (int) $existing->guest_user_id === (int) $sender->getKey()
        ) {
            $cooldownDays = (int) config('roicard.connections.declined_cooldown_days', 30);
            $canRetryAt = $existing->responded_at?->copy()->addDays($cooldownDays);
            if ($canRetryAt && $canRetryAt->isFuture()) {
                throw ValidationException::withMessages([
                    'profile' => 'This member declined a recent request. You can try again after '
                        . $canRetryAt->toFormattedDateString() . '.',
                ]);
            }
        }

        $this->assertRequestLimits($sender);

        return DB::transaction(function () use (
            $sender,
            $recipient,
            $senderProfile,
            $recipientProfile,
            $existing,
            $pairKey,
            $note,
            $matchReason,
            $reasonCodes
        ) {
            $payload = [
                'member_id' => $recipient->id,
                'guest_user_id' => $sender->id,
                'guest_name' => trim($sender->first_name . ' ' . $sender->last_name) ?: $sender->email,
                'guest_email' => $sender->email,
                'guest_org' => $senderProfile->organisation,
                'status' => Connection::STATUS_PENDING,
                'request_type' => Connection::TYPE_MEMBER,
                'sender_profile_id' => $senderProfile->id,
                'recipient_profile_id' => $recipientProfile->id,
                'member_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'match_reason' => $matchReason !== null && trim($matchReason) !== '' ? trim($matchReason) : null,
                'match_reason_codes' => $reasonCodes !== [] ? array_values(array_unique($reasonCodes)) : null,
                'pair_key' => $pairKey,
                'responded_at' => null,
                'withdrawn_at' => null,
                'removed_at' => null,
            ];

            if ($existing) {
                $existing->fill($payload)->save();
                $connection = $existing->fresh();
            } else {
                $connection = Connection::create($payload);
            }

            $recipient->notify(new MemberConnectionRequestNotification($connection));
            app(\App\Services\AnalyticsService::class)->record($recipient->id, 'connection_request', [
                'connection_id' => $connection->id,
                'from_member' => true,
            ]);
            Cache::increment('matchmaking:version');

            return $connection;
        });
    }

    public function accept(User $recipient, Connection $connection): Connection
    {
        $this->assertRecipient($recipient, $connection);

        if ($connection->status !== Connection::STATUS_PENDING) {
            throw ValidationException::withMessages(['connection' => 'This request is no longer pending.']);
        }

        $this->blocks->assertNotBlocked($recipient, (int) $connection->guest_user_id);

        return DB::transaction(function () use ($recipient, $connection) {
            $connection->forceFill([
                'status' => Connection::STATUS_APPROVED,
                'responded_at' => now(),
            ])->save();

            $connection->guestUser?->notify(new MemberConnectionAcceptedNotification($connection));
            app(\App\Services\AnalyticsService::class)->record((int) $connection->guest_user_id, 'connection_accepted', [
                'connection_id' => $connection->id,
            ]);
            Cache::increment('matchmaking:version');

            return $connection->fresh();
        });
    }

    public function decline(User $recipient, Connection $connection): Connection
    {
        $this->assertRecipient($recipient, $connection);

        if ($connection->status !== Connection::STATUS_PENDING) {
            throw ValidationException::withMessages(['connection' => 'This request is no longer pending.']);
        }

        $connection->forceFill([
            'status' => Connection::STATUS_DECLINED,
            'responded_at' => now(),
        ])->save();

        // Product rule: never notify the sender that they were declined.
        Cache::increment('matchmaking:version');

        return $connection->fresh();
    }

    public function withdraw(User $sender, Connection $connection): Connection
    {
        abort_unless((int) $connection->guest_user_id === (int) $sender->getKey(), 403);
        $this->assertMemberRequest($connection);

        if ($connection->status !== Connection::STATUS_PENDING) {
            throw ValidationException::withMessages(['connection' => 'Only pending requests can be withdrawn.']);
        }

        $connection->forceFill([
            'status' => Connection::STATUS_WITHDRAWN,
            'withdrawn_at' => now(),
        ])->save();

        Cache::increment('matchmaking:version');

        return $connection->fresh();
    }

    public function remove(User $actor, Connection $connection): Connection
    {
        abort_unless(
            in_array((int) $actor->getKey(), [(int) $connection->member_id, (int) $connection->guest_user_id], true),
            403
        );

        if ($connection->status !== Connection::STATUS_APPROVED) {
            throw ValidationException::withMessages(['connection' => 'Only accepted connections can be removed.']);
        }

        $connection->forceFill([
            'status' => Connection::STATUS_REMOVED,
            'removed_at' => now(),
        ])->save();

        Cache::increment('matchmaking:version');

        return $connection->fresh();
    }

    protected function findPair(User $sender, User $recipient, string $pairKey): ?Connection
    {
        return Connection::query()
            ->where(function ($query) use ($sender, $recipient, $pairKey) {
                $query->where('pair_key', $pairKey)
                    ->orWhere(function ($pair) use ($sender, $recipient) {
                        $pair->where('member_id', $recipient->id)->where('guest_user_id', $sender->id);
                    })
                    ->orWhere(function ($pair) use ($sender, $recipient) {
                        $pair->where('member_id', $sender->id)->where('guest_user_id', $recipient->id);
                    });
            })
            ->orderByDesc('id')
            ->first();
    }

    protected function assertRequestLimits(User $sender): void
    {
        $todayCount = Connection::query()
            ->where('request_type', Connection::TYPE_MEMBER)
            ->where('guest_user_id', $sender->id)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        $dailyLimit = $this->entitlements->limit($sender, PlanLimitKey::CONNECTION_REQUESTS_PER_DAY);
        $newAccountDays = config('roicard.connections.new_account_days');
        $newAccountLimit = config('roicard.connections.new_account_daily_limit');

        if ($newAccountDays !== null && $newAccountDays !== '' && $newAccountLimit !== null && $newAccountLimit !== '') {
            $isNewAccount = $sender->created_at !== null
                && $sender->created_at->diffInDays(now()) < (int) $newAccountDays;
            $isVerified = $sender->email_verified_at !== null;
            $hasPublishedProfile = $sender->profiles()->where('is_published', true)->exists();

            // The reduced limit applies to new accounts until the member has
            // both verified email and published a profile. Once established,
            // the normal plan limits apply.
            if ($isNewAccount && (! $isVerified || ! $hasPublishedProfile)) {
                $newLimit = max(0, (int) $newAccountLimit);
                $dailyLimit = $dailyLimit === null ? $newLimit : min($dailyLimit, $newLimit);
            }
        }

        if ($dailyLimit !== null && $todayCount >= $dailyLimit) {
            throw new EntitlementExceededException(
                PlanLimitKey::CONNECTION_REQUESTS_PER_DAY,
                $dailyLimit,
                $todayCount,
                'Your plan does not allow more connection requests today.',
            );
        }

        $weeklyLimit = config('roicard.connections.weekly_limit');
        if ($weeklyLimit !== null && $weeklyLimit !== '') {
            $weekCount = Connection::query()
                ->where('request_type', Connection::TYPE_MEMBER)
                ->where('guest_user_id', $sender->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            $weeklyLimit = max(0, (int) $weeklyLimit);
            if ($weekCount >= $weeklyLimit) {
                throw new EntitlementExceededException(
                    'CONNECTION_REQUESTS_PER_WEEK',
                    $weeklyLimit,
                    $weekCount,
                    'Your plan does not allow more connection requests this week.',
                );
            }
        }
    }

    protected function assertRecipient(User $recipient, Connection $connection): void
    {
        $this->assertMemberRequest($connection);
        abort_unless((int) $connection->member_id === (int) $recipient->getKey(), 403);
    }

    protected function assertMemberRequest(Connection $connection): void
    {
        abort_unless($connection->isMemberRequest(), 404);
    }
}
