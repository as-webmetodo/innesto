<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\RootsSettings;
use Innesto\Roots\SharedConfig;

test('the Roots settings default to a 5-minute max-age and allow only the Site URL\'s origin', function (): void {
    $install = install();
    completeSetup($install);
    $store = contentStore($install);

    $settings = RootsSettings::fromStore($store);

    expect($settings->maxAge)->toBe(5)
        ->and($settings->allowedOrigins(SharedConfig::fromStore($store, 'https://example.com:443')))->toBe(['https://example.com']);
});

test('the allowed origins are the Site URL\'s origin, then the extra ones, once each', function (): void {
    $install = install();
    $token = completeSetup($install);
    postConfig($install, $token, ['site_url' => 'https://WWW.example.com:443/shop/'] + SHARED_FORM);
    postConfig($install, $token, ['action' => 'settings', 'max_age' => '15', 'extra_origins' => "https://preview.example.net\nhttps://www.example.com"]);
    $store = contentStore($install);

    $settings = RootsSettings::fromStore($store);

    expect($settings->maxAge)->toBe(15)
        ->and($settings->allowedOrigins(SharedConfig::fromStore($store, 'https://example.com')))->toBe(['https://www.example.com', 'https://preview.example.net']);
});

test('Roots settings the Store holds in a shape Roots never writes fail fast', function (): void {
    $install = install();
    completeSetup($install);
    $store = contentStore($install);

    $store->put('config', ['id' => 'roots_settings', 'max_age' => 0, 'extra_origins' => []]);

    expect(fn () => RootsSettings::fromStore($store))->toThrow(UnexpectedValueException::class);
});
