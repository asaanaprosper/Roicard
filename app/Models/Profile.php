<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Profile extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** Bump when the public payload shape/privacy rules change. */
    public const PUBLIC_CACHE_PREFIX = 'public_profile:v2:';

    protected $fillable = [
        'user_id',
        'profile_type',
        'display_name',
        'title',
        'role_description',
        'organisation',
        'whatsapp_phone',
        'phone',
        'date_of_birth',
        'gender',
        'interests',
        'location',
        'bio',
        'seeking',
        'offering',
        'slug',
        'is_live',
        'completion_pct',
        'visibility',
        'is_searchable',
        'is_contactable',
        'connection_request_policy',
        'contact_details_visibility',
        'is_default',
        'is_published',
        'published_at',
        'privacy_confirmed_at',
        'skills',
        'services',
        'contact_email',
        'website_url',
        'contact_visibility',
        'ai_draft',
        'ai_approved_fields',
        'ai_catalog_draft',
    ];

    protected $appends = ['avatar_url', 'logo_url', 'cover_url'];

    protected function casts(): array
    {
        return [
            'is_live' => 'boolean',
            'completion_pct' => 'integer',
            'date_of_birth' => 'date',
            'interests' => 'array',
            'is_searchable' => 'boolean',
            'is_contactable' => 'boolean',
            'is_default' => 'boolean',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'privacy_confirmed_at' => 'datetime',
            'skills' => 'array',
            'services' => 'array',
            'contact_visibility' => 'array',
            'ai_draft' => 'array',
            'ai_approved_fields' => 'array',
            'ai_catalog_draft' => 'array',
        ];
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->versionedMediaUrl('avatar');
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->versionedMediaUrl('logo');
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->versionedMediaUrl('cover');
    }

    /**
     * Version fixed-name media URLs so CDN/browser caches invalidate when the
     * owner replaces an avatar, logo or cover image.
     */
    protected function versionedMediaUrl(string $collection): ?string
    {
        $media = $this->getFirstMedia($collection);

        if (! $media) {
            return null;
        }

        return $media->getUrl() . '?v=' . ($media->updated_at?->timestamp ?? $media->id);
    }

    /**
     * Bust the shared public-profile response cache for this profile. Media
     * and related-record writes (avatar, CV, social links, education, etc.)
     * never touch the profile row itself, so the observer's saved() hook does
     * not fire for them — every such mutation must call this explicitly.
     */
    public function bustPublicCache(): void
    {
        if ($this->slug) {
            Cache::forget(self::PUBLIC_CACHE_PREFIX . $this->slug);
        }
        Cache::forget('public_profiles_sitemap');
    }

    /**
     * Remove avatar/logo/cover/CV files and catalog images from storage and
     * the media table. Spatie media rows are polymorphic, so deleting the
     * profile row does not remove them automatically.
     */
    public function purgeStoredMedia(): void
    {
        $this->catalogItems()
            ->with('media')
            ->get()
            ->each(function (CatalogItem $item) {
                $item->media->each->delete();
            });

        $this->media()
            ->get()
            ->each(fn ($media) => $media->delete());
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function education()
    {
        return $this->hasMany(EducationEntry::class);
    }

    public function experience()
    {
        return $this->hasMany(ExperienceEntry::class);
    }

    public function achievements()
    {
        return $this->hasMany(AchievementEntry::class);
    }

    public function socialLinks()
    {
        return $this->hasMany(SocialLink::class);
    }

    public function interestOptions()
    {
        return $this->belongsToMany(InterestOption::class, 'profile_interest');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_profile');
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function signals()
    {
        return $this->hasMany(ProfileSignal::class);
    }

    public function catalogItems()
    {
        return $this->hasMany(CatalogItem::class);
    }

    public function activeCatalogItems()
    {
        return $this->catalogItems()->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    public function catalogEnquiries()
    {
        return $this->hasMany(CatalogEnquiry::class, 'business_profile_id');
    }

    public function seekingSignals()
    {
        return $this->hasMany(ProfileSignal::class)->where('type', ProfileSignal::TYPE_SEEKING);
    }

    public function offeringSignals()
    {
        return $this->hasMany(ProfileSignal::class)->where('type', ProfileSignal::TYPE_OFFERING);
    }

    public function activeSeekingSignals()
    {
        return $this->seekingSignals()->where('is_active', true);
    }

    public function activeOfferingSignals()
    {
        return $this->offeringSignals()->where('is_active', true);
    }

    public function isBusiness(): bool
    {
        return $this->profile_type === \App\Support\ProfileVisibilityOptions::TYPE_BUSINESS;
    }

    public function isPublished(): bool
    {
        return (bool) $this->is_published;
    }

    public function hasApprovedAiDraft(): bool
    {
        $draft = $this->ai_draft;

        if (!$draft || ($draft['source'] ?? null) !== 'ai') {
            return true;
        }

        $generatedFields = array_filter([
            'headline' => $draft['headline'] ?? null,
            'about' => $draft['about'] ?? null,
            'experience' => $draft['experience'] ?? null,
            'skills' => $draft['skills'] ?? null,
            'services' => $draft['services'] ?? null,
            'seeking' => $draft['seeking'] ?? null,
            'offering' => $draft['offering'] ?? null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        if ($generatedFields === []) {
            return true;
        }

        $approved = $this->ai_approved_fields ?? [];

        foreach (array_keys($generatedFields) as $field) {
            if (!in_array($field, $approved, true)) {
                return false;
            }
        }

        return true;
    }

    public function scopeSearchable($query)
    {
        return $query->where('is_searchable', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('profile_type', $type);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->singleFile();
        $this->addMediaCollection('logo')->singleFile();
        $this->addMediaCollection('cover')->singleFile();
        // CVs are private documents. They are never served from the public
        // media disk; downloads go through the authorized CV endpoint.
        $this->addMediaCollection('cv')->useDisk('local');
    }

    public function recalculateCompletion(): void
    {
        $score = 0;
        $total = 10;

        if ($this->title) $score++;
        if ($this->organisation) $score++;
        if ($this->whatsapp_phone) $score++;
        if ($this->location) $score++;
        if ($this->bio) $score++;
        if ($this->education()->count() > 0) $score++;
        if ($this->experience()->count() > 0) $score++;
        if ($this->achievements()->count() > 0) $score++;
        if ($this->socialLinks()->count() > 0) $score++;
        if ($this->getMedia('cv')->count() > 0 || $this->getMedia('avatar')->count() > 0) $score++;

        $this->completion_pct = (int) round(($score / $total) * 100);
        $this->saveQuietly();
    }
}
