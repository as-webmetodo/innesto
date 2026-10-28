<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use DateTimeZone;
use Innesto\Roots\Locale\LocaleResolver;
use Innesto\Roots\Store\Store;
use UnexpectedValueException;

/**
 * The Shared config as every Module reads it: read-only, built from Roots'
 * content Store, with the defaults until the Developer confirms it. Roots
 * settings are never part of it.
 *
 * @phpstan-type SharedDocument array{name: string, type: string, street_address: string, postal_code: string, locality: string, region: string, country: string, phone: string, url: string, image: string, default_locale: string, enabled_locales: list<string>, timezone: string, site_url: string}
 */
final class SharedConfig
{
    /** The Business profile's Schema.org types: `LocalBusiness` and common small-business subtypes. */
    public const TYPES = [
        'LocalBusiness', 'AutoRepair', 'Bakery', 'BarOrPub', 'BeautySalon', 'BookStore', 'CafeOrCoffeeShop',
        'ClothingStore', 'DaySpa', 'Dentist', 'Florist', 'GroceryStore', 'HairSalon', 'HealthClub', 'Hotel',
        'IceCreamShop', 'LegalService', 'MedicalClinic', 'Optician', 'Pharmacy', 'Physician', 'Restaurant', 'Store',
    ];

    /** Its document in the content Store's `config` collection. */
    public const ID = 'shared_config';

    /** The Business profile's free-text fields. */
    private const TEXT = ['name', 'street_address', 'postal_code', 'locality', 'region'];

    private const MAX_TEXT = 200;

    /** @var SharedDocument the defaults, but for the Site URL, which depends on Roots' origin */
    private const DEFAULTS = [
        'name' => '', 'type' => 'LocalBusiness', 'street_address' => '', 'postal_code' => '', 'locality' => '',
        'region' => '', 'country' => '', 'phone' => '', 'url' => '', 'image' => '',
        'default_locale' => 'en', 'enabled_locales' => ['en'], 'timezone' => 'UTC', 'site_url' => '',
    ];

    private function __construct(
        public readonly string $businessName,
        public readonly string $businessType,
        public readonly string $streetAddress,
        public readonly string $postalCode,
        public readonly string $locality,
        public readonly string $region,
        /** ISO 3166-1 alpha-2, or '' */
        public readonly string $country,
        public readonly string $phone,
        /** The Business profile URL, defaulting to the Site URL */
        public readonly string $businessUrl,
        public readonly string $image,
        public readonly string $defaultLocale,
        /** @var list<string> */
        public readonly array $enabledLocales,
        public readonly string $installTimezone,
        public readonly string $siteUrl,
        private readonly string $defaultSiteUrl,
    ) {
    }

    /** @param string $origin Roots' origin, whose root is the default Site URL */
    public static function fromStore(Store $content, string $origin): self
    {
        $shared = self::document($content, $origin);

        return new self(
            $shared['name'],
            $shared['type'],
            $shared['street_address'],
            $shared['postal_code'],
            $shared['locality'],
            $shared['region'],
            $shared['country'],
            $shared['phone'],
            $shared['url'] !== '' ? $shared['url'] : $shared['site_url'],
            $shared['image'],
            $shared['default_locale'],
            $shared['enabled_locales'],
            $shared['timezone'],
            $shared['site_url'],
            self::defaultSiteUrl($origin),
        );
    }

    /**
     * The stored fields, or the defaults before the Developer confirms them.
     * A stored document Roots could not have written fails fast.
     *
     * @return SharedDocument
     */
    public static function document(Store $content, string $origin): array
    {
        $stored = $content->get('config', self::ID);
        if ($stored === null) {
            return ['site_url' => self::defaultSiteUrl($origin)] + self::DEFAULTS;
        }
        $document = array_diff_key($stored, ['id' => true]);
        $strings = array_diff_key($document, ['enabled_locales' => true]);
        $locales = $document['enabled_locales'] ?? null;
        $shaped = array_diff_key(self::DEFAULTS, $document) === [] && array_diff_key($document, self::DEFAULTS) === []
            && array_filter($strings, is_string(...)) === $strings
            && is_array($locales) && array_is_list($locales) && array_filter($locales, is_string(...)) === $locales;
        /** @var SharedDocument $document as the checks above prove */
        if (!$shaped || self::errors($document, LocaleResolver::SHIPPED) !== []) {
            throw new UnexpectedValueException('The stored Shared config is not one Roots writes');
        }

        return $document;
    }

    /**
     * The Shared config a form asks for, normalised, and the fields at fault.
     *
     * @param array<string, string> $form
     * @param list<string> $locales the locales Roots and every Active Module cover, the only ones it may enable
     * @return array{SharedDocument, list<string>}
     */
    public static function fromForm(array $form, array $locales): array
    {
        $field = static fn (string $name): string => trim($form[$name] ?? '');
        $document = [
            'name' => $field('name'),
            'type' => $field('type'),
            'street_address' => $field('street_address'),
            'postal_code' => $field('postal_code'),
            'locality' => $field('locality'),
            'region' => $field('region'),
            'country' => strtoupper($field('country')),
            'phone' => $field('phone'),
            'url' => $field('url'),
            'image' => $field('image'),
            'default_locale' => $field('default_locale'),
            'enabled_locales' => array_values(array_filter($locales, static fn (string $locale): bool => isset($form["locale_$locale"]))),
            'timezone' => $field('timezone'),
            'site_url' => $field('site_url'),
        ];

        return [$document, self::errors($document, $locales)];
    }

    /** No name or no address: the `jsonld` View has nothing to emit. */
    public function incompleteBusinessProfile(): bool
    {
        return $this->businessName === '' || $this->streetAddress === '' || $this->locality === '';
    }

    /** Also for the same URL written without the slash or in another case. */
    public function siteUrlIsDefault(): bool
    {
        return strtolower(rtrim($this->siteUrl, '/')) === strtolower(rtrim($this->defaultSiteUrl, '/'));
    }

    /** The Site URL's origin as browsers send it: lower case, without a default port. */
    public function siteOrigin(): string
    {
        $parts = parse_url($this->siteUrl);
        $scheme = strtolower(is_array($parts) ? $parts['scheme'] ?? '' : '');
        $port = is_array($parts) ? $parts['port'] ?? null : null;
        $host = strtolower(is_array($parts) ? $parts['host'] ?? '' : '');

        return "$scheme://$host" . ($port === null || $port === ['http' => 80, 'https' => 443][$scheme] ? '' : ":$port");
    }

    /**
     * A stored document is checked against every locale Roots ships, so a
     * Module activated later never makes it unreadable.
     *
     * @param SharedDocument $document
     * @param list<string> $locales the locales it may enable
     * @return list<string> the fields at fault
     */
    private static function errors(array $document, array $locales): array
    {
        $errors = [];
        foreach (self::TEXT as $name) {
            $length = preg_match_all('/./su', $document[$name]);
            if ($length === false || $length > self::MAX_TEXT || preg_match('/\p{Cc}/u', $document[$name]) === 1) {
                $errors[] = $name;
            }
        }
        $checks = [
            'type' => in_array($document['type'], self::TYPES, true),
            'country' => preg_match('/^([A-Z]{2})?$/', $document['country']) === 1,
            'phone' => preg_match('~^(\+?[0-9][0-9 ()./-]{2,30})?$~', $document['phone']) === 1,
            'url' => $document['url'] === '' || self::webUrl($document['url']),
            'image' => $document['image'] === '' || self::webUrl($document['image']),
            'default_locale' => in_array($document['default_locale'], $document['enabled_locales'], true)
                && array_diff($document['enabled_locales'], $locales) === [],
            // With the backward-compatible names, a zone tzdata renames later stays valid.
            'timezone' => in_array($document['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true),
            'site_url' => self::webUrl($document['site_url']),
        ];

        return [...$errors, ...array_keys(array_filter($checks, static fn (bool $valid): bool => !$valid))];
    }

    /**
     * An absolute http(s) URL with a host, in printable ASCII (an IDN host
     * goes in punycode). `filter` is not an always-enabled extension.
     */
    private static function webUrl(string $url): bool
    {
        $parts = preg_match('~^https?://[\x21-\x7e]{1,2000}$~i', $url) === 1 ? parse_url($url) : false;

        return is_array($parts) && ($parts['host'] ?? '') !== '';
    }

    private static function defaultSiteUrl(string $origin): string
    {
        return "$origin/";
    }
}
