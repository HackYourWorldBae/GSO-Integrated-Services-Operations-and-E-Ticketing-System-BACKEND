<?php

namespace Tests\Unit;

use App\Libraries\WorkCalendar;
use App\Models\TicketModel;
use CodeIgniter\Test\CIUnitTestCase;
use DateTime;

/**
 * TicketNotationAndScheduleTest
 *
 * Validates staff notation support on tickets and schedule recalculation logic
 * for scheduled jobs and active jobs.
 */
final class TicketNotationAndScheduleTest extends CIUnitTestCase
{
    public function testTicketModelIncludesStaffNotationAllowedField(): void
    {
        $ticketModel = new TicketModel();
        // Use reflection to inspect protected $allowedFields
        $ref = new \ReflectionClass($ticketModel);
        $prop = $ref->getProperty('allowedFields');
        $prop->setAccessible(true);
        $fields = $prop->getValue($ticketModel);

        $this->assertContains('staff_notation', $fields, 'staff_notation must be present in TicketModel allowedFields');
        $this->assertContains('ssu_notation', $fields, 'ssu_notation must be present in TicketModel allowedFields');
    }

    public function testWorkCalendarCalculatesRescheduledCompletionDate(): void
    {
        // Pick a Monday (e.g. 2026-10-19)
        $startMonday = new DateTime('2026-10-19');
        // Adding 3 working days: Tuesday (1), Wednesday (2), Thursday (3) => 2026-10-22
        $targetDate = WorkCalendar::addWorkingDays($startMonday, 3);
        $this->assertSame('2026-10-22', $targetDate->format('Y-m-d'));

        // Adding 5 working days: Mon->Tue(1), Wed(2), Thu(3), Fri(4), Mon(5) => 2026-10-26 (skips Sat & Sun)
        $targetDate5 = WorkCalendar::addWorkingDays($startMonday, 5);
        $this->assertSame('2026-10-26', $targetDate5->format('Y-m-d'));
    }

    public function testSanitizationOfStaffNotation(): void
    {
        helper('sanitize');
        $input = '<script>alert("xss")</script> <b>Site inspection completed</b>. Parts arrive Friday.';
        $clean = sanitize_string($input);

        $this->assertStringNotContainsString('<script>', $clean);
        $this->assertStringContainsString('Site inspection completed', $clean);
    }
}
