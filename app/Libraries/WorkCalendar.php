<?php

namespace App\Libraries;

use DateTime;
use DateTimeInterface;

/**
 * WorkCalendar
 *
 * Institutional Work Calendar for GSO E-Ticketing.
 * Implements Philippine Civil Service / University standard working hours:
 * - Net working hours per day (dynamically configurable, default 8:00 AM to 5:00 PM with 12:00 PM - 1:00 PM lunch break).
 * - Excludes weekends (Saturdays & Sundays).
 * - Excludes official Philippine regular and special non-working holidays (plus Holy Week and custom non-working holidays).
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
     * Runtime caches to prevent redundant database queries during iteration loops.
     */
    private static ?array $customHolidaysCache = null;
    private static ?array $workingHoursConfigCache = null;

    /**
     * Clear memoized settings and holiday cache (used on update or in tests).
     */
    public static function clearCache(): void
    {
        self::$customHolidaysCache = null;
        self::$workingHoursConfigCache = null;
    }

    /**
     * Retrieve active operational working hours configuration with database fallback.
     */
    public static function getWorkingHoursConfig(): array
    {
        if (self::$workingHoursConfigCache !== null) {
            return self::$workingHoursConfigCache;
        }

        $config = [
            'start_hour'                 => self::WORK_START_HOUR,
            'start_min'                  => 0,
            'end_hour'                   => self::WORK_END_HOUR,
            'end_min'                    => 0,
            'lunch_start'                => self::LUNCH_START,
            'lunch_end'                  => self::LUNCH_END,
            'daily_hours'                => self::STANDARD_DAILY_HOURS,
            'daily_continuation_enabled' => true,
            'continuation_start_time'    => '08:00',
            'continuation_cutoff_time'   => '17:00',
            'lunch_break_enabled'        => true,
            'morning_break_enabled'      => false,
            'morning_break_start'        => '10:00',
            'morning_break_end'          => '10:15',
            'afternoon_break_enabled'    => false,
            'afternoon_break_start'      => '15:00',
            'afternoon_break_end'        => '15:15',
            'exclude_weekends'           => true,
            'exclude_saturday'           => true,
            'exclude_sunday'             => true,
        ];

        try {
            $settingModel = new \App\Models\SystemSettingModel();
            $startTime  = $settingModel->getSetting('work_start_time', '08:00');
            $endTime    = $settingModel->getSetting('work_end_time', '17:00');
            $lunchStart = $settingModel->getSetting('lunch_start_time', '12:00');
            $lunchEnd   = $settingModel->getSetting('lunch_end_time', '13:00');
            $dailyHours = (float) $settingModel->getSetting('standard_daily_hours', '8.0');

            if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($startTime), $sm)) {
                $config['start_hour'] = (int) $sm[1];
                $config['start_min']  = (int) $sm[2];
            }
            if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($endTime), $em)) {
                $config['end_hour'] = (int) $em[1];
                $config['end_min']  = (int) $em[2];
            }
            if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($lunchStart), $lm)) {
                $config['lunch_start'] = (int) $lm[1];
            }
            if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($lunchEnd), $lem)) {
                $config['lunch_end'] = (int) $lem[1];
            }
            if ($dailyHours > 0) {
                $config['daily_hours'] = $dailyHours;
            }

            // Automatic daily continuation of jobs settings
            $continuationEnabled = $settingModel->getSetting('daily_continuation_enabled');
            if ($continuationEnabled !== null) {
                $config['daily_continuation_enabled'] = (bool) (int) $continuationEnabled;
            }
            $config['continuation_start_time']  = $settingModel->getSetting('continuation_start_time', $startTime ?: '08:00');
            $config['continuation_cutoff_time'] = $settingModel->getSetting('continuation_cutoff_time', $endTime ?: '17:00');

            // Time breaks settings
            $lunchEnabled = $settingModel->getSetting('lunch_break_enabled');
            if ($lunchEnabled !== null) {
                $config['lunch_break_enabled'] = (bool) (int) $lunchEnabled;
            }
            $mornEnabled = $settingModel->getSetting('morning_break_enabled');
            if ($mornEnabled !== null) {
                $config['morning_break_enabled'] = (bool) (int) $mornEnabled;
            }
            $config['morning_break_start'] = $settingModel->getSetting('morning_break_start', '10:00');
            $config['morning_break_end']   = $settingModel->getSetting('morning_break_end', '10:15');

            $aftEnabled = $settingModel->getSetting('afternoon_break_enabled');
            if ($aftEnabled !== null) {
                $config['afternoon_break_enabled'] = (bool) (int) $aftEnabled;
            }
            $config['afternoon_break_start'] = $settingModel->getSetting('afternoon_break_start', '15:00');
            $config['afternoon_break_end']   = $settingModel->getSetting('afternoon_break_end', '15:15');

            // Weekend exclusion settings
            $exWeekends = $settingModel->getSetting('exclude_weekends');
            if ($exWeekends !== null) {
                $config['exclude_weekends'] = (bool) (int) $exWeekends;
            }
            $exSat = $settingModel->getSetting('exclude_saturday');
            if ($exSat !== null) {
                $config['exclude_saturday'] = (bool) (int) $exSat;
            }
            $exSun = $settingModel->getSetting('exclude_sunday');
            if ($exSun !== null) {
                $config['exclude_sunday'] = (bool) (int) $exSun;
            }
        } catch (\Throwable $e) {
            // Defensive fallback to standard constants
        }

        self::$workingHoursConfigCache = $config;
        return $config;
    }

    /**
     * Checks if a given date falls on a weekend, respecting institutional exclusion policy.
     */
    public static function isWeekend(DateTimeInterface $date): bool
    {
        $cfg = self::getWorkingHoursConfig();
        if (isset($cfg['exclude_weekends']) && !$cfg['exclude_weekends']) {
            return false;
        }

        $dayOfWeek = (int) $date->format('N'); // 1 (Mon) through 7 (Sun)
        $excludeSat = !isset($cfg['exclude_saturday']) || !empty($cfg['exclude_saturday']);
        $excludeSun = !isset($cfg['exclude_sunday']) || !empty($cfg['exclude_sunday']);

        if ($dayOfWeek === 6) return (bool) $excludeSat;
        if ($dayOfWeek === 7) return (bool) $excludeSun;

        return false;
    }

    /**
     * Checks if a given date is an official Philippine holiday or custom special non-working holiday.
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

        // Check dynamically configured special non-working holidays
        if (self::isSpecialHolidayDate($date)) {
            return true;
        }

        return false;
    }

    /**
     * Check if date matches dynamically configured special non-working holidays.
     */
    private static function isSpecialHolidayDate(DateTimeInterface $date): bool
    {
        $ymd = $date->format('Y-m-d');
        $md  = $date->format('m-d');

        if (self::$customHolidaysCache === null) {
            try {
                $holidayModel = new \App\Models\SpecialHolidayModel();
                self::$customHolidaysCache = $holidayModel->getActiveHolidays();
            } catch (\Throwable $e) {
                self::$customHolidaysCache = [];
            }
        }

        foreach (self::$customHolidaysCache as $holiday) {
            if (!empty($holiday['holiday_date']) && $holiday['holiday_date'] === $ymd) {
                return true;
            }
            if (!empty($holiday['is_recurring']) && !empty($holiday['holiday_date'])) {
                $recMd = date('m-d', strtotime($holiday['holiday_date']));
                if ($recMd === $md) {
                    return true;
                }
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
     * configured operational work hours (with lunch break excluded),
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

        $cfg = self::getWorkingHoursConfig();
        $workStartHour = $cfg['start_hour'];
        $workStartMin  = $cfg['start_min'];
        $workEndHour   = $cfg['end_hour'];
        $workEndMin    = $cfg['end_min'];
        $lunchStartH   = $cfg['lunch_start'];
        $lunchEndH     = $cfg['lunch_end'];
        $standardDaily = $cfg['daily_hours'];

        $totalHours = 0.0;
        $startDate = (new DateTime($start->format('Y-m-d')))->setTime(0, 0, 0);
        $endDate   = (new DateTime($end->format('Y-m-d')))->setTime(0, 0, 0);

        $current = clone $startDate;

        while ($current <= $endDate) {
            $dayYmd = $current->format('Y-m-d');
            $isStartDay = ($dayYmd === $start->format('Y-m-d'));
            $isEndDay   = ($dayYmd === $end->format('Y-m-d'));

            if (self::isWorkingDay($current)) {
                if (!$isStartDay && !$isEndDay) {
                    // Full intermediate working day
                    $totalHours += $standardDaily;
                } else {
                    // Start day, end day, or same day
                    $dayStart = (new DateTime($dayYmd))->setTime($workStartHour, $workStartMin, 0);
                    $dayEnd   = (new DateTime($dayYmd))->setTime($workEndHour, $workEndMin, 0);

                    // On start day, count from actual start timestamp so early starts are not discarded
                    $windowStart = $isStartDay ? $start : $dayStart;
                    $windowEnd   = $isEndDay   ? min($end, $dayEnd) : $dayEnd;

                    if ($windowStart < $windowEnd) {
                        $seconds = $windowEnd->getTimestamp() - $windowStart->getTimestamp();
                        $hours = $seconds / 3600.0;

                        // Deduct lunch break if enabled and window intersects it
                        if (!empty($cfg['lunch_break_enabled']) && $lunchStartH < $lunchEndH) {
                            $lunchStart = (new DateTime($dayYmd))->setTime($lunchStartH, 0, 0);
                            $lunchEnd   = (new DateTime($dayYmd))->setTime($lunchEndH, 0, 0);

                            if ($windowStart < $lunchEnd && $windowEnd > $lunchStart) {
                                $overlapStart = max($windowStart, $lunchStart);
                                $overlapEnd   = min($windowEnd, $lunchEnd);
                                $lunchOverlapHours = ($overlapEnd->getTimestamp() - $overlapStart->getTimestamp()) / 3600.0;
                                $hours = max(0.0, $hours - $lunchOverlapHours);
                            }
                        }

                        // Deduct morning break if enabled
                        if (!empty($cfg['morning_break_enabled']) && !empty($cfg['morning_break_start']) && !empty($cfg['morning_break_end'])) {
                            [$mStartH, $mStartM] = array_pad(array_map('intval', explode(':', $cfg['morning_break_start'])), 2, 0);
                            [$mEndH, $mEndM]     = array_pad(array_map('intval', explode(':', $cfg['morning_break_end'])), 2, 0);
                            $mBreakStart = (new DateTime($dayYmd))->setTime($mStartH, $mStartM, 0);
                            $mBreakEnd   = (new DateTime($dayYmd))->setTime($mEndH, $mEndM, 0);

                            if ($windowStart < $mBreakEnd && $windowEnd > $mBreakStart) {
                                $mOverlapStart = max($windowStart, $mBreakStart);
                                $mOverlapEnd   = min($windowEnd, $mBreakEnd);
                                $mOverlapHours = ($mOverlapEnd->getTimestamp() - $mOverlapStart->getTimestamp()) / 3600.0;
                                $hours = max(0.0, $hours - $mOverlapHours);
                            }
                        }

                        // Deduct afternoon break if enabled
                        if (!empty($cfg['afternoon_break_enabled']) && !empty($cfg['afternoon_break_start']) && !empty($cfg['afternoon_break_end'])) {
                            [$aStartH, $aStartM] = array_pad(array_map('intval', explode(':', $cfg['afternoon_break_start'])), 2, 0);
                            [$aEndH, $aEndM]     = array_pad(array_map('intval', explode(':', $cfg['afternoon_break_end'])), 2, 0);
                            $aBreakStart = (new DateTime($dayYmd))->setTime($aStartH, $aStartM, 0);
                            $aBreakEnd   = (new DateTime($dayYmd))->setTime($aEndH, $aEndM, 0);

                            if ($windowStart < $aBreakEnd && $windowEnd > $aBreakStart) {
                                $aOverlapStart = max($windowStart, $aBreakStart);
                                $aOverlapEnd   = min($windowEnd, $aBreakEnd);
                                $aOverlapHours = ($aOverlapEnd->getTimestamp() - $aOverlapStart->getTimestamp()) / 3600.0;
                                $hours = max(0.0, $hours - $aOverlapHours);
                            }
                        }

                        $totalHours += $hours;
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
        $cfg = self::getWorkingHoursConfig();
        $dailyHours = $cfg['daily_hours'] > 0 ? $cfg['daily_hours'] : self::STANDARD_DAILY_HOURS;

        $baseDays = floor($workingHours / $dailyHours);
        $remHours = fmod($workingHours, $dailyHours);

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
