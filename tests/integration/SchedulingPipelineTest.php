<?php

use App\Libraries\WorkCalendar;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Level 2 — Integration tests for dispatch scheduling rules.
 *
 * Verifies dispatch scheduling working-day duration and WorkCalendar
 * target date arithmetic as computed in DispatchController::assign:
 * working days (clamped 1..31) -> target completion date.
 *
 * @internal
 */
final class SchedulingPipelineTest extends CIUnitTestCase
{
    /**
     * Mirror of the 1..31 working-day clamp in DispatchController::assign.
     */
    private function clampWorkingDays(int $days): int
    {
        return min(31, max(1, $days));
    }

    public function testWorkingDayClampBounds(): void
    {
        $this->assertSame(1, $this->clampWorkingDays(0));
        $this->assertSame(1, $this->clampWorkingDays(-4));
        $this->assertSame(31, $this->clampWorkingDays(99));
        $this->assertSame(15, $this->clampWorkingDays(15));
    }

    public function testTargetDateFromMonday(): void
    {
        // Monday Sep 21 2026 + 3 working days = Thursday Sep 24.
        $target = WorkCalendar::addWorkingDays(
            new DateTime('2026-09-21'),
            3
        );

        $this->assertSame('2026-09-24', $target->format('Y-m-d'));
    }

    public function testWorkingDayTargetsRespectWorkCalendar(): void
    {
        $start = new DateTime('2026-09-21'); // Monday

        foreach ([1, 3, 7, 14, 21, 31] as $days) {
            $clamped = $this->clampWorkingDays($days);
            $target = WorkCalendar::addWorkingDays($start, $clamped);
            $this->assertTrue(WorkCalendar::isWorkingDay($target));
        }
    }

    public function testDayCountMatchesCalendarWorkingDays(): void
    {
        $start = new DateTime('2026-09-21');

        foreach ([1, 3, 7, 14, 21] as $days) {
            $target = WorkCalendar::addWorkingDays($start, $days);

            // Working days strictly after dispatch day up to (incl.) target == days.
            $counted = WorkCalendar::countWorkingDays(
                (clone $start)->modify('+1 day'),
                $target
            );
            $this->assertSame($days, $counted, "days {$days}");
        }
    }

    public function testEmergencySameDayDispatchCountsFromToday(): void
    {
        // Emergency jobs start immediately: target = today + days.
        $today  = new DateTime('2026-09-21');
        $target = WorkCalendar::addWorkingDays($today, 3);

        $this->assertGreaterThan($today, $target);
        $this->assertSame(
            3,
            WorkCalendar::countWorkingDays((clone $today)->modify('+1 day'), $target)
        );
    }
}
