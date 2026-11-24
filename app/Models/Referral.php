<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Referral extends Model
{
    public const STATUS_ATTRIBUTED = 'attributed';
    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_PAID = 'paid';
    public const STATUS_VOIDED = 'voided';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CAPPED = 'capped';

    protected $fillable = [
        'referrer_user_id',
        'invitee_user_id',
        'referral_code',
        'status',
        'source',
        'invitee_email',
        'attribution_ip',
        'risk_flags',
        'attributed_at',
        'expires_at',
        'qualified_at',
        'voided_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'risk_flags' => 'array',
            'attributed_at' => 'datetime',
            'expires_at' => 'datetime',
            'qualified_at' => 'datetime',
            'voided_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_user_id');
    }

    public function rewardEntries(): HasMany
    {
        return $this->hasMany(ReferralRewardEntry::class);
    }

    public function isWithinAttributionWindow(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
