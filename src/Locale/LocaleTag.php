<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots\Locale;

/**
 * The locale tags Innesto uses: a BCP 47 language with an optional region,
 * written canonically as `en` or `en-GB`.
 */
final class LocaleTag
{
    private const CANONICAL = '/^[a-z]{2,3}(-[A-Z]{2})?$/';

    /** $tag in canonical case (tags are case-insensitive), or null when malformed. */
    public static function canonical(string $tag): ?string
    {
        $parts = explode('-', $tag, 2);
        $canonical = strtolower($parts[0]) . (isset($parts[1]) ? '-' . strtoupper($parts[1]) : '');

        return preg_match(self::CANONICAL, $canonical) === 1 ? $canonical : null;
    }

    /**
     * A canonical tag, then its language when it has a region.
     *
     * @return list<string> e.g. `en-GB` → [`en-GB`, `en`]
     */
    public static function withLanguage(string $canonical): array
    {
        return array_values(array_unique([$canonical, explode('-', $canonical)[0]]));
    }
}
