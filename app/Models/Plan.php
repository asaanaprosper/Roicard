<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_active',
        'is_default',
        'sort_order',
        'price',
        'currency',
        'billing_interval',
        'verification_mark',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
            'price' => 'decimal:2',
            'verification_mark' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isPaid(): bool
    {
        return $this->price !== null && (float) $this->price > 0;
    }

    public function hasConfiguredPrice(): bool
    {
        return $this->price !== null && $this->currency !== null && $this->billing_interval !== null;
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, int|null>
     */
    public function entitlementMap(): array
    {
        return $this->entitlements
            ->pluck('limit_value', 'limit_key')
            ->all();
    }
}
