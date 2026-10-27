<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\Locale\LocaleResolver;
use Innesto\Roots\Locale\LocalisedText;

test('public output uses an enabled lang, otherwise the Default locale', function (?string $lang, array $expected): void {
    expect(LocaleResolver::forPublic($lang, ['it', 'en', 'en-GB'], 'it'))->toBe($expected);
})->with([
    'no lang' => [null, ['locale' => 'it', 'fellBack' => false]],
    'empty lang' => ['', ['locale' => 'it', 'fellBack' => false]],
    'enabled' => ['en', ['locale' => 'en', 'fellBack' => false]],
    'enabled, other case' => ['en-gb', ['locale' => 'en-GB', 'fellBack' => false]],
    'region of an enabled language' => ['en-US', ['locale' => 'en', 'fellBack' => false]],
    'not enabled' => ['fr', ['locale' => 'it', 'fellBack' => true]],
    'region of a language not enabled' => ['fr-CA', ['locale' => 'it', 'fellBack' => true]],
    'malformed' => ['../x', ['locale' => 'it', 'fellBack' => true]],
]);

test('the Developer area and Client PWA follow the browser among shipped locales', function (?string $acceptLanguage, string $expected): void {
    expect(LocaleResolver::forDeveloperAreaAndClientPwa($acceptLanguage))->toBe($expected);
})->with([
    'none' => [null, 'en'],
    'exact region' => ['en-GB,en;q=0.9', 'en-GB'],
    'language of an unshipped region' => ['it-CH, de;q=0.8', 'it'],
    'by preference, not order' => ['fr;q=0.2, it;q=0.7, de', 'it'],
    'refused with q=0' => ['it;q=0, en-US;q=0.5', 'en'],
    'nothing shipped' => ['de-DE, fr', 'en'],
    'lowercase region' => ['en-gb', 'en-GB'],
    'uppercase Q and other parameters' => ['fr;q=0.9, it;level=1;Q=0.95', 'it'],
]);

test('Localised text falls back to the Default locale', function (): void {
    $name = ['it' => 'Negozio', 'en' => 'Shop', 'fr' => ''];

    expect(LocalisedText::pick($name, 'en', 'it'))->toBe('Shop')
        ->and(LocalisedText::pick($name, 'en-GB', 'it'))->toBe('Negozio')
        ->and(LocalisedText::pick($name, 'fr', 'it'))->toBe('Negozio');
});

test('Localised text requires the Default locale', function (): void {
    expect(fn () => LocalisedText::pick(['en' => 'Shop'], 'en', 'it'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => LocalisedText::pick(['it' => '', 'en' => 'Shop'], 'en', 'it'))->toThrow(InvalidArgumentException::class);
});
