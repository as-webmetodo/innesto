<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots\Locale;

use InvalidArgumentException;

/**
 * Free text typed for Visitors, stored once per locale as
 * `{"it": "…", "en": "…"}`. The Default locale's entry is required and the
 * others fall back to it; an empty entry counts as missing.
 */
final class LocalisedText
{
    /** @param array<string, string> $text */
    public static function pick(array $text, string $locale, string $defaultLocale): string
    {
        if (($text[$defaultLocale] ?? '') === '') {
            throw new InvalidArgumentException("Localised text lacks the Default locale $defaultLocale");
        }

        return ($text[$locale] ?? '') !== '' ? $text[$locale] : $text[$defaultLocale];
    }
}
