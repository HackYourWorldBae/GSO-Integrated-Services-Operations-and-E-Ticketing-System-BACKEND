<?php

use App\Libraries\WorkCalendar;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Level 2 — Integration tests for dispatch scheduling rules.
 *
 * Verifies the EODB turnaround tiers (RA 11032: 3/7/21 working days) chain
 * correctly into WorkCalendar date arithmetic exactly as
 * DispatchController::assign computes implementation and target dates:
 * tier -> working days (clamped 1..31) -> target completion date.
 *
 * @internal
 */
final class SchedulingPipelineTest extends CIUnitTestCase
{
    /**
     * Mirror of the EODB tier mapping in DispatchController::assign.
     */
    private function eodbDaysForTier(string $tier): int
    {
        return match ($tier) {
            'moderate_7d' => 7,
            'complex_21d' => 21,
            default       => 3,
        };
    }

    /**
     * Mirror of the 1..31 working-day clamp in DispatchController::assign.
     */
    private function clampWorkingDays(int $days): int
    {
        return min(31, max(1, $days));
    }

    public function testEodbTiersMapToCorrectWorkingDays(): void
    {
        $this->assertSame(3, $this->eodbDaysForTier('simple_3d'));
        $this->assertSame(7, $this->eodbDaysForTier('moderate_7d'));
        $this->assertSame(21, $this->eodbDaysForTier('complex_21d'));
        $this->assertSame(3, $this->eodbDaysForTier('unknown-tier'));
        $this->assertSame(3, $this->eodbDaysForTier(''));
    }

    public function testWorkingDayClampBounds(): void
    {
        $this->assertSame(1, $this->clampWorkingDays(0));
        $this->assertSame(1, $this->clampWorkingDays(-4));
        $this->assertSame(31, $this->clampWorkingDays(99));
        $this->assertSame(15, $this->clampWorkingDays(15));
    }

    public function testSimpleTierTargetDateFromMonday(): void
    {
        // Monday Sep 21 2026 + 3 working days = Thursday Sep 24.
        $target = WorkCalendar::addWorkingDays(
            new DateTime('2026-09-21'),
            $this->eodbDaysForTier('simple_3d')
        );

        $this->assertSame('2026-09-24', $target->format('Y-m-d'));
    }

    public function testAllTiersProduceOrderedWorkingDayTargets(): void
    {
        $start   = new DateTime('2026-09-21'); // Monday
        $targets = [];

        foreach (['simple_3d', 'moderate_7d', 'complex_21d'] as $tier) {
            $days          = $this->clampWorkingDays($this->eodbDaysForTier($tier));
            $targets[$tier] = WorkCalendar::addWorkingDays($start, $days);
            $this->assertTrue(WorkCalendar::isWorkingDay($targets[$tier]));
        }

        $this->assertLessThan($targets['moderate_7d'], $targets['simple_3d']);
        $this->assertLessThan($targets['complex_21d'], $targets['moderate_7d']);
    }

    public function testTierDayCountMatchesCalendarWorkingDays(): void
    {
        $start = new DateTime('2026-09-21');

        foreach (['simple_3d' => 3, 'moderate_7d' => 7, 'complex_21d' => 21] as $tier => $days) {
            $target = WorkCalendar::addWorkingDays($start, $days);

            // Working days strictly after dispatch day up to (incl.) target == tier days.
            $counted = WorkCalendar::countWorkingDays(
                (clone $start)->modify('+1 day'),
                $target
            );
            $this->assertSame($days, $counted, "tier {$tier}");
        }
    }

    public function testEmergencySameDayDispatchCountsFromToday(): void
    {
        // Emergency jobs start immediately: target = today + tier days.
        $today  = new DateTime('2026-09-21');
        $target = WorkCalendar::addWorkingDays($today, $this->eodbDaysForTier('simple_3d'));

        $this->assertGreaterThan($today, $target);
        $this->assertSame(
            3,
            WorkCalendar::countWorkingDays((clone $today)->modify('+1 day'), $target)
        );
    }
}
