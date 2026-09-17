<?php

use App\Libraries\WorkCalendar;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Level 1 — Unit tests for the institutional Work Calendar.
 *
 * Covers every public method of App\Libraries\WorkCalendar, which drives
 * dispatch scheduling (EODB 3/7/21-day tiers), target completion dates,
 * and working-hours accounting across ticket operations.
 *
 * @internal
 */
final class WorkCalendarTest extends CIUnitTestCase
{
    public function testWorkdayConstantsMatchInstitutionalPolicy(): void
    {
        $this->assertSame(8, WorkCalendar::WORK_START_HOUR);
        $this->assertSame(17, WorkCalendar::WORK_END_HOUR);
        $this->assertSame(12, WorkCalendar::LUNCH_START);
        $this->assertSame(13, WorkCalendar::LUNCH_END);
        $this->assertSame(8.0, WorkCalendar::STANDARD_DAILY_HOURS);
    }

    public function testIsWeekendFlagsSaturdayAndSundayOnly(): void
    {
        // 2026-09-19 is a Saturday, 2026-09-20 a Sunday, 2026-09-21 a Monday.
        $this->assertTrue(WorkCalendar::isWeekend(new DateTime('2026-09-19')));
        $this->assertTrue(WorkCalendar::isWeekend(new DateTime('2026-09-20')));
        $this->assertFalse(WorkCalendar::isWeekend(new DateTime('2026-09-21')));
        $this->assertFalse(WorkCalendar::isWeekend(new DateTime('2026-09-17')));
    }

    public function testIsHolidayCoversFixedPhilippineHolidays(): void
    {
        $this->assertTrue(WorkCalendar::isHoliday(new DateTime('2026-01-01'))); // New Year
        $this->assertTrue(WorkCalendar::isHoliday(new DateTime('2026-06-12'))); // Independence Day
        $this->assertTrue(WorkCalendar::isHoliday(new DateTime('2026-12-25'))); // Christmas
        $this->assertTrue(WorkCalendar::isHoliday(new DateTime('2026-12-30'))); // Rizal Day
        $this->assertFalse(WorkCalendar::isHoliday(new DateTime('2026-09-21'))); // Ordinary Monday
    }

    public function testIsHolidayCoversNationalHeroesDayLastMondayOfAugust(): void
    {
        // Last Monday of August 2026 is the 31st.
        $this->assertTrue(WorkCalendar::isHoliday(new DateTime('2026-08-31')));
        $this->assertFalse(WorkCalendar::isHoliday(new DateTime('2026-08-24')));
    }

    public function testIsHolidayCoversHolyWeekTriduum(): void
    {
        // Easter 2026 falls on April 5: Maundy Thursday Apr 2, Good Friday Apr 3.
        $this->assertTrue(WorkCalendar::isHoliday(new DateTime('2026-04-02')));
        $this->assertTrue(WorkCalendar::isHoliday(new DateTime('2026-04-03')));
        $this->assertFalse(WorkCalendar::isHoliday(new DateTime('2026-04-06'))); // Easter Monday
    }

    public function testIsWorkingDayExcludesWeekendsAndHolidays(): void
    {
        $this->assertFalse(WorkCalendar::isWorkingDay(new DateTime('2026-09-19'))); // Saturday
        $this->assertFalse(WorkCalendar::isWorkingDay(new DateTime('2026-06-12'))); // Holiday (Friday)
        $this->assertTrue(WorkCalendar::isWorkingDay(new DateTime('2026-09-21')));  // Monday
    }

    public function testCountWorkingDaysAcrossFullWorkWeek(): void
    {
        $this->assertSame(
            5,
            WorkCalendar::countWorkingDays(new DateTime('2026-09-21'), new DateTime('2026-09-25'))
        );
    }

    public function testCountWorkingDaysSkipsWeekend(): void
    {
        // Sat Sep 19 .. Mon Sep 21 contains a single working day.
        $this->assertSame(
            1,
            WorkCalendar::countWorkingDays(new DateTime('2026-09-19'), new DateTime('2026-09-21'))
        );
    }

    public function testCountWorkingDaysSkipsHoliday(): void
    {
        // Thu Jun 11 .. Fri Jun 12 (Independence Day) contains one working day.
        $this->assertSame(
            1,
            WorkCalendar::countWorkingDays(new DateTime('2026-06-11'), new DateTime('2026-06-12'))
        );
    }

    public function testCountWorkingDaysReturnsZeroForReversedRange(): void
    {
        $this->assertSame(
            0,
            WorkCalendar::countWorkingDays(new DateTime('2026-09-25'), new DateTime('2026-09-21'))
        );
    }

    public function testAddWorkingDaysSkipsWeekend(): void
    {
        // Friday Sep 18 + 1 working day lands on Monday Sep 21.
        $result = WorkCalendar::addWorkingDays(new DateTime('2026-09-18'), 1);
        $this->assertSame('2026-09-21', $result->format('Y-m-d'));
    }

    public function testAddWorkingDaysSkipsHoliday(): void
    {
        // Thu Jun 11 + 1 working day skips Independence Day (Jun 12) to Mon Jun 15.
        $result = WorkCalendar::addWorkingDays(new DateTime('2026-06-11'), 1);
        $this->assertSame('2026-06-15', $result->format('Y-m-d'));
    }

    public function testAddWorkingDaysWithZeroOrNegativeReturnsStartDate(): void
    {
        $this->assertSame(
            '2026-09-21',
            WorkCalendar::addWorkingDays(new DateTime('2026-09-21 10:00:00'), 0)->format('Y-m-d')
        );
        $this->assertSame(
            '2026-09-21',
            WorkCalendar::addWorkingDays(new DateTime('2026-09-21'), -5)->format('Y-m-d')
        );
    }

    public function testAddWorkingDaysNeverLandsOnNonWorkingDay(): void
    {
        $result = WorkCalendar::addWorkingDays(new DateTime('2026-09-21'), 10);
        $this->assertTrue(WorkCalendar::isWorkingDay($result));
    }

    public function testCalculateWorkingHoursForFullDay(): void
    {
        // 8:00 AM – 5:00 PM with 1-hour lunch = 8 net hours.
        $this->assertSame(
            8.0,
            WorkCalendar::calculateWorkingHours(new DateTime('2026-09-21 08:00:00'), new DateTime('2026-09-21 17:00:00'))
        );
    }

    public function testCalculateWorkingHoursForMorningOnly(): void
    {
        $this->assertSame(
            4.0,
            WorkCalendar::calculateWorkingHours(new DateTime('2026-09-21 08:00:00'), new DateTime('2026-09-21 12:00:00'))
        );
    }

    public function testCalculateWorkingHoursDeductsLunchOverlap(): void
    {
        // 11:00 AM – 2:00 PM spans lunch: 3h gross minus 1h lunch = 2h net.
        $this->assertSame(
            2.0,
            WorkCalendar::calculateWorkingHours(new DateTime('2026-09-21 11:00:00'), new DateTime('2026-09-21 14:00:00'))
        );
    }

    public function testCalculateWorkingHoursIgnoresWeekends(): void
    {
        $this->assertSame(
            0.0,
            WorkCalendar::calculateWorkingHours(new DateTime('2026-09-19 08:00:00'), new DateTime('2026-09-19 17:00:00'))
        );
    }

    public function testCalculateWorkingHoursAddsOvertime(): void
    {
        $this->assertSame(
            10.5,
            WorkCalendar::calculateWorkingHours(new DateTime('2026-09-21 08:00:00'), new DateTime('2026-09-21 17:00:00'), 2.5)
        );
    }

    public function testCalculateWorkingHoursWithReversedRangeReturnsOvertimeOnly(): void
    {
        $this->assertSame(
            1.5,
            WorkCalendar::calculateWorkingHours(new DateTime('2026-09-22 08:00:00'), new DateTime('2026-09-21 08:00:00'), 1.5)
        );
    }

    public function testFormatDurationLabels(): void
    {
        $this->assertSame('1d (8 hrs)', WorkCalendar::formatDuration(8.0));
        $this->assertSame('2d 2.5h (18.5 hrs)', WorkCalendar::formatDuration(18.5));
        $this->assertSame('0h (0 hrs)', WorkCalendar::formatDuration(0.0));
        $this->assertSame('1d (8 hrs) [+2.5h OT]', WorkCalendar::formatDuration(8.0, 2.5));
    }
}
