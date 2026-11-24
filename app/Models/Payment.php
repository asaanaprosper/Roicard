<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const PURPOSE_MEMBERSHIP = 'membership';
    public const PURPOSE_SUBSCRIPTION = 'subscription';
    public const PURPOSE_CARD_ACTIVATION = 'card_activation';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'user_id',
        'amount',
        'currency',
        'method',
        'momo_number',
        'status',
        'purpose',
        'plan_id',
        'subscription_id',
        'smart_card_id',
        'profile_id',
        'provider_reference',
        'provider',
        'provider_event_id',
        'idempotency_key',
        'paid_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function smartCard(): BelongsTo
    {
        return $this->belongsTo(SmartCard::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
