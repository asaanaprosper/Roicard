<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecommendationFeedback extends Model
{
    protected $fillable = [
        'profile_id',
        'recommended_profile_id',
        'category_pair_key',
        'penalty',
    ];

    protected function casts(): array
    {
        return [
            'penalty' => 'float',
        ];
    }
}
