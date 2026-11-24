<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityMember extends Model
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_MEMBER = 'member';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INVITED = 'invited';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_LEFT = 'left';

    public const JOIN_OPEN = 'open';
    public const JOIN_REQUEST = 'request';
    public const JOIN_INVITE = 'invite';
    public const JOIN_ADMIN = 'admin';

    protected $fillable = [
        'community_id',
        'user_id',
        'role',
        'status',
        'join_method',
        'discoverable',
        'joined_at',
        'affiliation_confirmed_at',
        'member_accepted_at',
        'affiliation_confirmed_by',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
            'join_method' => 'string',
            'discoverable' => 'boolean',
            'joined_at' => 'datetime',
            'affiliation_confirmed_at' => 'datetime',
            'member_accepted_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public static function roles(): array
    {
        return [self::ROLE_OWNER, self::ROLE_MANAGER, self::ROLE_MODERATOR, self::ROLE_MEMBER];
    }

    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_INVITED, self::STATUS_DECLINED, self::STATUS_LEFT];
    }

    public static function joinMethods(): array
    {
        return [self::JOIN_OPEN, self::JOIN_REQUEST, self::JOIN_INVITE, self::JOIN_ADMIN];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affiliation_confirmed_by');
    }

    /**
     * An affiliation may only be shown publicly after both sides agree.
     */
    public function isPubliclyAffiliated(): bool
    {
        return $this->isActive()
            && $this->affiliation_confirmed_at !== null
            && $this->member_accepted_at !== null;
    }

    public function scopePubliclyAffiliated($query)
    {
        return $query->active()
            ->whereNotNull('affiliation_confirmed_at')
            ->whereNotNull('member_accepted_at');
    }

    public function isActive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $now = now();

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Active status plus the membership's start/end window.
     */
    public function scopeActive($query)
    {
        $now = now();

        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function ($builder) use ($now) {
                $builder->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($builder) use ($now) {
                $builder->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            });
    }
}
