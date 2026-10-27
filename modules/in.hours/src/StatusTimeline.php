<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Hours;

use DateTimeImmutable;
use DateTimeZone;
use Innesto\Roots\Locale\Catalogue;
use Innesto\Roots\Locale\Formatter;
use InvalidArgumentException;

/**
 * A Schedule's Status timeline: open and closed segments over
 * [now, now + 14 days), each with its Visitor-facing sentence. Pure: the
 * caller passes the Schedule and its Exceptions as stored, and "now".
 *
 * @phpstan-type Period array{start: string, end: string}
 * @phpstan-type Weekly array<string, list<Period>>
 * @phpstan-type Season array{from: string, to: string, yearly: bool, weekly: Weekly}
 * @phpstan-type Schedule array{timezone: string, weekly: Weekly, seasons: list<Season>}
 * @phpstan-type Exception array{kind: string, start: string, end: ?string, yearly: bool, periods: list<Period>, edited: string}
 * @phpstan-type Segment array{from: string, to: string, open: bool, text: string}
 */
final class StatusTimeline
{
    private const HORIZON_DAYS = 14;

    /** How far past the horizon the next opening or closing is looked for before "until further notice". */
    private const SEARCH_DAYS = 366;

    private const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private const DATE = '/^\d{4}-\d\d-\d\d$/';

    private const MONTH_DAY = '/^\d\d-\d\d$/';

    /**
     * @param Catalogue $sentences in.hours' catalogue, for the Status sentences
     * @param Catalogue $dates Roots' catalogue, for the formatter's names and patterns
     */
    public function __construct(private readonly Catalogue $sentences, private readonly Catalogue $dates)
    {
    }

    /**
     * @param Schedule $schedule
     * @param list<Exception> $exceptions the Schedule's Exceptions
     * @return list<Segment>
     */
    public function segments(array $schedule, array $exceptions, DateTimeImmutable $now, string $locale): array
    {
        $zone = new DateTimeZone($schedule['timezone']);
        $today = $now->setTimezone($zone)->format('Y-m-d');
        $start = $now->getTimestamp();
        $end = $start + self::HORIZON_DAYS * 86400;

        $intervals = [];
        $lastDate = self::addDays($today, self::HORIZON_DAYS + 1 + self::SEARCH_DAYS);
        for ($date = self::addDays($today, -1); $date < $lastDate; $date = self::addDays($date, 1)) {
            foreach (self::periodsOn($date, $schedule, $exceptions) as $period) {
                $interval = self::interval($date, $period, $zone);
                // A start inside a DST gap moves forward, possibly past the end: the period is then empty.
                if ($interval[0] < $interval[1]) {
                    $intervals[] = $interval;
                }
            }
        }
        $intervals = self::merge($intervals);
        // Transitions at or past the last computed midnight are unknown.
        $known = self::at($lastDate, '00:00', $zone);

        $cuts = [$start, $end];
        foreach ($intervals as [$from, $to]) {
            $cuts[] = $from;
            $cuts[] = $to;
        }
        for ($days = 1; $days <= self::HORIZON_DAYS; $days++) {
            $cuts[] = self::at(self::addDays($today, $days), '00:00', $zone);
        }
        $cuts = array_values(array_unique(array_filter($cuts, fn (int $cut): bool => $cut >= $start && $cut <= $end)));
        sort($cuts);

        $formatter = new Formatter($this->dates, $locale);
        $segments = [];
        foreach (array_slice($cuts, 0, -1) as $i => $from) {
            $to = $cuts[$i + 1];
            [$open, $change] = self::stateAt($from, $intervals);
            $segments[] = [
                'from' => gmdate('Y-m-d\TH:i:s\Z', $from),
                'to' => gmdate('Y-m-d\TH:i:s\Z', $to),
                'open' => $open,
                'text' => $this->sentence($open, $from, $change !== null && $change < $known ? $change : null, $to === $end && $change > $end, $zone, $formatter, $locale),
            ];
        }

        return $segments;
    }

    /**
     * The Status sentence. "Today" and "tomorrow" are relative to the local
     * date the segment starts on; a change past the horizon is given its date.
     */
    private function sentence(bool $open, int $from, ?int $change, bool $pastHorizon, DateTimeZone $zone, Formatter $formatter, string $locale): string
    {
        $state = $open ? 'open' : 'closed';
        if ($change === null) {
            return $this->sentences->text($locale, "$state.until_further_notice");
        }
        $day = (new DateTimeImmutable("@$from"))->setTimezone($zone);
        $at = (new DateTimeImmutable("@$change"))->setTimezone($zone);
        $days = self::daysBetween($day->format('Y-m-d'), $at->format('Y-m-d'));
        $params = ['time' => $formatter->time($at)];

        return match (true) {
            $pastHorizon || $days >= 7 => $this->sentences->text($locale, "$state.date", $params + ['date' => $formatter->dayMonth($at)]),
            $days === 0 => $this->sentences->text($locale, "$state.today", $params),
            default => $this->sentences->text($locale, "$state.day", $params + ['day' => $formatter->relativeDay($at, $day)]),
        };
    }

    /**
     * Whether $instant is open, and when that next changes (null when never).
     *
     * @param list<array{int, int}> $intervals merged and sorted
     * @return array{bool, ?int}
     */
    private static function stateAt(int $instant, array $intervals): array
    {
        foreach ($intervals as [$from, $to]) {
            if ($instant < $from) {
                return [false, $from];
            }
            if ($instant < $to) {
                return [true, $to];
            }
        }

        return [false, null];
    }

    /**
     * A date's Opening periods by precedence: Exception > Season > Weekly hours.
     *
     * @param Schedule $schedule
     * @param list<Exception> $exceptions
     * @return list<Period>
     */
    private static function periodsOn(string $date, array $schedule, array $exceptions): array
    {
        $winner = null;
        $winnerKey = null;
        foreach ($exceptions as $exception) {
            $span = self::span($exception['start'], $exception['end'], $exception['yearly'], $date);
            if ($span === null) {
                continue;
            }
            // The shorter span wins, then the most recently edited.
            $key = [$span, -(new DateTimeImmutable($exception['edited']))->getTimestamp()];
            if ($winnerKey === null || $key < $winnerKey) {
                [$winner, $winnerKey] = [$exception, $key];
            }
        }
        if ($winner !== null) {
            return $winner['kind'] === 'closure' ? [] : $winner['periods'];
        }

        $weekly = $schedule['weekly'];
        foreach ($schedule['seasons'] as $season) {
            if (self::span($season['from'], $season['to'], $season['yearly'], $date) !== null) {
                $weekly = $season['weekly'];
                break;
            }
        }

        return $weekly[self::WEEKDAYS[(int) self::day($date)->format('N') - 1]] ?? [];
    }

    /**
     * The length in days of the span (or of the yearly occurrence) holding
     * $date, null when it does not; an open-ended span is endless.
     */
    private static function span(string $from, ?string $to, bool $yearly, string $date): ?int
    {
        $format = $yearly ? self::MONTH_DAY : self::DATE;
        if (preg_match($format, $from) !== 1 || ($to !== null && preg_match($format, $to) !== 1)) {
            throw new InvalidArgumentException("Malformed date span {$from}–{$to}");
        }
        if (!$yearly) {
            if ($date < $from || ($to !== null && $date > $to)) {
                return null;
            }

            return $to === null ? PHP_INT_MAX : self::daysBetween($from, $to);
        }
        if ($to === null) {
            throw new InvalidArgumentException('A yearly span needs an end');
        }
        $year = (int) substr($date, 0, 4);
        foreach ([$year, $year - 1] as $startYear) {
            $start = "$startYear-$from";
            $end = ($to < $from ? $startYear + 1 : $startYear) . "-$to";
            if ($date >= $start && $date <= $end) {
                return self::daysBetween($start, $end);
            }
        }

        return null;
    }

    /**
     * A period as UTC instants. Times are literal wall-clock in the Schedule
     * timezone; an end not after the start is on the next day.
     *
     * @param Period $period
     * @return array{int, int}
     */
    private static function interval(string $date, array $period, DateTimeZone $zone): array
    {
        if (preg_match(self::TIME, $period['start']) !== 1 || ($period['end'] !== '24:00' && preg_match(self::TIME, $period['end']) !== 1)) {
            throw new InvalidArgumentException("Malformed Opening period {$period['start']}–{$period['end']} on $date");
        }
        $endDate = $period['end'] === '24:00' || $period['end'] <= $period['start'] ? self::addDays($date, 1) : $date;

        return [self::at($date, $period['start'], $zone), self::at($endDate, $period['end'] === '24:00' ? '00:00' : $period['end'], $zone)];
    }

    /**
     * Sorts intervals and merges those that overlap or touch.
     *
     * @param list<array{int, int}> $intervals
     * @return list<array{int, int}>
     */
    private static function merge(array $intervals): array
    {
        sort($intervals);
        $merged = [];
        foreach ($intervals as [$from, $to]) {
            $last = array_key_last($merged);
            if ($last !== null && $from <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $to);
            } else {
                $merged[] = [$from, $to];
            }
        }

        return $merged;
    }

    private static function at(string $date, string $time, DateTimeZone $zone): int
    {
        return (new DateTimeImmutable("$date $time", $zone))->getTimestamp();
    }

    private static function addDays(string $date, int $days): string
    {
        return self::day($date)->modify("$days day")->format('Y-m-d');
    }

    private static function daysBetween(string $from, string $to): int
    {
        return (int) self::day($from)->diff(self::day($to))->format('%a');
    }

    /** A calendar date, in UTC so that day arithmetic never meets DST. */
    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }
}
