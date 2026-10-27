<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\Locale\Catalogue;

function fixtureCatalogue(): Catalogue
{
    return new Catalogue(__DIR__ . '/fixtures');
}

test('a key is looked up by region, then language, then the owner\'s en', function (): void {
    $catalogue = fixtureCatalogue();

    expect($catalogue->text('it-CH', 'greeting', ['name' => 'Ada']))->toBe('Grüezi, Ada')
        ->and($catalogue->text('it-CH', 'only_it'))->toBe('Solo italiano')
        ->and($catalogue->text('it-CH', 'only_en'))->toBe('English only')
        ->and($catalogue->text('it', 'greeting', ['name' => 'Ada']))->toBe('Ciao, Ada')
        ->and($catalogue->text('fr', 'greeting', ['name' => 'Ada']))->toBe('Hello, Ada');
});

test('locale tags are matched case-insensitively', function (): void {
    expect(fixtureCatalogue()->text('IT-ch', 'greeting', ['name' => 'Ada']))->toBe('Grüezi, Ada');
});

test('a key missing even in en is an error', function (): void {
    expect(fn () => fixtureCatalogue()->text('it', 'nowhere'))->toThrow(OutOfBoundsException::class);
});

test('a malformed locale tag is rejected before any file is read', function (string $tag): void {
    expect(fn () => fixtureCatalogue()->text($tag, 'greeting'))->toThrow(InvalidArgumentException::class);
})->with(['../en', 'en/../it', 'en_GB', 'english', '']);

test('plural forms are chosen by the rule of the catalogue that holds them', function (): void {
    $catalogue = fixtureCatalogue();

    expect($catalogue->plural('it', 'apples', 1))->toBe('1 mela')
        ->and($catalogue->plural('it-CH', 'apples', 3))->toBe('3 mele')
        ->and($catalogue->plural('pl', 'apples', 3))->toBe('3 jabłka')
        ->and($catalogue->plural('pl', 'apples', 5))->toBe('5 jabłek')
        ->and($catalogue->plural('pl', 'fewish', 3))->toBe('3 things');
});

test('a key used with the wrong shape is an error', function (): void {
    $catalogue = fixtureCatalogue();

    expect(fn () => $catalogue->text('it', 'apples'))->toThrow(OutOfBoundsException::class)
        ->and(fn () => $catalogue->plural('it', 'greeting', 2))->toThrow(OutOfBoundsException::class)
        ->and(fn () => $catalogue->plural('it', 'no_other', 2))->toThrow(OutOfBoundsException::class);
});

test('a catalogue file that does not return an array is an error', function (): void {
    expect(fn () => fixtureCatalogue()->text('nl', 'greeting'))->toThrow(InvalidArgumentException::class);
});
