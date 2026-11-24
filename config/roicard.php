<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Membership activation fee (GHS)
    |--------------------------------------------------------------------------
    |
    | The one-time activation amount charged for full membership. The server is
    | the single source of truth for pricing — the client never supplies the
    | amount, so this value (not any request body) determines what is charged.
    |
    */

    'activation_fee' => (float) env('ACTIVATION_FEE_GHS', 350),

    /*
    |--------------------------------------------------------------------------
    | NLF campaign pricing + code
    |--------------------------------------------------------------------------
    |
    | During the NLF mass-onboarding, members who register with the campaign
    | code pay a discounted (or free) activation fee. NLF_CAMPAIGN_CODE is the
    | shared code announced at the event; entering it at registration stamps
    | the discount flag on the account, and NLF_ACTIVATION_FEE_GHS is the fee
    | those members are charged at checkout. Both are set server-side.
    |
    */

    'nlf' => [
        // 50% off the standard GHS 350 activation fee for program members.
        'campaign_code' => env('NLF_CAMPAIGN_CODE', 'NLF2026'),
        'activation_fee' => (float) env('NLF_ACTIVATION_FEE_GHS', 175),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plans and entitlements (MVP 2)
    |--------------------------------------------------------------------------
    |
    | These are fallback values used when a member has no assigned plan, or
    | when a plan does not define a particular key. A NULL value means
    | unlimited. Zero disables the feature.
    |
    | IMPORTANT: pricing and final entitlement values are subject to founder
    | confirmation. Keep the defaults NULL (unlimited) until approved; do not
    | encode plan restrictions directly in feature controllers.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Matchmaking and network search (MVP 2 Phase 3)
    |--------------------------------------------------------------------------
    |
    | These are explainable, configurable ranking weights and safety windows,
    | not plan limits. Plan limits continue to live under plans.limits.
    |
    */

    'matchmaking' => [
        'weights' => [
            'offering_meets_seeking' => (float) env('MATCH_WEIGHT_OFFERING_MEETS_SEEKING', 40),
            'seeking_meets_offering' => (float) env('MATCH_WEIGHT_SEEKING_MEETS_OFFERING', 40),
            'mutual_bonus' => (float) env('MATCH_WEIGHT_MUTUAL_BONUS', 15),
            'tag_overlap' => (float) env('MATCH_WEIGHT_TAG_OVERLAP', 10),
            'same_organization' => (float) env('MATCH_WEIGHT_SAME_ORGANIZATION', 5),
            'same_location' => (float) env('MATCH_WEIGHT_SAME_LOCATION', 5),
            'profile_completeness' => (float) env('MATCH_WEIGHT_PROFILE_COMPLETENESS', 8),
        ],
        'minimum_score' => (float) env('MATCH_MINIMUM_SCORE', 25),
        'diversity_cap_per_organization' => (int) env('MATCH_DIVERSITY_CAP_PER_ORGANIZATION', 2),
        'default_recommendation_limit' => (int) env('MATCH_DEFAULT_RECOMMENDATION_LIMIT', 20),
        'dismiss_days' => (int) env('MATCH_DISMISS_DAYS', 90),
        'not_relevant_penalty' => (float) env('MATCH_NOT_RELEVANT_PENALTY', 10),
        'cache_ttl_seconds' => (int) env('MATCH_CACHE_TTL_SECONDS', 600),
    ],

    'connections' => [
        // NULL means no extra limit beyond CONNECTION_REQUESTS_PER_DAY.
        'weekly_limit' => env('CONNECTION_REQUESTS_PER_WEEK'),
        'new_account_days' => env('CONNECTION_NEW_ACCOUNT_DAYS'),
        'new_account_daily_limit' => env('CONNECTION_NEW_ACCOUNT_DAILY_LIMIT'),
        'declined_cooldown_days' => (int) env('CONNECTION_DECLINED_COOLDOWN_DAYS', 30),
    ],

    'search' => [
        'max_candidates' => (int) env('SEARCH_MAX_CANDIDATES', 200),
        'per_page_max' => (int) env('SEARCH_PER_PAGE_MAX', 50),
        // Blank disables the admin warning. Set a founder-approved value to
        // alert when one member performs too many searches in an hour.
        'abuse_alert_threshold' => env('SEARCH_ABUSE_ALERT_THRESHOLD'),
    ],

    'catalog' => [
        'max_images_per_item' => (int) env('CATALOG_MAX_IMAGES_PER_ITEM', 5),
        'max_image_kb' => (int) env('CATALOG_MAX_IMAGE_KB', 5120),
        'enquiries_per_minute' => (int) env('CATALOG_ENQUIRIES_PER_MINUTE', 5),
        'default_currency' => env('CATALOG_DEFAULT_CURRENCY', 'GHS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI features and guardrails (MVP 2 Section 7)
    |--------------------------------------------------------------------------
    |
    | Gemini is the only provider. The API key lives in config/services.php.
    | Behaviour, consent, input caps, timeouts and the monthly cost alert live
    | here so feature code never hardcodes them. Cost prices/thresholds remain
    | founder/deployment decisions: leave them blank to disable cost estimates.
    |
    */

    'ai' => [
        'provider' => 'gemini',
        'consent_version' => env('AI_CONSENT_VERSION', '2026-11'),
        'consent_text' => env(
            'AI_CONSENT_TEXT',
            'I agree that Roicard may send the text, CV content or website content I provide to Google Gemini for the limited purpose of drafting profile or catalog content. I understand AI output is a proposal that I must review and approve, and that I can edit everything manually without AI.'
        ),
        'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 20),
        'requests_per_minute' => (int) env('AI_REQUESTS_PER_MINUTE', 10),
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 2048),
        'max_source_text_chars' => (int) env('AI_MAX_SOURCE_TEXT_CHARS', 12000),
        'max_cv_kb' => (int) env('AI_MAX_CV_KB', 10240),
        'max_website_bytes' => (int) env('AI_MAX_WEBSITE_BYTES', 400000),
        'max_website_chars' => (int) env('AI_MAX_WEBSITE_CHARS', 12000),
        'max_catalog_items' => (int) env('AI_MAX_CATALOG_ITEMS', 10),
        // Blank disables the estimate/alert rather than inventing a budget.
        'monthly_cost_alert_usd' => env('AI_MONTHLY_COST_ALERT_USD'),
        'input_price_per_million_usd' => env('AI_INPUT_PRICE_PER_MILLION_USD'),
        'output_price_per_million_usd' => env('AI_OUTPUT_PRICE_PER_MILLION_USD'),
    ],

    'organizations' => [
        'max_invitations_per_bulk' => (int) env('ORG_MAX_BULK_INVITATIONS', 50),
        'invitation_expiry_days' => (int) env('ORG_INVITATION_EXPIRY_DAYS', 30),
    ],

    'billing' => [
        // Mock billing is only for local/testing. Never enable in production.
        'allow_mock' => (bool) env('BILLING_ALLOW_MOCK', false),
        // Optional grace period after period end before returning to Free.
        'grace_days' => (int) env('BILLING_GRACE_DAYS', 0),
    ],

    'privacy' => [
        // Version stamped on the sign-up profile-processing consent. The
        // founder/legal owner must approve the wording and policy URL.
        'consent_version' => env('PRIVACY_CONSENT_VERSION', '2026-11'),
        'policy_url' => env('PRIVACY_POLICY_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications (Appendix C)
    |--------------------------------------------------------------------------
    |
    | The daily cap applies to non-mandatory member notifications. Leave it
    | blank until a founder-approved value exists. The two reminder delays are
    | also deployment decisions; blank disables that reminder rather than
    | inventing a product rule. Weekly recommendations run weekly.
    |
    */

    'notifications' => [
        'daily_cap' => env('NOTIFICATION_DAILY_CAP'),
        'unpublished_profile_reminder_days' => env('UNPUBLISHED_PROFILE_REMINDER_DAYS'),
        'subscription_lapse_warning_days' => env('SUBSCRIPTION_LAPSE_WARNING_DAYS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data retention
    |--------------------------------------------------------------------------
    |
    | Blank/NULL keeps records indefinitely. Founder/legal approval is required
    | before any of these windows are set. Account deletion uses
    | app.data_retention_days and the daily users:purge command.
    |
    */

    'retention' => [
        'recommendation_event_days' => env('RETENTION_RECOMMENDATION_EVENT_DAYS'),
        'admin_action_log_days' => env('RETENTION_ADMIN_ACTION_LOG_DAYS'),
        'ai_usage_log_days' => env('RETENTION_AI_USAGE_LOG_DAYS'),
    ],

    // Founder-specified one-time physical card activation fee.
    'card_activation_fee' => (float) env('CARD_ACTIVATION_FEE_GHS', 75),

    /*
    |--------------------------------------------------------------------------
    | Referrals
    |--------------------------------------------------------------------------
    |
    | Qualification and rewards are disabled until the founder configures a
    | qualification trigger and reward amount. No cash payout is attempted.
    | Supported qualification triggers: membership_active, subscription_active,
    | card_activated. Configure exactly one in REFERRAL_QUALIFICATION_TRIGGER.
    |
    */

    'referrals' => [
        'enabled' => (bool) env('REFERRALS_ENABLED', false),
        'attribution_days' => (int) env('REFERRAL_ATTRIBUTION_DAYS', 30),
        'qualification_trigger' => env('REFERRAL_QUALIFICATION_TRIGGER'),
        'reward_amount' => env('REFERRAL_REWARD_AMOUNT'),
        'currency' => env('REFERRAL_CURRENCY', 'GHS'),
        'monthly_reward_cap' => env('REFERRAL_MONTHLY_REWARD_CAP'),
        'total_budget' => env('REFERRAL_TOTAL_BUDGET'),
        'budget_alert_threshold' => env('REFERRAL_BUDGET_ALERT_THRESHOLD'),
        'refund_window_days' => env('REFERRAL_REFUND_WINDOW_DAYS'),
        'payout_enabled' => (bool) env('REFERRAL_PAYOUT_ENABLED', false),
        'same_ip_block' => (bool) env('REFERRAL_SAME_IP_BLOCK', false),
    ],

    'signature_cards' => [
        'cache_minutes' => (int) env('SIGNATURE_CARD_CACHE_MINUTES', 5),
        'font_path' => env('SIGNATURE_CARD_FONT_PATH'),
        'colors' => [
            'primary' => '#E63946',
            'accent' => '#FF8C42',
            'background' => '#111214',
            'text' => '#FFFFFF',
            'muted' => '#D6D3D1',
        ],
    ],

    'plans' => [
        'default_key' => env('DEFAULT_PLAN_KEY', 'free'),

        'limits' => [
            \App\Support\PlanLimitKey::ACTIVE_SEEKING_ITEMS => env('PLAN_LIMIT_ACTIVE_SEEKING_ITEMS'),
            \App\Support\PlanLimitKey::ACTIVE_OFFERING_ITEMS => env('PLAN_LIMIT_ACTIVE_OFFERING_ITEMS'),
            \App\Support\PlanLimitKey::PROFILE_COUNT => env('PLAN_LIMIT_PROFILE_COUNT'),
            \App\Support\PlanLimitKey::CATALOG_ITEMS => env('PLAN_LIMIT_CATALOG_ITEMS'),
            \App\Support\PlanLimitKey::SEARCHES_PER_DAY => env('PLAN_LIMIT_SEARCHES_PER_DAY'),
            \App\Support\PlanLimitKey::CONNECTION_REQUESTS_PER_DAY => env('PLAN_LIMIT_CONNECTION_REQUESTS_PER_DAY'),
            \App\Support\PlanLimitKey::RECOMMENDATIONS_SHOWN => env('PLAN_LIMIT_RECOMMENDATIONS_SHOWN'),
            \App\Support\PlanLimitKey::AI_CALLS_PER_DAY => env('PLAN_LIMIT_AI_CALLS_PER_DAY'),
            \App\Support\PlanLimitKey::VERIFICATION_MARK => env('PLAN_LIMIT_VERIFICATION_MARK'),
            \App\Support\PlanLimitKey::COMMUNITIES_JOINED => env('PLAN_LIMIT_COMMUNITIES_JOINED'),
            \App\Support\PlanLimitKey::ORG_MEMBER_SEATS => env('PLAN_LIMIT_ORG_MEMBER_SEATS'),
            \App\Support\PlanLimitKey::SIGNATURE_CARD_TEMPLATES => env('PLAN_LIMIT_SIGNATURE_CARD_TEMPLATES'),
            \App\Support\PlanLimitKey::REFERRAL_REWARDS => env('PLAN_LIMIT_REFERRAL_REWARDS'),
        ],
    ],

];