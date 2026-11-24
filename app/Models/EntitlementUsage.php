<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntitlementUsage extends Model
{
    protected $table = 'entitlement_usage';

    protected $fillable = [
        'user_id',
        'limit_key',
        'period_key',
        'used',
    ];

    protected function casts(): array
    {
        return [
            'used' => 'integer',
        ];
    }
}
