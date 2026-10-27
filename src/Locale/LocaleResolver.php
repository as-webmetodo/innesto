<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots\Locale;

/**
 * Picks the locale of a response. Public output never reads Accept-Language,
 * so one URL always gives one output; Roots' own areas follow the browser.
 */
final class LocaleResolver
{
    /** The locales Roots ships catalogues for. */
    public const SHIPPED = ['it', 'en', 'en-GB'];

    /**
     * The explicit $lang when it is enabled, otherwise the Default locale;
     * `fellBack` tells the caller to log the refused request.
     *
     * @param list<string> $enabled
     * @return array{locale: string, fellBack: bool}
     */
    public static function forPublic(?string $lang, array $enabled, string $default): array
    {
        if ($lang === null || $lang === '') {
            return ['locale' => $default, 'fellBack' => false];
        }
        $locale = self::canonical($lang);

        return in_array($locale, $enabled, true)
            ? ['locale' => $locale, 'fellBack' => false]
            : ['locale' => $default, 'fellBack' => true];
    }

    /**
     * The Developer area's and Client PWA's locale: the browser's most
     * preferred shipped locale (by region, then language), otherwise `en`.
     */
    public static function forDeveloperAreaAndClientPwa(?string $acceptLanguage): string
    {
        $preferences = [];
        foreach (explode(',', $acceptLanguage ?? '') as $i => $range) {
            $parts = explode(';', $range);
            $quality = 1.0;
            foreach (array_slice($parts, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([\d.]+)\s*$/i', $parameter, $match) === 1) {
                    $quality = (float) $match[1];
                }
            }
            if ($quality > 0) {
                // The index keeps equal qualities in the browser's order.
                $preferences[] = [-$quality, $i, self::canonical(trim($parts[0]))];
            }
        }
        sort($preferences);
        foreach ($preferences as [, , $locale]) {
            foreach ([$locale, explode('-', $locale)[0]] as $candidate) {
                if (in_array($candidate, self::SHIPPED, true)) {
                    return $candidate;
                }
            }
        }

        return 'en';
    }

    // BCP 47 tags are case-insensitive: Innesto writes `en-GB`.
    private static function canonical(string $tag): string
    {
        $parts = explode('-', $tag, 2);

        return strtolower($parts[0]) . (isset($parts[1]) ? '-' . strtoupper($parts[1]) : '');
    }
}
