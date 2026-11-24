<?php

namespace App\Support;

/**
 * Canonical profile privacy values.
 *
 * Keeping these in one place means migrations, validation, the visibility
 * service and the admin console cannot drift apart.
 */
final class ProfileVisibilityOptions
{
    public const TYPE_PERSONAL = 'personal';
    public const TYPE_BUSINESS = 'business';

    /** Anyone, including signed-out visitors. */
    public const VISIBILITY_PUBLIC = 'public';
    /** Any authenticated Roicard account. */
    public const VISIBILITY_MEMBERS = 'members';
    /** Viewers who share at least one community with the profile owner. */
    public const VISIBILITY_COMMUNITIES = 'communities';
    /** Viewers with an approved connection to the profile owner. */
    public const VISIBILITY_CONNECTIONS = 'connections';
    /** Owner and platform administrators only. */
    public const VISIBILITY_PRIVATE = 'private';

    /** Contact details for approved connections only. */
    public const CONTACT_CONNECTIONS = 'connections';
    /** Contact details for connections and shared-community members. */
    public const CONTACT_COMMUNITIES = 'communities';
    /** Contact details hidden from everyone except the owner/admin. */
    public const CONTACT_HIDDEN = 'hidden';

    public static function types(): array
    {
        return [self::TYPE_PERSONAL, self::TYPE_BUSINESS];
    }

    public static function visibilities(): array
    {
        return [
            self::VISIBILITY_PUBLIC,
            self::VISIBILITY_MEMBERS,
            self::VISIBILITY_COMMUNITIES,
            self::VISIBILITY_CONNECTIONS,
            self::VISIBILITY_PRIVATE,
        ];
    }

    public static function contactVisibilities(): array
    {
        return [
            self::CONTACT_CONNECTIONS,
            self::CONTACT_COMMUNITIES,
            self::CONTACT_HIDDEN,
        ];
    }

    public static function visibilityLabels(): array
    {
        return [
            self::VISIBILITY_PUBLIC => 'Public',
            self::VISIBILITY_MEMBERS => 'Roicard members',
            self::VISIBILITY_COMMUNITIES => 'My communities',
            self::VISIBILITY_CONNECTIONS => 'Connections only',
            self::VISIBILITY_PRIVATE => 'Private',
        ];
    }

    public static function contactVisibilityLabels(): array
    {
        return [
            self::CONTACT_CONNECTIONS => 'Connections only',
            self::CONTACT_COMMUNITIES => 'Connections and communities',
            self::CONTACT_HIDDEN => 'Hidden',
        ];
    }
}
