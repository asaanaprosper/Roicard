<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignatureCard extends Model
{
    public const FORMAT_SQUARE = 'square';
    public const FORMAT_STORY = 'story';
    public const FORMAT_EMAIL_BANNER = 'email_banner';

    public const TEMPLATE_CLASSIC = 'classic';

    protected $fillable = [
        'profile_id',
        'format',
        'template',
        'file_path',
        'hash',
        'alt_text',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public static function formats(): array
    {
        return [self::FORMAT_SQUARE, self::FORMAT_STORY, self::FORMAT_EMAIL_BANNER];
    }

    public static function dimensions(string $format): array
    {
        return match ($format) {
            self::FORMAT_STORY => [1080, 1920],
            self::FORMAT_EMAIL_BANNER => [600, 200],
            default => [1080, 1080],
        };
    }

    public static function templates(): array
    {
        return [self::TEMPLATE_CLASSIC];
    }
}
