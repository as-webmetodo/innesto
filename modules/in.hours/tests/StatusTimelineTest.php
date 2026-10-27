<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Hours\StatusTimeline;
use Innesto\Roots\Locale\Catalogue;

const HOURS_TZ = 'Europe/Rome';

/** @return list<array{start: string, end: string}> */
function hoursPeriods(string ...$spans): array
{
    return array_values(array_map(function (string $span): array {
        [$start, $end] = explode('–', $span);

        return ['start' => $start, 'end' => $end];
    }, $spans));
}

/** @return array<string, list<array{start: string, end: string}>> the same periods on every weekday */
function hoursEveryDay(string ...$spans): array
{
    return array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], hoursPeriods(...$spans));
}

/**
 * @param array<string, list<array{start: string, end: string}>> $weekly
 * @param list<array{from: string, to: string, yearly: bool, weekly: array<string, list<array{start: string, end: string}>>}> $seasons
 * @return array{timezone: string, weekly: array<string, list<array{start: string, end: string}>>, seasons: list<array{from: string, to: string, yearly: bool, weekly: array<string, list<array{start: string, end: string}>>}>}
 */
function hoursSchedule(array $weekly, array $seasons = []): array
{
    return ['timezone' => HOURS_TZ, 'weekly' => $weekly, 'seasons' => $seasons];
}

/** @return array{kind: string, start: string, end: ?string, yearly: bool, periods: list<array{start: string, end: string}>, edited: string} */
function hoursClosure(string $start, ?string $end, string $edited = '2026-10-01T00:00:00Z', bool $yearly = false): array
{
    return ['kind' => 'closure', 'start' => $start, 'end' => $end, 'yearly' => $yearly, 'periods' => [], 'edited' => $edited];
}

/**
 * @param list<array{start: string, end: string}> $periods
 * @return array{kind: string, start: string, end: ?string, yearly: bool, periods: list<array{start: string, end: string}>, edited: string}
 */
function hoursSpecial(string $start, string $end, array $periods, string $edited = '2026-10-01T00:00:00Z'): array
{
    return ['kind' => 'special', 'start' => $start, 'end' => $end, 'yearly' => false, 'periods' => $periods, 'edited' => $edited];
}

/**
 * The timeline at a fixed local time in the Schedule timezone; nothing reads the real time.
 *
 * @param array{timezone: string, weekly: array<string, list<array{start: string, end: string}>>, seasons: list<array{from: string, to: string, yearly: bool, weekly: array<string, list<array{start: string, end: string}>>}>} $schedule
 * @param list<array{kind: string, start: string, end: ?string, yearly: bool, periods: list<array{start: string, end: string}>, edited: string}> $exceptions
 * @return list<array{from: string, to: string, open: bool, text: string}>
 */
function hoursTimelineAt(string $localNow, array $schedule, array $exceptions = [], string $locale = 'en-GB'): array
{
    $root = dirname(__DIR__, 3);
    $timeline = new StatusTimeline(new Catalogue("$root/modules/in.hours/locales"), new Catalogue("$root/locales"));

    return $timeline->segments($schedule, $exceptions, new DateTimeImmutable($localNow, new DateTimeZone(HOURS_TZ)), $locale);
}

/**
 * Open segments as local "date from–to", to read a timeline day by day.
 *
 * @param list<array{from: string, to: string, open: bool, text: string}> $segments
 * @return list<string>
 */
function hoursOpenings(array $segments): array
{
    $local = fn (string $utc): DateTimeImmutable => (new DateTimeImmutable($utc))->setTimezone(new DateTimeZone(HOURS_TZ));

    return array_values(array_map(
        fn (array $segment): string => $local($segment['from'])->format('Y-m-d H:i') . '–' . $local($segment['to'])->format('H:i'),
        array_filter($segments, fn (array $segment): bool => $segment['open']),
    ));
}

/** @return array{from: string, to: string, open: bool, text: string} */
function hoursSegment(string $from, string $to, bool $open, string $text): array
{
    return ['from' => $from, 'to' => $to, 'open' => $open, 'text' => $text];
}

test('the timeline covers [now, now + 14 days) without gaps', function (): void {
    $segments = hoursTimelineAt('2026-11-03 10:17:42', hoursSchedule(hoursEveryDay('09:00–13:00')));

    expect($segments[0]['from'])->toBe('2026-11-03T09:17:42Z')
        ->and(end($segments)['to'] ?? null)->toBe('2026-11-17T09:17:42Z');
    foreach (array_slice($segments, 1) as $i => $segment) {
        expect($segment['from'])->toBe($segments[$i]['to']);
    }
});

test('several Opening periods a day alternate open and closed, split at local midnight', function (): void {
    $segments = hoursTimelineAt('2026-11-03 10:00', hoursSchedule(hoursEveryDay('09:00–13:00', '15:00–19:00')));

    expect(array_slice($segments, 0, 5))->toBe([
        hoursSegment('2026-11-03T09:00:00Z', '2026-11-03T12:00:00Z', true, 'Open · closes 13:00'),
        hoursSegment('2026-11-03T12:00:00Z', '2026-11-03T14:00:00Z', false, 'Closed · opens at 15:00'),
        hoursSegment('2026-11-03T14:00:00Z', '2026-11-03T18:00:00Z', true, 'Open · closes 19:00'),
        hoursSegment('2026-11-03T18:00:00Z', '2026-11-03T23:00:00Z', false, 'Closed · reopens tomorrow at 09:00'),
        hoursSegment('2026-11-03T23:00:00Z', '2026-11-04T08:00:00Z', false, 'Closed · opens at 09:00'),
    ]);
});

test('touching periods merge into one opening', function (): void {
    $segments = hoursTimelineAt('2026-11-03 10:00', hoursSchedule(hoursEveryDay('09:00–13:00', '13:00–19:00')));

    expect($segments[0])->toBe(hoursSegment('2026-11-03T09:00:00Z', '2026-11-03T18:00:00Z', true, 'Open · closes 19:00'));
});

test('sentences are localised', function (string $locale, array $expected): void {
    $weekdays = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri'], hoursPeriods('09:00–13:00', '15:00–19:00'));
    $segments = hoursTimelineAt('2026-11-05 10:00', hoursSchedule($weekdays), [hoursClosure('2026-11-09', '2026-11-16')], $locale);

    expect(array_column([...array_slice($segments, 0, 4), $segments[8], $segments[17]], 'text'))->toBe($expected);
})->with([
    'en, 12-hour' => ['en', [
        'Open · closes 1:00 PM',
        'Closed · opens at 3:00 PM',
        'Open · closes 7:00 PM',
        'Closed · reopens tomorrow at 9:00 AM',
        'Closed · reopens on November 17 at 9:00 AM',
        'Closed · reopens Tuesday at 9:00 AM',
    ]],
    'it' => ['it', [
        'Aperto · chiude alle 13:00',
        'Chiuso · apre alle 15:00',
        'Aperto · chiude alle 19:00',
        'Chiuso · riapre domani alle 09:00',
        'Chiuso · riapre il 17 novembre alle 09:00',
        'Chiuso · riapre martedì alle 09:00',
    ]],
]);

test('a past-midnight spill belongs to its start day and survives a Closure on the next day', function (): void {
    $segments = hoursTimelineAt('2026-11-03 20:00', hoursSchedule(hoursEveryDay('18:00–02:00')), [hoursClosure('2026-11-04', '2026-11-04')]);

    expect(array_slice($segments, 0, 4))->toBe([
        hoursSegment('2026-11-03T19:00:00Z', '2026-11-03T23:00:00Z', true, 'Open · closes tomorrow at 02:00'),
        hoursSegment('2026-11-03T23:00:00Z', '2026-11-04T01:00:00Z', true, 'Open · closes 02:00'),
        hoursSegment('2026-11-04T01:00:00Z', '2026-11-04T23:00:00Z', false, 'Closed · reopens tomorrow at 18:00'),
        hoursSegment('2026-11-04T23:00:00Z', '2026-11-05T17:00:00Z', false, 'Closed · opens at 18:00'),
    ]);
});

test('an Exception on the start day replaces its spill', function (): void {
    $segments = hoursTimelineAt('2026-11-03 13:00', hoursSchedule(hoursEveryDay('18:00–02:00')), [hoursSpecial('2026-11-03', '2026-11-03', hoursPeriods('12:00–14:00'))]);

    expect(array_slice($segments, 0, 3))->toBe([
        hoursSegment('2026-11-03T12:00:00Z', '2026-11-03T13:00:00Z', true, 'Open · closes 14:00'),
        hoursSegment('2026-11-03T13:00:00Z', '2026-11-03T23:00:00Z', false, 'Closed · reopens tomorrow at 18:00'),
        hoursSegment('2026-11-03T23:00:00Z', '2026-11-04T17:00:00Z', false, 'Closed · opens at 18:00'),
    ]);
});

test('an Exception spill overlapping the next day\'s first period merges into one opening', function (): void {
    $segments = hoursTimelineAt('2026-11-03 21:00', hoursSchedule(hoursEveryDay('09:00–13:00')), [hoursSpecial('2026-11-03', '2026-11-03', hoursPeriods('20:00–10:00'))]);

    expect(array_slice($segments, 0, 2))->toBe([
        hoursSegment('2026-11-03T20:00:00Z', '2026-11-03T23:00:00Z', true, 'Open · closes tomorrow at 13:00'),
        hoursSegment('2026-11-03T23:00:00Z', '2026-11-04T12:00:00Z', true, 'Open · closes 13:00'),
    ]);
});

test('an Exception beats a Season, which beats the Weekly hours; the shorter Exception wins, then the most recently edited', function (): void {
    $season = ['from' => '2026-11-01', 'to' => '2026-11-10', 'yearly' => false, 'weekly' => hoursEveryDay('08:00–20:00')];
    $exceptions = [
        hoursSpecial('2026-11-02', '2026-11-08', hoursPeriods('10:00–12:00'), '2026-10-01T10:00:00Z'),
        hoursClosure('2026-11-04', '2026-11-04', '2026-09-01T10:00:00Z'),
        hoursSpecial('2026-11-05', '2026-11-05', hoursPeriods('15:00–17:00'), '2026-10-20T10:00:00Z'),
        hoursSpecial('2026-11-05', '2026-11-05', hoursPeriods('14:00–16:00'), '2026-10-10T10:00:00Z'),
    ];

    expect(hoursOpenings(hoursTimelineAt('2026-11-03 00:00', hoursSchedule(hoursEveryDay('09:00–18:00'), [$season]), $exceptions)))->toBe([
        '2026-11-03 10:00–12:00',
        '2026-11-05 15:00–17:00',
        '2026-11-06 10:00–12:00',
        '2026-11-07 10:00–12:00',
        '2026-11-08 10:00–12:00',
        '2026-11-09 08:00–20:00',
        '2026-11-10 08:00–20:00',
        '2026-11-11 09:00–18:00',
        '2026-11-12 09:00–18:00',
        '2026-11-13 09:00–18:00',
        '2026-11-14 09:00–18:00',
        '2026-11-15 09:00–18:00',
        '2026-11-16 09:00–18:00',
    ]);
});

test('a yearly Season and a yearly Exception cross the new year', function (string $now, array $expected): void {
    $winter = ['from' => '11-01', 'to' => '02-28', 'yearly' => true, 'weekly' => hoursEveryDay('10:00–16:00')];
    $holidays = hoursClosure('12-24', '01-06', yearly: true);

    expect(hoursOpenings(hoursTimelineAt($now, hoursSchedule(hoursEveryDay('09:00–19:00'), [$winter]), [$holidays])))->toBe($expected);
})->with([
    'over the new year' => ['2026-12-30 00:00', ['2027-01-07 10:00–16:00', '2027-01-08 10:00–16:00', '2027-01-09 10:00–16:00', '2027-01-10 10:00–16:00', '2027-01-11 10:00–16:00', '2027-01-12 10:00–16:00']],
    'at the Season\'s end' => ['2027-02-24 00:00', ['2027-02-24 10:00–16:00', '2027-02-25 10:00–16:00', '2027-02-26 10:00–16:00', '2027-02-27 10:00–16:00', '2027-02-28 10:00–16:00', '2027-03-01 09:00–19:00', '2027-03-02 09:00–19:00', '2027-03-03 09:00–19:00', '2027-03-04 09:00–19:00', '2027-03-05 09:00–19:00', '2027-03-06 09:00–19:00', '2027-03-07 09:00–19:00', '2027-03-08 09:00–19:00', '2027-03-09 09:00–19:00']],
]);

test('times stay literal wall-clock across DST', function (string $now, array $expected): void {
    expect(array_slice(hoursTimelineAt($now, hoursSchedule(hoursEveryDay('09:00–18:00'))), 0, 4))->toBe($expected);
})->with([
    'DST ends' => ['2026-10-24 12:00', [
        hoursSegment('2026-10-24T10:00:00Z', '2026-10-24T16:00:00Z', true, 'Open · closes 18:00'),
        hoursSegment('2026-10-24T16:00:00Z', '2026-10-24T22:00:00Z', false, 'Closed · reopens tomorrow at 09:00'),
        hoursSegment('2026-10-24T22:00:00Z', '2026-10-25T08:00:00Z', false, 'Closed · opens at 09:00'),
        hoursSegment('2026-10-25T08:00:00Z', '2026-10-25T17:00:00Z', true, 'Open · closes 18:00'),
    ]],
    'DST starts' => ['2027-03-27 12:00', [
        hoursSegment('2027-03-27T11:00:00Z', '2027-03-27T17:00:00Z', true, 'Open · closes 18:00'),
        hoursSegment('2027-03-27T17:00:00Z', '2027-03-27T23:00:00Z', false, 'Closed · reopens tomorrow at 09:00'),
        hoursSegment('2027-03-27T23:00:00Z', '2027-03-28T07:00:00Z', false, 'Closed · opens at 09:00'),
        hoursSegment('2027-03-28T07:00:00Z', '2027-03-28T16:00:00Z', true, 'Open · closes 18:00'),
    ]],
]);

test('a period starting inside the DST gap is empty', function (): void {
    $segments = hoursTimelineAt('2027-03-27 12:00', hoursSchedule(hoursEveryDay('09:00–18:00')), [hoursSpecial('2027-03-28', '2027-03-28', hoursPeriods('02:30–03:00'))]);

    expect(array_slice($segments, 1, 3))->toBe([
        hoursSegment('2027-03-27T17:00:00Z', '2027-03-27T23:00:00Z', false, 'Closed · reopens Monday at 09:00'),
        hoursSegment('2027-03-27T23:00:00Z', '2027-03-28T22:00:00Z', false, 'Closed · reopens tomorrow at 09:00'),
        hoursSegment('2027-03-28T22:00:00Z', '2027-03-29T07:00:00Z', false, 'Closed · opens at 09:00'),
    ]);
});

test('a closed weekend is split at each midnight, each piece worded for its own day', function (): void {
    $weekdays = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri'], hoursPeriods('09:00–19:00'));

    expect(array_slice(hoursTimelineAt('2026-11-06 19:00', hoursSchedule($weekdays)), 0, 4))->toBe([
        hoursSegment('2026-11-06T18:00:00Z', '2026-11-06T23:00:00Z', false, 'Closed · reopens Monday at 09:00'),
        hoursSegment('2026-11-06T23:00:00Z', '2026-11-07T23:00:00Z', false, 'Closed · reopens Monday at 09:00'),
        hoursSegment('2026-11-07T23:00:00Z', '2026-11-08T23:00:00Z', false, 'Closed · reopens tomorrow at 09:00'),
        hoursSegment('2026-11-08T23:00:00Z', '2026-11-09T08:00:00Z', false, 'Closed · opens at 09:00'),
    ]);
});

test('rendered at 23:45, the segment after midnight reads "opens at", not "reopens tomorrow"', function (): void {
    expect(array_slice(hoursTimelineAt('2026-11-03 23:45', hoursSchedule(hoursEveryDay('09:00–19:00'))), 0, 2))->toBe([
        hoursSegment('2026-11-03T22:45:00Z', '2026-11-03T23:00:00Z', false, 'Closed · reopens tomorrow at 09:00'),
        hoursSegment('2026-11-03T23:00:00Z', '2026-11-04T08:00:00Z', false, 'Closed · opens at 09:00'),
    ]);
});

test('a reopening a week or more away is given its date', function (): void {
    $segments = hoursTimelineAt('2026-11-06 19:00', hoursSchedule(hoursEveryDay('09:00–19:00')), [hoursClosure('2026-11-07', '2026-11-16')]);

    expect($segments[0])->toBe(hoursSegment('2026-11-06T18:00:00Z', '2026-11-06T23:00:00Z', false, 'Closed · reopens on 17 November at 09:00'))
        ->and($segments[1]['text'])->toBe('Closed · reopens on 17 November at 09:00')
        ->and($segments[9]['text'])->toBe('Closed · reopens Tuesday at 09:00');
});

test('an open-ended Closure reads "until further notice" up to the horizon', function (): void {
    $segments = hoursTimelineAt('2026-11-03 10:00', hoursSchedule(hoursEveryDay('09:00–19:00')), [hoursClosure('2026-11-05', null)]);
    $last = end($segments);

    expect(array_slice($segments, 3, 3))->toBe([
        hoursSegment('2026-11-04T08:00:00Z', '2026-11-04T18:00:00Z', true, 'Open · closes 19:00'),
        hoursSegment('2026-11-04T18:00:00Z', '2026-11-04T23:00:00Z', false, 'Closed until further notice'),
        hoursSegment('2026-11-04T23:00:00Z', '2026-11-05T23:00:00Z', false, 'Closed until further notice'),
    ])
        ->and($last)->toBe(hoursSegment('2026-11-16T23:00:00Z', '2026-11-17T09:00:00Z', false, 'Closed until further notice'))
        ->and(count($segments))->toBe(18);
});

test('a segment running past the horizon ends with an absolute date', function (): void {
    $segments = hoursTimelineAt('2026-11-03 10:00', hoursSchedule(hoursEveryDay('09:00–19:00')), [hoursClosure('2026-11-05', '2026-11-17')]);

    [$beforeLast, $last] = array_slice($segments, -2);

    expect($last)->toBe(hoursSegment('2026-11-16T23:00:00Z', '2026-11-17T09:00:00Z', false, 'Closed · reopens on 18 November at 09:00'))
        ->and($beforeLast['text'])->toBe('Closed · reopens Wednesday at 09:00');
});

test('a change just within a year past the horizon is found; one beyond it reads "until further notice"', function (string $closureEnd, string $text): void {
    // Searched dates end before 2027-11-19: the horizon's 15 dates from 2026-11-03, then 366 more.
    $segments = hoursTimelineAt('2026-11-03 10:00', hoursSchedule(hoursEveryDay('09:00–19:00')), [hoursClosure('2026-11-05', $closureEnd)]);

    expect(end($segments)['text'] ?? null)->toBe($text);
})->with([
    'reopening on the last searched date' => ['2027-11-17', 'Closed · reopens on 18 November at 09:00'],
    'reopening past it' => ['2027-11-18', 'Closed until further notice'],
]);

test('a Schedule with no Weekly hours is closed until further notice', function (): void {
    $segments = hoursTimelineAt('2026-11-03 10:00', hoursSchedule([]));

    expect(hoursOpenings($segments))->toBe([])
        ->and(array_unique(array_column($segments, 'text')))->toBe(['Closed until further notice'])
        ->and(count($segments))->toBe(15);
});

test('a Schedule that is always open stays open until further notice', function (): void {
    $segments = hoursTimelineAt('2026-11-03 10:00', hoursSchedule(hoursEveryDay('00:00–24:00')));

    expect($segments[0])->toBe(hoursSegment('2026-11-03T09:00:00Z', '2026-11-03T23:00:00Z', true, 'Open until further notice'))
        ->and(array_unique(array_column($segments, 'text')))->toBe(['Open until further notice']);
});

test('malformed times and dates are refused', function (): void {
    expect(fn () => hoursTimelineAt('2026-11-03 10:00', hoursSchedule(hoursEveryDay('9:00–13:00'))))->toThrow(InvalidArgumentException::class, 'Malformed Opening period 9:00–13:00')
        ->and(fn () => hoursTimelineAt('2026-11-03 10:00', hoursSchedule(hoursEveryDay('09:00–13:00')), [hoursClosure('2026-11-4', '2026-11-05')]))->toThrow(InvalidArgumentException::class, 'Malformed date span 2026-11-4–2026-11-05')
        ->and(fn () => hoursTimelineAt('2026-11-03 10:00', hoursSchedule(hoursEveryDay('09:00–13:00')), [hoursClosure('12-24', null, yearly: true)]))->toThrow(InvalidArgumentException::class, 'A yearly span needs an end');
});
