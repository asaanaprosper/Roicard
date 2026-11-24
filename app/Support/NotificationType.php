<?php

namespace App\Support;

/**
 * Canonical Appendix C notification catalogue.
 *
 * Keep the keys aligned with the `type` value stored in each notification's
 * database payload so preferences, admin settings and analytics agree.
 */
final class NotificationType
{
    public const CONNECTION_REQUEST = 'connection_request';
    public const CONNECTION_ACCEPTED = 'connection_accepted';
    public const CATALOG_ENQUIRY = 'catalog_enquiry';
    public const WEEKLY_RECOMMENDATIONS = 'weekly_recommendations';
    public const REFERRAL_SIGNUP = 'referral_signup';
    public const REFERRAL_QUALIFIED = 'referral_qualified';
    public const REFERRAL_REWARD_CREDITED = 'referral_reward_credited';
    public const REFERRAL_REWARD_PAID = 'referral_reward_paid';
    public const SUBSCRIPTION_ABOUT_TO_LAPSE = 'subscription_about_to_lapse';
    public const SUBSCRIPTION_LAPSED = 'subscription_lapsed';
    public const UNPUBLISHED_PROFILE_REMINDER = 'unpublished_profile_reminder';
    public const REPORT_OUTCOME = 'report_outcome';
    public const ADMIN_SCRAPING = 'admin_alert_scraping';
    public const ADMIN_REFERRAL_REWARD_CAP = 'admin_alert_referral_reward_cap';
    public const ADMIN_AI_BUDGET = 'admin_alert_ai_budget';

    public const CHANNEL_DATABASE = 'database';
    public const CHANNEL_MAIL = 'mail';
    public const CHANNEL_PUSH = 'push';

    /**
     * @return array<string, array{label: string, description: string, channels: list<string>, mandatory: bool, admin: bool}>
     */
    public static function all(): array
    {
        return [
            self::CONNECTION_REQUEST => [
                'label' => 'Connection requests',
                'description' => 'Someone asks to connect with you.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL, self::CHANNEL_PUSH],
                'mandatory' => true,
                'admin' => false,
            ],
            self::CONNECTION_ACCEPTED => [
                'label' => 'Connection accepted',
                'description' => 'A member accepts your connection request.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL, self::CHANNEL_PUSH],
                'mandatory' => true,
                'admin' => false,
            ],
            self::CATALOG_ENQUIRY => [
                'label' => 'Catalog enquiries',
                'description' => 'A visitor sends an enquiry from your business catalog.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL, self::CHANNEL_PUSH],
                'mandatory' => true,
                'admin' => false,
            ],
            self::WEEKLY_RECOMMENDATIONS => [
                'label' => 'Weekly recommendations',
                'description' => 'A weekly digest of members matched to your Seeking and Offering.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL, self::CHANNEL_PUSH],
                'mandatory' => false,
                'admin' => false,
            ],
            self::REFERRAL_SIGNUP => [
                'label' => 'Referral signups',
                'description' => 'Someone registers using your referral link.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL],
                'mandatory' => false,
                'admin' => false,
            ],
            self::REFERRAL_QUALIFIED => [
                'label' => 'Referral qualification',
                'description' => 'A referral reaches the configured qualification trigger.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL],
                'mandatory' => false,
                'admin' => false,
            ],
            self::REFERRAL_REWARD_CREDITED => [
                'label' => 'Reward credited',
                'description' => 'A referral reward becomes available in your ledger.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL],
                'mandatory' => false,
                'admin' => false,
            ],
            self::REFERRAL_REWARD_PAID => [
                'label' => 'Reward paid',
                'description' => 'A referral reward is marked paid.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL],
                'mandatory' => false,
                'admin' => false,
            ],
            self::SUBSCRIPTION_ABOUT_TO_LAPSE => [
                'label' => 'Subscription renewal reminder',
                'description' => 'Your paid plan is approaching its period end.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL, self::CHANNEL_PUSH],
                'mandatory' => false,
                'admin' => false,
            ],
            self::SUBSCRIPTION_LAPSED => [
                'label' => 'Subscription ended',
                'description' => 'Your paid plan has lapsed and the account has moved to Free limits.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL, self::CHANNEL_PUSH],
                'mandatory' => false,
                'admin' => false,
            ],
            self::UNPUBLISHED_PROFILE_REMINDER => [
                'label' => 'Unpublished profile reminder',
                'description' => 'Your profile has been unpublished for the configured reminder delay.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL, self::CHANNEL_PUSH],
                'mandatory' => false,
                'admin' => false,
            ],
            self::REPORT_OUTCOME => [
                'label' => 'Report outcomes',
                'description' => 'An administrator finalises a report you submitted.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL],
                'mandatory' => true,
                'admin' => false,
            ],
            self::ADMIN_SCRAPING => [
                'label' => 'Admin: scraping/abuse alert',
                'description' => 'Unusual search volume crossed the configured threshold.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL],
                'mandatory' => true,
                'admin' => true,
            ],
            self::ADMIN_REFERRAL_REWARD_CAP => [
                'label' => 'Admin: referral reward cap',
                'description' => 'A referral cap or configured reward budget threshold was reached.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL],
                'mandatory' => true,
                'admin' => true,
            ],
            self::ADMIN_AI_BUDGET => [
                'label' => 'Admin: AI budget alert',
                'description' => 'The configured monthly AI cost threshold was reached.',
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL],
                'mandatory' => true,
                'admin' => true,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return list<string>
     */
    public static function channels(string $type): array
    {
        return self::all()[$type]['channels'] ?? [self::CHANNEL_DATABASE, self::CHANNEL_MAIL];
    }

    public static function isMandatory(string $type): bool
    {
        return (bool) (self::all()[$type]['mandatory'] ?? false);
    }

    public static function isAdmin(string $type): bool
    {
        return (bool) (self::all()[$type]['admin'] ?? false);
    }

    /**
     * @return array<string, array{label: string, description: string, channels: list<string>, mandatory: bool, admin: bool}>
     */
    public static function userFacing(): array
    {
        return array_filter(self::all(), fn (array $type) => ! $type['admin']);
    }
}
