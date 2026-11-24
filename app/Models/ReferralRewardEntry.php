<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralRewardEntry extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_VOIDED = 'voided';
    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'referral_id',
        'referrer_user_id',
        'invitee_user_id',
        'entry_type',
        'status',
        'amount',
        'delta_amount',
        'currency',
        'note',
        'created_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'delta_amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
