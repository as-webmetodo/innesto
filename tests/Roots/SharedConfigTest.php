<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\SharedConfig;

test('before the Developer confirms it, the Shared config holds the defaults', function (): void {
    $install = install();
    completeSetup($install);

    $config = SharedConfig::fromStore(contentStore($install), 'https://example.com');

    expect($config->businessType)->toBe('LocalBusiness')
        ->and($config->defaultLocale)->toBe('en')
        ->and($config->enabledLocales)->toBe(['en'])
        ->and($config->installTimezone)->toBe('UTC')
        ->and($config->siteUrl)->toBe('https://example.com/')
        ->and($config->businessUrl)->toBe('https://example.com/')
        ->and($config->incompleteBusinessProfile())->toBeTrue()
        ->and($config->siteUrlIsDefault())->toBeTrue();
});

test('a Business profile without a name, street or city is incomplete', function (string $field): void {
    $install = install();
    $token = completeSetup($install);

    postConfig($install, $token, [$field => ''] + SHARED_FORM);

    expect(SharedConfig::fromStore(contentStore($install), 'https://example.com')->incompleteBusinessProfile())->toBeTrue();
})->with(['name', 'street_address', 'locality']);

test('a complete Business profile and a Site URL of its own raise no finding', function (): void {
    $install = install();
    $token = completeSetup($install);

    postConfig($install, $token, SHARED_FORM);
    $config = SharedConfig::fromStore(contentStore($install), 'https://example.com');

    expect($config->incompleteBusinessProfile())->toBeFalse()
        ->and($config->siteUrlIsDefault())->toBeFalse();
});

test('a confirmed Site URL equal to the root of Roots\' origin is still the default', function (): void {
    $install = install();
    $token = completeSetup($install);

    postConfig($install, $token, ['site_url' => 'https://example.com/'] + SHARED_FORM);

    expect(SharedConfig::fromStore(contentStore($install), 'https://example.com')->siteUrlIsDefault())->toBeTrue();
});

test('the Site URL written without the slash or in another case is still the default', function (string $url): void {
    $install = install();
    $token = completeSetup($install);

    postConfig($install, $token, ['site_url' => $url] + SHARED_FORM);

    expect(SharedConfig::fromStore(contentStore($install), 'https://example.com')->siteUrlIsDefault())->toBeTrue();
})->with(['https://example.com', 'https://Example.com/']);
