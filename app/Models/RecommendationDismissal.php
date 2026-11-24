<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecommendationDismissal extends Model
{
    protected $fillable = [
        'profile_id',
        'recommended_profile_id',
        'reason',
        'dismissed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function recommendedProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'recommended_profile_id');
    }

    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', now());
    }
}
