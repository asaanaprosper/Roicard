<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'registration_ip',
        'google_id',
        'password',
        'status',
        'role',
        'timezone',
        'email_verified_at',
        'email_verification_code',
        'email_verification_code_expires_at',
        'onboarding_completed_at',
        'onboarding_goals',
        'onboarding_goals_dismissed_at',
        'setup_card_dismissed_at',
        'campaign_code',
        'discount_campaign_id',
        'two_factor_secret',
        'two_factor_enabled',
        'deactivated_at',
        'referral_code',
        'referred_by_user_id',
        'referral_attributed_at',
        'referral_attribution_expires_at',
        'referral_signature_enabled',
        'legacy_member',
        'legacy_member_since',
        'privacy_consent_at',
        'privacy_consent_version',
        'notification_preferences',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'email_verification_code',
        'registration_ip',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_code_expires_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'onboarding_goals' => 'array',
            'onboarding_goals_dismissed_at' => 'datetime',
            'setup_card_dismissed_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'suspended_by_admin' => 'boolean',
            'deleted_at' => 'datetime',
            'two_factor_enabled' => 'boolean',
            'referral_attributed_at' => 'datetime',
            'referral_attribution_expires_at' => 'datetime',
            'referral_signature_enabled' => 'boolean',
            'legacy_member' => 'boolean',
            'legacy_member_since' => 'datetime',
            'ai_consent_at' => 'datetime',
            'privacy_consent_at' => 'datetime',
            'notification_preferences' => 'array',
            'password' => 'hashed',
        ];
    }

    /**
     * Generate a 6-digit email verification code with a 30-minute expiry.
     */
    public function generateEmailVerificationCode(): string
    {
        $code = str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->update([
            'email_verification_code' => $code,
            'email_verification_code_expires_at' => now()->addMinutes(30),
        ]);

        return $code;
    }

    /**
     * Check if the given code matches and hasn't expired.
     */
    public function validateEmailVerificationCode(string $code): bool
    {
        if (
            $this->email_verification_code === null ||
            $this->email_verification_code_expires_at === null
        ) {
            return false;
        }

        if (now()->greaterThan($this->email_verification_code_expires_at)) {
            return false;
        }

        return hash_equals($this->email_verification_code, $code);
    }

    /**
     * Clear the verification code after successful use.
     */
    public function clearEmailVerificationCode(): void
    {
        $this->update([
            'email_verification_code' => null,
            'email_verification_code_expires_at' => null,
        ]);
    }

    /**
     * All profiles owned by this account (MVP 2 supports several).
     */
    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    /**
     * Backwards-compatible singular profile used by MVP 1 flows. Resolves the
     * account's default profile, falling back to the oldest profile when no
     * default has been explicitly set yet.
     */
    public function profile()
    {
        return $this->hasOne(Profile::class)
            ->orderByDesc('is_default')
            ->orderBy('id');
    }

    public function defaultProfile(): ?Profile
    {
        return $this->profiles()->orderByDesc('is_default')->orderBy('id')->first();
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->latestOfMany();
    }

    public function referralsMade(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_user_id');
    }

    public function referralReceived()
    {
        return $this->hasOne(Referral::class, 'invitee_user_id');
    }

    public function referralRewardEntries(): HasMany
    {
        return $this->hasMany(ReferralRewardEntry::class, 'referrer_user_id');
    }

    public function pushTokens(): HasMany
    {
        return $this->hasMany(\App\Models\PushToken::class);
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function onboardingTasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class);
    }

    public function blocksMade(): HasMany
    {
        return $this->hasMany(MemberBlock::class, 'blocker_id');
    }

    public function blocksReceived(): HasMany
    {
        return $this->hasMany(MemberBlock::class, 'blocked_id');
    }

    public function reportsMade(): HasMany
    {
        return $this->hasMany(MemberReport::class, 'reporter_id');
    }

    public function reportsReceived(): HasMany
    {
        return $this->hasMany(MemberReport::class, 'reported_user_id');
    }

    public function communityMemberships(): HasMany
    {
        return $this->hasMany(CommunityMember::class);
    }

    public function communities(): BelongsToMany
    {
        return $this->belongsToMany(Community::class, 'community_members')
            ->withPivot([
                'role', 'status', 'join_method', 'joined_at', 'starts_at', 'ends_at',
                'affiliation_confirmed_at', 'member_accepted_at',
            ])
            ->withTimestamps();
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Community::class, 'community_members')
            ->where('communities.type', Community::TYPE_ORGANIZATION)
            ->withPivot([
                'role', 'status', 'join_method', 'joined_at', 'starts_at', 'ends_at',
                'affiliation_confirmed_at', 'member_accepted_at',
            ])
            ->withTimestamps();
    }

    public function publicOrganizations(): BelongsToMany
    {
        return $this->organizations()
            ->wherePivot('status', CommunityMember::STATUS_ACTIVE)
            ->wherePivotNotNull('affiliation_confirmed_at')
            ->wherePivotNotNull('member_accepted_at')
            ->where('communities.status', Community::STATUS_ACTIVE)
            ->where('communities.verification_status', Community::VERIFICATION_VERIFIED);
    }

    public function auditActions(): HasMany
    {
        return $this->hasMany(AdminActionLog::class, 'actor_id');
    }

    public function discountCampaign()
    {
        return $this->belongsTo(DiscountCampaign::class, 'discount_campaign_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function smartCard()
    {
        return $this->hasOne(SmartCard::class);
    }

    public function connections()
    {
        return $this->hasMany(Connection::class, 'member_id');
    }

    public function analyticsEvents()
    {
        return $this->hasMany(AnalyticsEvent::class, 'member_id');
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function meetingTypes(): HasMany
    {
        return $this->hasMany(MeetingType::class);
    }

    public function hostedBookings(): HasMany
    {
        return $this->hasMany(MeetingBooking::class, 'host_user_id');
    }

    public function blockedDates(): HasMany
    {
        return $this->hasMany(MeetingBlockedDate::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function sendEmailVerificationNotification(): void
    {
        $code = $this->generateEmailVerificationCode();
        $this->notify(new \App\Notifications\VerifyEmail($code));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * When this draft account will be closed by drafts:expiration-reminders,
     * or null for non-draft accounts. The command closes cohorts whose
     * created_at date is 8 days back, i.e. at/after midnight following the
     * 7-day activation window — matching the "after midnight" email copy.
     */
    public function draftClosesAt(): ?\Carbon\CarbonInterface
    {
        if ($this->status !== 'draft' || !$this->created_at) {
            return null;
        }

        return $this->created_at->startOfDay()->addDays(8);
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * True when an administrator blocked this account. Admin suspensions can
     * only be lifted from the console; self-service reactivation is reserved
     * for accounts the member deactivated themselves.
     */
    public function isSuspendedByAdmin(): bool
    {
        return (bool) $this->suspended_by_admin;
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
