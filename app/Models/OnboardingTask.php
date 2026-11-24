<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingTask extends Model
{
    public const STATE_TODO = 'todo';
    public const STATE_DONE = 'done';
    public const STATE_SKIPPED = 'skipped';

    protected $fillable = [
        'user_id',
        'task_key',
        'state',
        'completed_at',
        'skipped_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'skipped_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public static function states(): array
    {
        return [self::STATE_TODO, self::STATE_DONE, self::STATE_SKIPPED];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDone(): bool
    {
        return $this->state === self::STATE_DONE;
    }

    public function isSkipped(): bool
    {
        return $this->state === self::STATE_SKIPPED;
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
