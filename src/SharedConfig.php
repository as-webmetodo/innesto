<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use Innesto\Roots\Store\Store;

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
     *
     * @return SharedDocument
     */
    public static function document(Store $content, string $origin): array
    {
        $stored = $content->get('config', 'shared');
        if ($stored !== null) {
            /** @var SharedDocument $document written only by ConfigScreen, after validation */
            $document = array_diff_key($stored, ['id' => true]);

            return $document;
        }

        return [
            'name' => '', 'type' => 'LocalBusiness', 'street_address' => '', 'postal_code' => '', 'locality' => '',
            'region' => '', 'country' => '', 'phone' => '', 'url' => '', 'image' => '',
            'default_locale' => 'en', 'enabled_locales' => ['en'], 'timezone' => 'UTC', 'site_url' => self::defaultSiteUrl($origin),
        ];
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

    private static function defaultSiteUrl(string $origin): string
    {
        return "$origin/";
    }
}
