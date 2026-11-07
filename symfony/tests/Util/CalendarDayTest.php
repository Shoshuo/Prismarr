<?php

namespace App\Tests\Util;

use App\Util\CalendarDay;
use PHPUnit\Framework\TestCase;

/**
 * Review 2026-11-06 (C2 / DD-4): Radarr dates (`…T00:00:00Z`) and Sonarr
 * airDates (00:00 UTC) are calendar days, not instants. "Today" must be
 * the DISPLAY zone's calendar date expressed the same way (UTC midnight),
 * or a release dated today is dropped as "past" for every user west of UTC
 * (reproduced with America/New_York in the review).
 */
class CalendarDayTest extends TestCase
{
    public function testTodayIsTheDisplayZonesCalendarDateAtUtcMidnight(): void
    {
        $now = new \DateTimeImmutable('2026-11-06T03:30:00Z'); // still Nov 5 in New York

        $this->assertSame('2026-11-05T00:00:00+00:00', CalendarDay::todayIn('America/New_York', $now)->format('c'));
        $this->assertSame('2026-11-06T00:00:00+00:00', CalendarDay::todayIn('Europe/Paris', $now)->format('c'));
        $this->assertSame('2026-11-06T00:00:00+00:00', CalendarDay::todayIn('UTC', $now)->format('c'));
    }

    public function testAReleaseDatedTodayIsNotBeforeToday(): void
    {
        $now     = new \DateTimeImmutable('2026-11-06T03:30:00Z');
        $today   = CalendarDay::todayIn('America/New_York', $now);
        $release = new \DateTimeImmutable('2026-11-05T00:00:00Z'); // Radarr's date-only value for Nov 5

        $this->assertFalse($release->setTime(0, 0) < $today, 'dropped as past before the fix');
        $this->assertTrue(CalendarDay::isSameDay($release, $today));
    }

    public function testAnInvalidZoneFallsBackToTheProcessZone(): void
    {
        $now = new \DateTimeImmutable('2026-11-06T12:00:00Z');
        $this->assertSame(
            CalendarDay::todayIn(date_default_timezone_get(), $now)->format('c'),
            CalendarDay::todayIn('Not/AZone', $now)->format('c')
        );
    }
}
