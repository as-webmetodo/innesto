<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\Locale\Catalogue;
use Innesto\Roots\Locale\Formatter;

function formatter(string $locale): Formatter
{
    return new Formatter(new Catalogue(dirname(__DIR__, 2) . '/locales'), $locale);
}

function at(string $local): DateTimeImmutable
{
    return new DateTimeImmutable($local, new DateTimeZone('Europe/Rome'));
}

test('weekday and month names come from the locale', function (string $locale, string $weekday, string $month): void {
    $date = at('2026-11-02 12:00');

    expect(formatter($locale)->weekday($date))->toBe($weekday)
        ->and(formatter($locale)->month($date))->toBe($month)
        ->and(formatter($locale)->weekday(at('2026-11-08 12:00')))->toBe($locale === 'it' ? 'domenica' : 'Sunday');
})->with([
    ['en', 'Monday', 'November'],
    ['en-GB', 'Monday', 'November'],
    ['it', 'lunedì', 'novembre'],
]);

test('times follow the locale clock', function (string $locale, string $morning, string $afternoon, string $midnight): void {
    expect(formatter($locale)->time(at('2026-11-02 09:05')))->toBe($morning)
        ->and(formatter($locale)->time(at('2026-11-02 13:30')))->toBe($afternoon)
        ->and(formatter($locale)->time(at('2026-11-02 00:00')))->toBe($midnight);
})->with([
    'en, 12-hour' => ['en', '9:05 AM', '1:30 PM', '12:00 AM'],
    'en-GB, 24-hour' => ['en-GB', '09:05', '13:30', '00:00'],
    'it, 24-hour' => ['it', '09:05', '13:30', '00:00'],
]);

test('day-month dates follow the locale order', function (string $locale, string $expected): void {
    expect(formatter($locale)->dayMonth(at('2026-11-03 12:00')))->toBe($expected);
})->with([
    ['en', 'November 3'],
    ['en-GB', '3 November'],
    ['it', '3 novembre'],
]);

test('a day is today, tomorrow, a weekday within the week, or a day-month date', function (string $locale, string $date, string $expected): void {
    // Late on Monday 2 November, so the calendar date decides, not 24-hour spans.
    $today = at('2026-11-02 23:45');

    expect(formatter($locale)->relativeDay(at($date), $today))->toBe($expected);
})->with([
    ['en', '2026-11-02 08:00', 'today'],
    ['en', '2026-11-03 00:10', 'tomorrow'],
    ['en', '2026-11-04 09:00', 'Wednesday'],
    ['en', '2026-11-08 09:00', 'Sunday'],
    ['en', '2026-11-09 09:00', 'November 9'],
    ['en-GB', '2026-11-09 09:00', '9 November'],
    ['it', '2026-11-02 08:00', 'oggi'],
    ['it', '2026-11-03 00:10', 'domani'],
    ['it', '2026-11-04 09:00', 'mercoledì'],
    ['it', '2026-11-09 09:00', '9 novembre'],
]);

test('it and en declare their CLDR plural rules', function (string $locale): void {
    $catalogue = new Catalogue(dirname(__DIR__, 2) . '/locales');

    expect(array_map(fn (int $n): string => $catalogue->pluralCategory($locale, $n), [0, 1, 2, 11, 21, 100]))
        ->toBe(['other', 'one', 'other', 'other', 'other', 'other']);
})->with(['it', 'en', 'en-GB']);
