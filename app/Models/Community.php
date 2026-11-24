<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Community extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const TYPE_EVENT = 'event';
    public const TYPE_GROUP = 'group';
    public const TYPE_ORGANIZATION = 'organization';

    public const JOIN_OPEN = 'open';
    public const JOIN_REQUEST = 'request';
    public const JOIN_INVITE = 'invite';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    public const VERIFICATION_UNVERIFIED = 'unverified';
    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_VERIFIED = 'verified';
    public const VERIFICATION_REJECTED = 'rejected';
    public const VERIFICATION_SUSPENDED = 'suspended';

    protected $fillable = [
        'owner_user_id',
        'name',
        'slug',
        'description',
        'type',
        'join_method',
        'status',
        'verification_status',
        'organisation_name',
        'industry',
        'website_url',
        'location',
        'services',
        'seeking',
        'offering',
        'member_seat_limit',
        'approved_at',
        'approved_by',
        'rejected_reason',
        'suspended_at',
        'starts_at',
        'ends_at',
        'metadata',
    ];

    protected $appends = ['logo_url', 'cover_url'];

    protected function casts(): array
    {
        return [
            'member_seat_limit' => 'integer',
            'services' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public static function types(): array
    {
        return [self::TYPE_EVENT, self::TYPE_GROUP, self::TYPE_ORGANIZATION];
    }

    public static function joinMethods(): array
    {
        return [self::JOIN_OPEN, self::JOIN_REQUEST, self::JOIN_INVITE];
    }

    public static function statuses(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_ARCHIVED];
    }

    public static function verificationStatuses(): array
    {
        return [
            self::VERIFICATION_UNVERIFIED,
            self::VERIFICATION_PENDING,
            self::VERIFICATION_VERIFIED,
            self::VERIFICATION_REJECTED,
            self::VERIFICATION_SUSPENDED,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommunityMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->active();
    }

    /**
     * Active members whose organization affiliation has been confirmed by the
     * organization and accepted by the member.
     */
    public function affiliatedMembers(): HasMany
    {
        return $this->members()
            ->where('status', CommunityMember::STATUS_ACTIVE)
            ->whereNotNull('affiliation_confirmed_at')
            ->whereNotNull('member_accepted_at');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('logo') ?: null;
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('cover') ?: null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isOrganization(): bool
    {
        return $this->type === self::TYPE_ORGANIZATION;
    }

    public function isVerified(): bool
    {
        return $this->verification_status === self::VERIFICATION_VERIFIED;
    }

    public function isPendingApproval(): bool
    {
        return $this->verification_status === self::VERIFICATION_PENDING;
    }

    public function isSuspended(): bool
    {
        return $this->verification_status === self::VERIFICATION_SUSPENDED;
    }

    /**
     * Only approved organizations with an active record appear in public
     * organization discovery and directories.
     */
    public function isPubliclyVisible(): bool
    {
        return $this->isOrganization()
            && $this->isActive()
            && $this->isVerified();
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeVerifiedOrganizations($query)
    {
        return $query->where('type', self::TYPE_ORGANIZATION)
            ->where('status', self::STATUS_ACTIVE)
            ->where('verification_status', self::VERIFICATION_VERIFIED);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
        $this->addMediaCollection('cover')->singleFile();
    }

    public static function makeSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'community';
        $slug = $base;
        $counter = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
