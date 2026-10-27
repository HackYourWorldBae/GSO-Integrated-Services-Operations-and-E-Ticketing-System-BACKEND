<?php

namespace Tests\Unit;

use App\Libraries\WorkCalendar;
use App\Models\CatalogMaterialModel;
use App\Models\SpecialHolidayModel;
use CodeIgniter\Test\CIUnitTestCase;
use DateTime;

/**
 * OperationsHubTest
 *
 * Validates the core logic of the Operations Central Hub:
 * - Material catalog partition by unit (FGMU vs LEAU)
 * - Special non-working holidays detection
 * - Dynamic work calendar integration
 */
final class OperationsHubTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkCalendar::clearCache();
    }

    protected function tearDown(): void
    {
        WorkCalendar::clearCache();
        parent::tearDown();
    }

    public function testDefaultCatalogSeparatesFgmuAndLeau(): void
    {
        $materialModel = new CatalogMaterialModel();
        $fgmuMaterials = $materialModel->getByUnit('FGMU');
        $leauMaterials = $materialModel->getByUnit('LEAU');
        $allMaterials  = $materialModel->getByUnit('ALL');

        $this->assertNotEmpty($fgmuMaterials, 'FGMU materials should not be empty');
        $this->assertNotEmpty($leauMaterials, 'LEAU materials should not be empty');
        $this->assertGreaterThan(20, count($fgmuMaterials), 'FGMU should have robust catalog');
        $this->assertGreaterThan(15, count($leauMaterials), 'LEAU should have robust catalog');

        // Verify strict separation
        foreach ($fgmuMaterials as $m) {
            $this->assertSame('FGMU', $m['unit_code']);
            $this->assertNotEmpty($m['category']);
            $this->assertNotEmpty($m['name']);
            $this->assertNotEmpty($m['unit']);
        }

        foreach ($leauMaterials as $m) {
            $this->assertSame('LEAU', $m['unit_code']);
            $this->assertNotEmpty($m['category']);
            $this->assertNotEmpty($m['name']);
            $this->assertNotEmpty($m['unit']);
        }

        $this->assertSame(count($fgmuMaterials) + count($leauMaterials), count($allMaterials));
    }

    public function testSpecialHolidaysRecognizesRecurringAndExactDates(): void
    {
        $holidayModel = new SpecialHolidayModel();
        
        // 2026-11-02 (All Souls' Day) is in DEFAULT_SPECIAL_HOLIDAYS
        $allSouls = new DateTime('2026-11-02');
        $this->assertTrue($holidayModel->isSpecialHoliday($allSouls));

        // Ordinary day should not be a special holiday
        $regularDay = new DateTime('2026-11-04');
        $this->assertFalse($holidayModel->isSpecialHoliday($regularDay));
    }

    public function testWorkCalendarIntegratesSpecialHolidays(): void
    {
        // 2026-11-02 is Monday, which is a special non-working holiday
        $allSoulsMonday = new DateTime('2026-11-02');
        $this->assertTrue(WorkCalendar::isHoliday($allSoulsMonday));
        $this->assertFalse(WorkCalendar::isWorkingDay($allSoulsMonday));

        // 2026-11-03 is Tuesday (regular working day)
        $tuesday = new DateTime('2026-11-03');
        $this->assertTrue(WorkCalendar::isWorkingDay($tuesday));
    }

    public function testWorkCalendarWorkingHoursConfigStructure(): void
    {
        $config = WorkCalendar::getWorkingHoursConfig();
        $this->assertIsArray($config);
        $this->assertArrayHasKey('start_hour', $config);
        $this->assertArrayHasKey('end_hour', $config);
        $this->assertArrayHasKey('lunch_start', $config);
        $this->assertArrayHasKey('lunch_end', $config);
        $this->assertArrayHasKey('daily_hours', $config);
        $this->assertSame(8, $config['start_hour']);
        $this->assertSame(17, $config['end_hour']);
    }

    public function testAccountActivityLogShorthandLogMethod(): void
    {
        $logModel = new \App\Models\AccountActivityLogModel();
        // Calling log should execute without throwing an undefined method error
        $result = $logModel->log(
            'system-test',
            'TEST_EVENT',
            'Testing holiday activity logger',
            '127.0.0.1'
        );
        $this->assertIsBool($result);
    }

    public function testHolidayModelInsertAndRetrieve(): void
    {
        if (!class_exists('SQLite3')) {
            $this->markTestSkipped('SQLite3 extension not installed in CLI environment.');
        }

        $holidayModel = new SpecialHolidayModel();
        $testDate = '2099-07-15';
        
        // Cleanup if previously present
        $holidayModel->where('holiday_date', $testDate)->delete();

        $insertId = $holidayModel->insert([
            'holiday_date' => $testDate,
            'name'         => 'Test Holiday',
            'type'         => 'special_non_working',
            'is_recurring' => 0,
            'is_active'    => 1,
            'notes'        => 'Temporary test holiday entry',
        ]);

        $this->assertNotFalse($insertId, 'Holiday should insert successfully: ' . json_encode($holidayModel->errors()));

        $found = $holidayModel->find($insertId);
        $this->assertNotNull($found);
        $this->assertSame('Test Holiday', $found['name']);
        $this->assertSame($testDate, $found['holiday_date']);

        // Check isSpecialHoliday detects it
        $this->assertTrue($holidayModel->isSpecialHoliday(new DateTime($testDate)));

        // Clean up
        $holidayModel->delete($insertId);
    }
}

