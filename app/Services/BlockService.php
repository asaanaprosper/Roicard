<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\MemberBlock;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BlockService
{
    /**
     * @return array<int, int>
     */
    public function blockedUserIds(User $user): array
    {
        $made = MemberBlock::where('blocker_id', $user->id)->pluck('blocked_id')->all();
        $received = MemberBlock::where('blocked_id', $user->id)->pluck('blocker_id')->all();

        return array_values(array_unique(array_map('intval', array_merge($made, $received))));
    }

    public function isBlockedBetween(User $first, int $secondUserId): bool
    {
        if ((int) $first->getKey() === $secondUserId) {
            return false;
        }

        return MemberBlock::query()
            ->where(function ($query) use ($first, $secondUserId) {
                $query->where('blocker_id', $first->getKey())->where('blocked_id', $secondUserId);
            })
            ->orWhere(function ($query) use ($first, $secondUserId) {
                $query->where('blocker_id', $secondUserId)->where('blocked_id', $first->getKey());
            })
            ->exists();
    }

    public function assertNotBlocked(User $actor, int $otherUserId): void
    {
        if ($this->isBlockedBetween($actor, $otherUserId)) {
            throw ValidationException::withMessages([
                'member' => 'This member is not available for this action.',
            ]);
        }
    }

    public function block(User $blocker, int $blockedUserId, ?string $reason = null): MemberBlock
    {
        if ((int) $blocker->getKey() === $blockedUserId) {
            throw ValidationException::withMessages(['member' => 'You cannot block yourself.']);
        }

        $blocked = User::findOrFail($blockedUserId);

        return DB::transaction(function () use ($blocker, $blocked, $reason) {
            $block = MemberBlock::firstOrCreate(
                ['blocker_id' => $blocker->id, 'blocked_id' => $blocked->id],
                ['reason' => $reason],
            );

            // Pending requests must stop immediately. Accepted connections are
            // retained but hidden and contact details are blocked by the
            // visibility service.
            Connection::query()
                ->where(function ($query) use ($blocker, $blocked) {
                    $query->where(function ($pair) use ($blocker, $blocked) {
                        $pair->where('member_id', $blocker->id)->where('guest_user_id', $blocked->id);
                    })->orWhere(function ($pair) use ($blocker, $blocked) {
                        $pair->where('member_id', $blocked->id)->where('guest_user_id', $blocker->id);
                    });
                })
                ->where('status', Connection::STATUS_PENDING)
                ->update([
                    'status' => Connection::STATUS_WITHDRAWN,
                    'withdrawn_at' => now(),
                ]);

            Cache::increment('matchmaking:version');

            return $block->fresh();
        });
    }

    public function unblock(User $blocker, int $blockedUserId): bool
    {
        $deleted = MemberBlock::where('blocker_id', $blocker->id)
            ->where('blocked_id', $blockedUserId)
            ->delete();

        if ($deleted) {
            Cache::increment('matchmaking:version');
        }

        return $deleted > 0;
    }

    /**
     * @return array<int, int>
     */
    public function blockedByUser(User $user): array
    {
        return MemberBlock::where('blocker_id', $user->id)
            ->pluck('blocked_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
