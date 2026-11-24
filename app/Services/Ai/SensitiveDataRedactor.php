<?php

namespace App\Services\Ai;

/**
 * Removes contact details from source content before it leaves the server.
 *
 * The specification permits the text needed for drafting but forbids sending
 * email addresses, phone numbers or other contact details to the provider.
 */
class SensitiveDataRedactor
{
    private const REPLACEMENT = '[contact detail removed]';

    public function redact(string $text): string
    {
        // Email addresses.
        $text = (string) preg_replace(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            self::REPLACEMENT,
            $text,
        );

        // Phone-like sequences. Only redact when there are at least seven
        // digits so years, ranges and small numbers survive.
        $text = (string) preg_replace_callback(
            '/(?<!\d)(?:\+?\d[\d\s().\-]{5,}\d)(?!\d)/u',
            function (array $matches): string {
                $candidate = trim($matches[0]);

                // Do not treat a year range (for example 2019 - 2021) as a
                // phone number; dates are useful profile content.
                if (preg_match('/^\d{4}\s*-\s*\d{4}$/', $candidate)) {
                    return $matches[0];
                }

                $digits = preg_replace('/\D/', '', $candidate) ?? '';

                return strlen($digits) >= 7 ? self::REPLACEMENT : $matches[0];
            },
            $text,
        );

        // Mailto/tel link targets that may remain after HTML stripping.
        $text = (string) preg_replace('/\b(?:mailto|tel):\S+/iu', self::REPLACEMENT, $text);

        return $text;
    }
}
