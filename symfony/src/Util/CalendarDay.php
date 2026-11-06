<?php

namespace App\Util;

/**
 * Calendar-day arithmetic for the *arr's date-only values (review 2026-11-06, C2).
 *
 * Radarr release dates (`…T00:00:00Z`) and Sonarr air dates are calendar
 * days encoded as UTC midnight, not instants. Comparing them with
 * `new \DateTimeImmutable('today')` (midnight in the PHP process zone)
 * drops a release dated today as "past" for every user west of UTC and
 * buckets it under the wrong column east of it. "Today" must therefore be
 * the DISPLAY zone's calendar date expressed the same way: UTC midnight.
 */
final class CalendarDay
{
    /**
     * UTC midnight of today's calendar date in $tz — directly comparable
     * with the clients' date-only values. An invalid zone falls back to the
     * process zone rather than throwing in a widget.
     */
    public static function todayIn(string $tz, ?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        try {
            $zone = new \DateTimeZone($tz);
        } catch (\Throwable) {
            $zone = new \DateTimeZone(date_default_timezone_get());
        }
        $local = ($now ?? new \DateTimeImmutable('now'))->setTimezone($zone);

        return new \DateTimeImmutable($local->format('Y-m-d') . ' 00:00:00', new \DateTimeZone('UTC'));
    }

    /** Same calendar date, each value read in its own zone. */
    public static function isSameDay(\DateTimeInterface $a, \DateTimeInterface $b): bool
    {
        return $a->format('Y-m-d') === $b->format('Y-m-d');
    }
}
