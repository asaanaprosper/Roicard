<?php

namespace App\Support;

/**
 * The complete set of plan/entitlement limit keys supported by MVP 2.
 *
 * Values are configured in config/roicard.php (or plan_entitlements rows),
 * never hardcoded in feature controllers.
 */
final class PlanLimitKey
{
    public const ACTIVE_SEEKING_ITEMS = 'ACTIVE_SEEKING_ITEMS';
    public const ACTIVE_OFFERING_ITEMS = 'ACTIVE_OFFERING_ITEMS';
    public const PROFILE_COUNT = 'PROFILE_COUNT';
    public const CATALOG_ITEMS = 'CATALOG_ITEMS';
    public const SEARCHES_PER_DAY = 'SEARCHES_PER_DAY';
    public const CONNECTION_REQUESTS_PER_DAY = 'CONNECTION_REQUESTS_PER_DAY';
    public const RECOMMENDATIONS_SHOWN = 'RECOMMENDATIONS_SHOWN';
    public const AI_CALLS_PER_DAY = 'AI_CALLS_PER_DAY';
    public const VERIFICATION_MARK = 'VERIFICATION_MARK';
    public const COMMUNITIES_JOINED = 'COMMUNITIES_JOINED';
    public const ORG_MEMBER_SEATS = 'ORG_MEMBER_SEATS';
    public const SIGNATURE_CARD_TEMPLATES = 'SIGNATURE_CARD_TEMPLATES';
    public const REFERRAL_REWARDS = 'REFERRAL_REWARDS';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ACTIVE_SEEKING_ITEMS,
            self::ACTIVE_OFFERING_ITEMS,
            self::PROFILE_COUNT,
            self::CATALOG_ITEMS,
            self::SEARCHES_PER_DAY,
            self::CONNECTION_REQUESTS_PER_DAY,
            self::RECOMMENDATIONS_SHOWN,
            self::AI_CALLS_PER_DAY,
            self::VERIFICATION_MARK,
            self::COMMUNITIES_JOINED,
            self::ORG_MEMBER_SEATS,
            self::SIGNATURE_CARD_TEMPLATES,
            self::REFERRAL_REWARDS,
        ];
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::all(), true);
    }
}
