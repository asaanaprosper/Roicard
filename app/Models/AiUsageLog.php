<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Token/outcome records for AI calls.
 *
 * Prompt and completion text are deliberately never stored here or written to
 * application logs. Only the metadata required for cost monitoring, abuse
 * review and the specification's logging requirement is retained.
 */
class AiUsageLog extends Model
{
    protected $fillable = [
        'user_id',
        'feature',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'outcome',
        'error_code',
        'estimated_cost_usd',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'estimated_cost_usd' => 'decimal:6',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
