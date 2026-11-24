<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class CatalogItem extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const TYPE_SERVICE = 'service';
    public const TYPE_PRODUCT = 'product';

    public const PRICE_FIXED = 'fixed';
    public const PRICE_FROM = 'from';
    public const PRICE_ON_REQUEST = 'on_request';

    public const CTA_ENQUIRE = 'enquire';
    public const CTA_REQUEST_MEETING = 'request_meeting';
    public const CTA_CALL = 'call';

    protected $table = 'business_catalog_items';

    protected $fillable = [
        'profile_id',
        'type',
        'title',
        'description',
        'category_id',
        'price',
        'price_mode',
        'currency',
        'cta_type',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function enquiries(): HasMany
    {
        return $this->hasMany(CatalogEnquiry::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function getImageUrlsAttribute(): array
    {
        return $this->getMedia('images')
            ->map(fn ($media) => $media->getUrl())
            ->values()
            ->all();
    }

    public function priceLabel(): ?string
    {
        if ($this->price_mode === self::PRICE_ON_REQUEST || $this->price === null) {
            return 'On request';
        }

        $amount = number_format((float) $this->price, 2, '.', ',');
        $prefix = $this->price_mode === self::PRICE_FROM ? 'From ' : '';

        return $prefix . $this->currency . ' ' . $amount;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images');
    }
}
