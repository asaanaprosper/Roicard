<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecommendationEvent extends Model
{
    protected $fillable = [
        'profile_id',
        'recommended_profile_id',
        'event_type',
        'score',
        'reason_codes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'reason_codes' => 'array',
            'metadata' => 'array',
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
}
