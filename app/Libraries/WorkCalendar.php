<?php

namespace App\Libraries;

use DateTime;
use DateTimeInterface;

/**
 * WorkCalendar
 *
 * Institutional Work Calendar for GSO E-Ticketing.
 * Implements Philippine Civil Service / University standard working hours:
 * - 8 working hours per day (8:00 AM to 5:00 PM with 1-hour lunch break 12:00 PM - 1:00 PM).
 * - Excludes weekends (Saturdays & Sundays).
 * - Excludes official Philippine regular and special non-working holidays (plus Holy Week).
 * - Accounts for approved overtime hours.
 */
class WorkCalendar
{
    public const WORK_START_HOUR = 8;  // 8:00 AM
    public const WORK_END_HOUR   = 17; // 5:00 PM
    public const LUNCH_START     = 12; // 12:00 PM
    public const LUNCH_END       = 13; // 1:00 PM
    public const STANDARD_DAILY_HOURS = 8.0;

    /**
     * Fixed-date Philippine holidays ('m-d').
     */
    private const FIXED_HOLIDAYS = [
        '01-01' => "New Year's Day",
        '02-25' => 'EDSA Revolution Anniversary',
        '04-09' => 'Araw ng Kagitingan (Day of Valor)',
        '05-01' => 'Labor Day',
        '06-12' => 'Independence Day',
        '08-21' => 'Ninoy Aquino Day',
        '11-01' => "All Saints' Day",
        '11-02' => "All Souls' Day",
        '11-30' => 'Bonifacio Day',
        '12-08' => 'Feast of the Immaculate Conception',
        '12-24' => 'Christmas Eve',
        '12-25' => 'Christmas Day',
        '12-30' => 'Rizal Day',
        '12-31' => "New Year's Eve",
    ];

    /**
     * Checks if a given date falls on a weekend (Saturday or Sunday).
     */
    public static function isWeekend(DateTimeInterface $date): bool
    {
        $dayOfWeek = (int) $date->format('N'); // 1 (Mon) through 7 (Sun)
        return $dayOfWeek >= 6;
    }

    /**
     * Checks if a given date is an official Philippine holiday.
     */
    public static function isHoliday(DateTimeInterface $date): bool
    {
        $monthDay = $date->format('m-d');
        if (isset(self::FIXED_HOLIDAYS[$monthDay])) {
            return true;
        }

        $year = (int) $date->format('Y');

        // National Heroes Day: Last Monday of August
        $lastMondayAug = new DateTime("last monday of august {$year}");
        if ($date->format('Y-m-d') === $lastMondayAug->format('Y-m-d')) {
            return true;
        }

        // Movable Holy Week Holidays based on Easter
        if (function_exists('easter_date')) {
            $easterTimestamp = easter_date($year);
            $easterDate = new DateTime();
            $easterDate->setTimestamp($easterTimestamp);

            // Maundy Thursday (-3 days)
            $maundyThursday = (clone $easterDate)->modify('-3 days')->format('Y-m-d');
            // Good Friday (-2 days)
            $goodFriday = (clone $easterDate)->modify('-2 days')->format('Y-m-d');
            // Black Saturday (-1 day)
            $blackSaturday = (clone $easterDate)->modify('-1 day')->format('Y-m-d');

            $currentYmd = $date->format('Y-m-d');
            if (in_array($currentYmd, [$maundyThursday, $goodFriday, $blackSaturday], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a date is a standard working day (non-weekend, non-holiday).
     */
    public static function isWorkingDay(DateTimeInterface $date): bool
    {
        return !self::isWeekend($date) && !self::isHoliday($date);
    }

    /**
     * Count the number of eligible working days between two dates inclusive.
     */
    public static function countWorkingDays(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $start = (new DateTime($from->format('Y-m-d')))->setTime(0, 0, 0);
        $end   = (new DateTime($to->format('Y-m-d')))->setTime(0, 0, 0);

        if ($start > $end) {
            return 0;
        }

        $workingDays = 0;
        $current = clone $start;

        while ($current <= $end) {
            if (self::isWorkingDay($current)) {
                $workingDays++;
            }
            $current->modify('+1 day');
        }

        return $workingDays;
    }

    /**
     * Add a specified number of working days to a starting date, skipping weekends and holidays.
     *
     * @param DateTimeInterface $startDate
     * @param int $workingDaysToAdd (e.g. 3)
     * @return DateTime Target completion date
     */
    public static function addWorkingDays(DateTimeInterface $startDate, int $workingDaysToAdd): DateTime
    {
        $current = new DateTime($startDate->format('Y-m-d H:i:s'));
        
        if ($workingDaysToAdd <= 0) {
            return $current;
        }

        $added = 0;
        while ($added < $workingDaysToAdd) {
            $current->modify('+1 day');
            if (self::isWorkingDay($current)) {
                $added++;
            }
        }

        return $current;
    }

    /**
     * Calculate net working hours between two timestamps, strictly adhering to
     * 8:00 AM - 5:00 PM (with 12:00 PM - 1:00 PM lunch break excluded),
     * omitting weekends and holidays, and adding any approved overtime hours.
     *
     * @param DateTimeInterface $start
     * @param DateTimeInterface $end
     * @param float $overtimeHours
     * @return float Total net working hours (rounded to 2 decimal places)
     */
    public static function calculateWorkingHours(
        DateTimeInterface $start,
        DateTimeInterface $end,
        float $overtimeHours = 0.0
    ): float {
        if ($start >= $end) {
            return max(0.0, round($overtimeHours, 2));
        }

        $totalHours = 0.0;
        $startDate = (new DateTime($start->format('Y-m-d')))->setTime(0, 0, 0);
        $endDate   = (new DateTime($end->format('Y-m-d')))->setTime(0, 0, 0);

        $current = clone $startDate;

        while ($current <= $endDate) {
            if (self::isWorkingDay($current)) {
                $dayYmd = $current->format('Y-m-d');
                $isStartDay = ($dayYmd === $start->format('Y-m-d'));
                $isEndDay   = ($dayYmd === $end->format('Y-m-d'));

                if (!$isStartDay && !$isEndDay) {
                    // Full intermediate working day
                    $totalHours += self::STANDARD_DAILY_HOURS;
                } else {
                    // Start day, end day, or same day
                    $dayStart = (new DateTime($dayYmd))->setTime(self::WORK_START_HOUR, 0, 0);
                    $dayEnd   = (new DateTime($dayYmd))->setTime(self::WORK_END_HOUR, 0, 0);

                    $windowStart = $isStartDay ? max($start, $dayStart) : $dayStart;
                    $windowEnd   = $isEndDay   ? min($end, $dayEnd)     : $dayEnd;

                    if ($windowStart < $windowEnd) {
                        $seconds = $windowEnd->getTimestamp() - $windowStart->getTimestamp();
                        $hours = $seconds / 3600.0;

                        // Deduct lunch break (12:00 - 13:00) if window intersects it
                        $lunchStart = (new DateTime($dayYmd))->setTime(self::LUNCH_START, 0, 0);
                        $lunchEnd   = (new DateTime($dayYmd))->setTime(self::LUNCH_END, 0, 0);

                        if ($windowStart < $lunchEnd && $windowEnd > $lunchStart) {
                            $overlapStart = max($windowStart, $lunchStart);
                            $overlapEnd   = min($windowEnd, $lunchEnd);
                            $lunchOverlapHours = ($overlapEnd->getTimestamp() - $overlapStart->getTimestamp()) / 3600.0;
                            $hours = max(0.0, $hours - $lunchOverlapHours);
                        }

                        $totalHours += min(self::STANDARD_DAILY_HOURS, $hours);
                    }
                }
            }

            $current->modify('+1 day');
        }

        $grandTotal = $totalHours + max(0.0, $overtimeHours);
        return round($grandTotal, 2);
    }

    /**
     * Human-friendly label for working duration.
     * E.g. "18.5 hrs (2d 2.5h) [+2.5h OT]"
     */
    public static function formatDuration(float $workingHours, float $overtimeHours = 0.0): string
    {
        $baseDays = floor($workingHours / self::STANDARD_DAILY_HOURS);
        $remHours = fmod($workingHours, self::STANDARD_DAILY_HOURS);

        $parts = [];
        if ($baseDays > 0) {
            $parts[] = "{$baseDays}d";
        }
        if ($remHours > 0 || empty($parts)) {
            $parts[] = round($remHours, 1) . 'h';
        }

        $formatted = implode(' ', $parts) . " ({$workingHours} hrs)";
        if ($overtimeHours > 0) {
            $formatted .= " [+" . round($overtimeHours, 1) . "h OT]";
        }

        return $formatted;
    }
}
