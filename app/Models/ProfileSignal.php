<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProfileSignal extends Model
{
    public const TYPE_SEEKING = 'seeking';
    public const TYPE_OFFERING = 'offering';

    protected $fillable = [
        'profile_id',
        'catalog_item_id',
        'type',
        'description',
        'is_active',
        'paused_at',
        'source',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'paused_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public static function types(): array
    {
        return [self::TYPE_SEEKING, self::TYPE_OFFERING];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'profile_signal_category', 'signal_id', 'category_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'profile_signal_tag', 'signal_id', 'tag_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
