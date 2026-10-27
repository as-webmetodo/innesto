<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots\Locale;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OutOfBoundsException;

/**
 * Innesto's own date and time formatter, identical on every host (no intl).
 * Names and patterns come from the locale's catalogue; a date is formatted
 * in its own timezone.
 */
final class Formatter
{
    public function __construct(private readonly Catalogue $catalogue, private readonly string $locale)
    {
    }

    public function weekday(DateTimeInterface $date): string
    {
        return $this->name('date.weekdays', (int) $date->format('N') - 1);
    }

    public function month(DateTimeInterface $date): string
    {
        return $this->name('date.months', (int) $date->format('n') - 1);
    }

    /** The time of day, on the locale's 12- or 24-hour clock. */
    public function time(DateTimeInterface $date): string
    {
        return strtr($this->catalogue->text($this->locale, 'time.pattern'), [
            '{HH}' => $date->format('H'),
            '{h}' => $date->format('g'),
            '{mm}' => $date->format('i'),
            '{a}' => $this->catalogue->text($this->locale, $date->format('G') < 12 ? 'time.am' : 'time.pm'),
        ]);
    }

    /** A date as day and month, e.g. "3 November". */
    public function dayMonth(DateTimeInterface $date): string
    {
        return $this->catalogue->text($this->locale, 'date.day_month', ['d' => $date->format('j'), 'month' => $this->month($date)]);
    }

    /**
     * $date relative to the calendar date of $today: "today", "tomorrow", a
     * weekday name within the coming week, otherwise a day-month date.
     */
    public function relativeDay(DateTimeInterface $date, DateTimeInterface $today): string
    {
        $utc = new DateTimeZone('UTC');
        $days = (int) (new DateTimeImmutable($today->format('Y-m-d'), $utc))
            ->diff(new DateTimeImmutable($date->format('Y-m-d'), $utc))
            ->format('%r%a');

        return match (true) {
            $days === 0 => $this->catalogue->text($this->locale, 'date.today'),
            $days === 1 => $this->catalogue->text($this->locale, 'date.tomorrow'),
            $days > 1 && $days < 7 => $this->weekday($date),
            default => $this->dayMonth($date),
        };
    }

    private function name(string $key, int $index): string
    {
        $names = $this->catalogue->value($this->locale, $key);
        $name = is_array($names) ? $names[$index] ?? null : null;

        return is_string($name) ? $name : throw new OutOfBoundsException("$key has no entry $index for $this->locale");
    }
}
