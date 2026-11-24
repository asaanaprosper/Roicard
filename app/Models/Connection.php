<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
    public const TYPE_GUEST = 'guest';
    public const TYPE_MEMBER = 'member';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'member_id',
        'guest_user_id',
        'guest_name',
        'guest_email',
        'guest_phone',
        'guest_org',
        'guest_meeting_context',
        'guest_introduction',
        'guest_intent',
        'status',
        'request_type',
        'sender_profile_id',
        'recipient_profile_id',
        'member_note',
        'match_reason',
        'match_reason_codes',
        'pair_key',
        'responded_at',
        'withdrawn_at',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'match_reason_codes' => 'array',
            'responded_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function member()
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function guestUser()
    {
        return $this->belongsTo(User::class, 'guest_user_id');
    }

    public function senderProfile()
    {
        return $this->belongsTo(Profile::class, 'sender_profile_id');
    }

    public function recipientProfile()
    {
        return $this->belongsTo(Profile::class, 'recipient_profile_id');
    }

    public static function pairKey(int $firstUserId, int $secondUserId): string
    {
        return min($firstUserId, $secondUserId) . ':' . max($firstUserId, $secondUserId);
    }

    public function isMemberRequest(): bool
    {
        return $this->request_type === self::TYPE_MEMBER;
    }

    public function isActiveConnection(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_APPROVED], true);
    }

    public function scopeMemberRequests($query)
    {
        return $query->where('request_type', self::TYPE_MEMBER);
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Link previously submitted guest requests to a newly registered user so
     * their Guest Profile record transitions to their real account.
     */
    public static function linkGuestRequestsToUser(User $user): void
    {
        static::whereNull('guest_user_id')
            ->where('guest_email', $user->email)
            ->update(['guest_user_id' => $user->id]);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function approve(): void
    {
        $this->status = 'approved';
        $this->save();
    }

    public function decline(): void
    {
        $this->status = 'declined';
        $this->save();
    }
}
