<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Libraries\WorkCalendar;
use App\Models\AccountActivityLogModel;
use App\Models\CatalogMaterialModel;
use App\Models\SpecialHolidayModel;
use App\Models\SystemSettingModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * OperationsHubController
 *
 * Central operational editing hub for the Executive Director:
 * 1. Commonly Used Materials Catalog:
 *    - Strict separation by Sub-Unit (FGMU vs LEAU)
 *    - Category grouping, default unit measurement, default price
 * 2. Special Non-Working Holiday Dates:
 *    - Holiday registry for dynamic turnaround duration & SLA counting
 * 3. Operational Working Hours:
 *    - Daily work start time, work end time, lunch break window, daily hours
 */
class OperationsHubController extends BaseController
{
    private CatalogMaterialModel $materialModel;
    private SpecialHolidayModel $holidayModel;
    private SystemSettingModel $settingModel;
    private AccountActivityLogModel $activityLogModel;

    public function __construct()
    {
        $this->materialModel    = new CatalogMaterialModel();
        $this->holidayModel     = new SpecialHolidayModel();
        $this->settingModel     = new SystemSettingModel();
        $this->activityLogModel = new AccountActivityLogModel();
    }

    /**
     * Safely record an administrative activity log without interrupting core operations.
     */
    private function logAction(string $eventType, string $details): void
    {
        try {
            $currentUserId = $this->currentUserId() ?? 'system';
            $this->activityLogModel->log(
                $currentUserId,
                $eventType,
                $details,
                $this->request->getIPAddress()
            );
        } catch (\Throwable $e) {
            log_message('error', '[OperationsHubController::logAction] Audit log write failed: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 1. Unified Operations Configuration
    // =========================================================================

    /**
     * Retrieve the consolidated operational config (working hours, holidays, catalog stats).
     * GET /api/v1/operations/config
     */
    public function getConfig(): ResponseInterface
    {
        // Auto-seed baseline catalogs if clean installation
        $this->materialModel->seedDefaultsIfEmpty();
        $this->holidayModel->seedDefaultsIfEmpty();

        $workingHours = $this->buildWorkingHoursPayload();

        $holidays = $this->holidayModel->getActiveHolidays();
        $allMaterials = $this->materialModel->getByUnit('ALL', true);

        $fgmuCount = count(array_filter($allMaterials, fn($m) => ($m['unit_code'] ?? '') === 'FGMU'));
        $leauCount = count(array_filter($allMaterials, fn($m) => ($m['unit_code'] ?? '') === 'LEAU'));

        return $this->successResponse('Operations configuration retrieved.', [
            'working_hours' => $workingHours,
            'holidays'      => $holidays,
            'summary'       => [
                'total_materials' => count($allMaterials),
                'fgmu_materials'  => $fgmuCount,
                'leau_materials'  => $leauCount,
                'total_holidays'  => count($holidays),
            ],
        ]);
    }

    // =========================================================================
    // 2. Materials Catalog Management (FGMU & LEAU Separated)
    // =========================================================================

    /**
     * List materials, filterable by sub-unit (FGMU, LEAU, ALL) and category.
     * GET /api/v1/operations/materials
     */
    public function getMaterials(): ResponseInterface
    {
        $this->materialModel->seedDefaultsIfEmpty();

        $unit       = strtoupper(trim($this->request->getGet('unit') ?? 'ALL'));
        $category   = trim($this->request->getGet('category') ?? '');
        $search     = trim($this->request->getGet('search') ?? '');
        $activeOnly = $this->request->getGet('active_only') !== '0';

        $materials = $this->materialModel->getByUnit($unit, $activeOnly);

        if (!empty($category)) {
            $materials = array_values(array_filter($materials, function ($item) use ($category) {
                return strcasecmp($item['category'] ?? '', $category) === 0;
            }));
        }

        if (!empty($search)) {
            $term = strtolower($search);
            $materials = array_values(array_filter($materials, function ($item) use ($term) {
                return str_contains(strtolower($item['name'] ?? ''), $term) ||
                       str_contains(strtolower($item['category'] ?? ''), $term);
            }));
        }

        // Categorize unique categories for filters
        $fgmuCategories = array_values(array_unique(array_column(
            array_filter($materials, fn($m) => ($m['unit_code'] ?? '') === 'FGMU'),
            'category'
        )));
        sort($fgmuCategories);

        $leauCategories = array_values(array_unique(array_column(
            array_filter($materials, fn($m) => ($m['unit_code'] ?? '') === 'LEAU'),
            'category'
        )));
        sort($leauCategories);

        return $this->successResponse('Materials catalog retrieved.', [
            'materials'  => $materials,
            'categories' => [
                'FGMU' => $fgmuCategories,
                'LEAU' => $leauCategories,
            ],
            'total'      => count($materials),
        ]);
    }

    /**
     * Add a new material to the catalog.
     * POST /api/v1/operations/materials
     */
    public function createMaterial(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        $unitCode     = strtoupper(trim($body['unit_code'] ?? ''));
        $category     = sanitize_string($body['category'] ?? 'General');
        if (empty($category)) {
            $category = 'General';
        }
        $name         = sanitize_string($body['name'] ?? '');
        $unit         = sanitize_string($body['unit'] ?? 'pcs');
        $defaultPrice = max(0.00, (float) ($body['default_price'] ?? 0));
        $isActive     = isset($body['is_active']) ? ((int) $body['is_active'] ? 1 : 0) : 1;

        if (!in_array($unitCode, ['FGMU', 'LEAU'], true)) {
            return $this->errorResponse('Sub-unit must be either FGMU or LEAU.');
        }

        if (empty($name)) {
            return $this->errorResponse('Material name is required.');
        }

        // Check duplicate name within the same unit
        $duplicate = $this->materialModel->where('unit_code', $unitCode)
                                         ->where('name', $name)
                                         ->first();

        if ($duplicate) {
            return $this->errorResponse("A material with name '{$name}' already exists in {$unitCode}.");
        }

        $id = $this->materialModel->insert([
            'unit_code'     => $unitCode,
            'category'      => $category,
            'name'          => $name,
            'unit'          => $unit ?: 'pcs',
            'default_price' => $defaultPrice,
            'is_active'     => $isActive,
        ]);

        if (!$id) {
            return $this->errorResponse('Failed to create material record.', $this->materialModel->errors());
        }

        $created = $this->materialModel->find($id);

        $this->logAction('Material Created', "Added '{$name}' ({$unitCode} - {$category}) to central materials catalog.");

        return $this->successResponse("Material '{$name}' successfully added to {$unitCode}.", $created, ResponseInterface::HTTP_CREATED);
    }

    /**
     * Update an existing material in the catalog.
     * PATCH /api/v1/operations/materials/(:num)
     */
    public function updateMaterial(int $id): ResponseInterface
    {
        $material = $this->materialModel->find($id);
        if (!$material) {
            return $this->notFoundResponse('Material');
        }

        $body = $this->request->getJSON(true) ?? [];
        $updateData = [];

        if (isset($body['unit_code'])) {
            $unitCode = strtoupper(trim($body['unit_code']));
            if (!in_array($unitCode, ['FGMU', 'LEAU'], true)) {
                return $this->errorResponse('Sub-unit must be either FGMU or LEAU.');
            }
            $updateData['unit_code'] = $unitCode;
        }

        if (isset($body['category'])) {
            $cat = sanitize_string($body['category']);
            $updateData['category'] = $cat ?: 'General';
        }

        if (isset($body['name'])) {
            $newName = sanitize_string($body['name']);
            if (empty($newName)) {
                return $this->errorResponse('Material name cannot be empty.');
            }
            $unitToCheck = $updateData['unit_code'] ?? $material['unit_code'];
            $existing = $this->materialModel->where('unit_code', $unitToCheck)
                                           ->where('name', $newName)
                                           ->where('id !=', $id)
                                           ->first();
            if ($existing) {
                return $this->errorResponse("A material with name '{$newName}' already exists in {$unitToCheck}.");
            }
            $updateData['name'] = $newName;
        }

        if (isset($body['unit'])) {
            $updateData['unit'] = sanitize_string($body['unit']);
        }

        if (isset($body['default_price'])) {
            $updateData['default_price'] = max(0.00, (float) $body['default_price']);
        }

        if (isset($body['is_active'])) {
            $updateData['is_active'] = (int) $body['is_active'] ? 1 : 0;
        }

        if (empty($updateData)) {
            return $this->errorResponse('No valid fields provided for update.');
        }

        $this->materialModel->update($id, $updateData);
        $updated = $this->materialModel->find($id);

        $this->logAction('Material Updated', "Updated material #{$id} ({$updated['name']} - {$updated['unit_code']}) in central catalog.");

        return $this->successResponse("Material '{$updated['name']}' updated successfully.", $updated);
    }

    /**
     * Delete a material from the catalog.
     * DELETE /api/v1/operations/materials/(:num)
     */
    public function deleteMaterial(int $id): ResponseInterface
    {
        $material = $this->materialModel->find($id);
        if (!$material) {
            return $this->notFoundResponse('Material');
        }

        $this->materialModel->delete($id);

        $this->logAction('Material Deleted', "Removed '{$material['name']}' ({$material['unit_code']}) from central materials catalog.");

        return $this->successResponse("Material '{$material['name']}' successfully removed from catalog.");
    }

    /**
     * Seed or restore baseline materials.
     * POST /api/v1/operations/materials/seed-defaults
     */
    public function seedDefaults(): ResponseInterface
    {
        $seeded = $this->materialModel->seedDefaultsIfEmpty();

        $this->logAction('Catalog Defaults Seeded', "Baseline FGMU and LEAU material library checked/seeded ({$seeded} items).");

        return $this->successResponse("Baseline materials catalog verified. Total items: {$seeded}.");
    }

    // =========================================================================
    // 3. Non-Working Days Management (Special Non-Working & University Days)
    // =========================================================================

    /**
     * List all non-working day dates.
     * GET /api/v1/operations/holidays
     */
    public function getHolidays(): ResponseInterface
    {
        $this->holidayModel->seedDefaultsIfEmpty();

        // Ensure deprecated regular and local_suspension types are removed from the registry
        try {
            $this->holidayModel->whereIn('type', ['regular', 'local_suspension'])->delete();
        } catch (\Throwable $e) {
            // ignore if already clean
        }

        $activeOnly = $this->request->getGet('active_only') === '1';
        $builder = $this->holidayModel->orderBy('holiday_date', 'ASC');
        if ($activeOnly) {
            $builder->where('is_active', 1);
        }
        $holidays = $builder->findAll();

        return $this->successResponse('Non-working days retrieved.', [
            'holidays' => $holidays,
            'total'    => count($holidays),
        ]);
    }

    /**
     * Register a new non-working day date or date range (working break).
     * POST /api/v1/operations/holidays
     */
    public function createHoliday(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        $holidayDate  = trim($body['holiday_date'] ?? $body['start_date'] ?? '');
        $endDate      = trim($body['end_date'] ?? '');
        $name         = sanitize_string($body['name'] ?? '');
        $type         = trim($body['type'] ?? 'special_non_working');
        if (!in_array($type, ['special_non_working', 'institutional'], true)) {
            $type = 'special_non_working';
        }
        $isRecurring  = !empty($body['is_recurring']) ? 1 : 0;
        $isActive     = isset($body['is_active']) ? ((int) $body['is_active'] ? 1 : 0) : 1;
        $notes        = sanitize_string($body['notes'] ?? '');
        $skipWeekends = !isset($body['skip_weekends']) || !empty($body['skip_weekends']);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) {
            return $this->errorResponse('Valid date in YYYY-MM-DD format is required.');
        }

        if (empty($name)) {
            return $this->errorResponse('Non-working day name/description is required.');
        }

        // Multi-day Date Range (Working Break / Institutional Period)
        if (!empty($endDate) && $endDate !== $holidayDate) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                return $this->errorResponse('Valid end date in YYYY-MM-DD format is required.');
            }

            if ($endDate < $holidayDate) {
                return $this->errorResponse('End date must be on or after start date.');
            }

            $start = new \DateTime($holidayDate);
            $end   = new \DateTime($endDate);
            $diff  = $start->diff($end)->days;

            if ($diff > 60) {
                return $this->errorResponse('Date range cannot exceed 60 days.');
            }

            $current = clone $start;
            $createdRecords = [];

            while ($current <= $end) {
                $curDate = $current->format('Y-m-d');
                $dayOfWeek = (int) $current->format('w'); // 0 = Sunday, 6 = Saturday

                // If skipWeekends is enabled, bypass Saturdays and Sundays
                if ($skipWeekends && ($dayOfWeek === 0 || $dayOfWeek === 6)) {
                    $current->modify('+1 day');
                    continue;
                }

                $existing = $this->holidayModel->where('holiday_date', $curDate)->first();
                if ($existing) {
                    $createdRecords[] = $existing;
                } else {
                    $id = $this->holidayModel->insert([
                        'holiday_date' => $curDate,
                        'name'         => $name,
                        'type'         => $type,
                        'is_recurring' => $isRecurring,
                        'is_active'    => $isActive,
                        'notes'        => $notes,
                    ]);

                    if ($id) {
                        $createdRecords[] = $this->holidayModel->find($id);
                    }
                }

                $current->modify('+1 day');
            }

            WorkCalendar::clearCache();

            $totalAdded = count($createdRecords);
            $this->logAction('Working Break Added', "Registered working break '{$name}' from {$holidayDate} to {$endDate} ({$totalAdded} dates).");

            return $this->successResponse(
                "Registered {$totalAdded} non-working break days for '{$name}' ({$holidayDate} to {$endDate}).",
                $createdRecords,
                ResponseInterface::HTTP_CREATED
            );
        }

        // Single Date Handling
        $existing = $this->holidayModel->where('holiday_date', $holidayDate)->first();
        if ($existing) {
            return $this->errorResponse("A non-working day entry for {$holidayDate} ('{$existing['name']}') already exists.");
        }

        $id = $this->holidayModel->insert([
            'holiday_date' => $holidayDate,
            'name'         => $name,
            'type'         => $type,
            'is_recurring' => $isRecurring,
            'is_active'    => $isActive,
            'notes'        => $notes,
        ]);

        if (!$id) {
            return $this->errorResponse('Failed to record non-working day.', $this->holidayModel->errors());
        }

        WorkCalendar::clearCache();

        $this->logAction('Non-Working Day Added', "Registered non-working day '{$name}' on {$holidayDate}.");

        $created = $this->holidayModel->find($id);
        return $this->successResponse("Non-working day '{$name}' on {$holidayDate} registered successfully.", $created, ResponseInterface::HTTP_CREATED);
    }

    /**
     * Update an existing non-working day entry.
     * PATCH /api/v1/operations/holidays/(:num)
     */
    public function updateHoliday(int $id): ResponseInterface
    {
        $holiday = $this->holidayModel->find($id);
        if (!$holiday) {
            return $this->notFoundResponse('Non-Working Day');
        }

        $body = $this->request->getJSON(true) ?? [];
        $updateData = [];

        if (isset($body['holiday_date'])) {
            $hDate = trim($body['holiday_date']);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hDate)) {
                return $this->errorResponse('Invalid date format (must be YYYY-MM-DD).');
            }
            $existing = $this->holidayModel->where('holiday_date', $hDate)->where('id !=', $id)->first();
            if ($existing) {
                return $this->errorResponse("Another entry already exists for {$hDate}.");
            }
            $updateData['holiday_date'] = $hDate;
        }

        if (isset($body['name'])) {
            $name = sanitize_string($body['name']);
            if (empty($name)) {
                return $this->errorResponse('Non-working day name cannot be empty.');
            }
            $updateData['name'] = $name;
        }

        if (isset($body['type'])) {
            $type = trim($body['type']);
            if (in_array($type, ['special_non_working', 'institutional'], true)) {
                $updateData['type'] = $type;
            }
        }

        if (isset($body['is_recurring'])) {
            $updateData['is_recurring'] = !empty($body['is_recurring']) ? 1 : 0;
        }

        if (isset($body['is_active'])) {
            $updateData['is_active'] = (int) $body['is_active'] ? 1 : 0;
        }

        if (isset($body['notes'])) {
            $updateData['notes'] = sanitize_string($body['notes']);
        }

        if (empty($updateData)) {
            return $this->errorResponse('No valid fields provided for update.');
        }

        $this->holidayModel->update($id, $updateData);
        WorkCalendar::clearCache();

        $updated = $this->holidayModel->find($id);

        $this->logAction('Non-Working Day Updated', "Updated non-working day #{$id} ({$updated['name']} on {$updated['holiday_date']}).");

        return $this->successResponse("Non-working day entry '{$updated['name']}' updated.", $updated);
    }

    /**
     * Delete a non-working day entry.
     * DELETE /api/v1/operations/holidays/(:num)
     */
    public function deleteHoliday(int $id): ResponseInterface
    {
        $holiday = $this->holidayModel->find($id);
        if (!$holiday) {
            return $this->notFoundResponse('Non-Working Day');
        }

        $this->holidayModel->delete($id);
        WorkCalendar::clearCache();

        $this->logAction('Non-Working Day Deleted', "Deleted non-working day '{$holiday['name']}' on {$holiday['holiday_date']}.");

        return $this->successResponse("Non-working day entry '{$holiday['name']}' deleted.");
    }

    // =========================================================================
    // 4. Working Hours & SLA Duration Configuration
    // =========================================================================

    /**
     * Helper to assemble the complete operational schedule & working hours configuration.
     */
    private function buildWorkingHoursPayload(): array
    {
        return [
            'work_start_time'            => $this->settingModel->getSetting('work_start_time', '08:00'),
            'work_end_time'              => $this->settingModel->getSetting('work_end_time', '17:00'),
            'lunch_start_time'           => $this->settingModel->getSetting('lunch_start_time', '12:00'),
            'lunch_end_time'             => $this->settingModel->getSetting('lunch_end_time', '13:00'),
            'standard_daily_hours'       => (float) $this->settingModel->getSetting('standard_daily_hours', '8.0'),
            'daily_continuation_enabled' => (bool) (int) $this->settingModel->getSetting('daily_continuation_enabled', '1'),
            'continuation_start_time'    => $this->settingModel->getSetting('continuation_start_time', '08:00'),
            'continuation_cutoff_time'   => $this->settingModel->getSetting('continuation_cutoff_time', '17:00'),
            'lunch_break_enabled'        => (bool) (int) $this->settingModel->getSetting('lunch_break_enabled', '1'),
            'morning_break_enabled'      => (bool) (int) $this->settingModel->getSetting('morning_break_enabled', '0'),
            'morning_break_start'        => $this->settingModel->getSetting('morning_break_start', '10:00'),
            'morning_break_end'          => $this->settingModel->getSetting('morning_break_end', '10:15'),
            'afternoon_break_enabled'    => (bool) (int) $this->settingModel->getSetting('afternoon_break_enabled', '0'),
            'afternoon_break_start'      => $this->settingModel->getSetting('afternoon_break_start', '15:00'),
            'afternoon_break_end'        => $this->settingModel->getSetting('afternoon_break_end', '15:15'),
            'exclude_weekends'           => (bool) (int) $this->settingModel->getSetting('exclude_weekends', '1'),
            'exclude_saturday'           => (bool) (int) $this->settingModel->getSetting('exclude_saturday', '1'),
            'exclude_sunday'             => (bool) (int) $this->settingModel->getSetting('exclude_sunday', '1'),
        ];
    }

    /**
     * Get operational working hours and schedule settings.
     * GET /api/v1/operations/working-hours
     */
    public function getWorkingHours(): ResponseInterface
    {
        return $this->successResponse('Working hours retrieved.', $this->buildWorkingHoursPayload());
    }

    /**
     * Update operational working hours, daily job continuation, time breaks, and weekend exclusions.
     * POST /api/v1/operations/working-hours
     */
    public function updateWorkingHours(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        $timeRegex = '/^([01]\d|2[0-3]):([0-5]\d)$/';

        // 1. Core Work Hours
        $startTime  = trim($body['work_start_time'] ?? '08:00');
        $endTime    = trim($body['work_end_time'] ?? '17:00');
        $dailyHours = (float) ($body['standard_daily_hours'] ?? 8.0);

        if (!preg_match($timeRegex, $startTime) || !preg_match($timeRegex, $endTime)) {
            return $this->errorResponse('Work start time and end time must be in 24-hour format (e.g. 08:00, 17:00).');
        }
        if ($startTime >= $endTime) {
            return $this->errorResponse('Work start time must be earlier than work end time.');
        }
        if ($dailyHours <= 0 || $dailyHours > 24) {
            return $this->errorResponse('Standard daily hours must be between 1 and 24 hours.');
        }

        // 2. Automatic Daily Continuation of Jobs
        $continuationEnabled = isset($body['daily_continuation_enabled']) ? ((int) $body['daily_continuation_enabled'] ? 1 : 0) : 1;
        $continuationStart   = trim($body['continuation_start_time'] ?? $startTime);
        $continuationCutoff  = trim($body['continuation_cutoff_time'] ?? $endTime);

        if (!preg_match($timeRegex, $continuationStart) || !preg_match($timeRegex, $continuationCutoff)) {
            return $this->errorResponse('Job continuation start time and cutoff time must be in 24-hour format (e.g. 08:00, 17:00).');
        }
        if ($continuationStart >= $continuationCutoff) {
            return $this->errorResponse('Job continuation resume time must be earlier than the evening cutoff time.');
        }

        // 3. Time Breaks
        $lunchEnabled = isset($body['lunch_break_enabled']) ? ((int) $body['lunch_break_enabled'] ? 1 : 0) : 1;
        $lunchStart   = trim($body['lunch_start_time'] ?? '12:00');
        $lunchEnd     = trim($body['lunch_end_time'] ?? '13:00');

        if (!preg_match($timeRegex, $lunchStart) || !preg_match($timeRegex, $lunchEnd)) {
            return $this->errorResponse('Lunch break times must be in 24-hour format (e.g. 12:00, 13:00).');
        }
        if ($lunchStart >= $lunchEnd) {
            return $this->errorResponse('Lunch start time must be earlier than lunch end time.');
        }
        if ($lunchStart < $startTime || $lunchEnd > $endTime) {
            return $this->errorResponse('Lunch break must fall within standard daily working hours.');
        }

        $mornEnabled = isset($body['morning_break_enabled']) ? ((int) $body['morning_break_enabled'] ? 1 : 0) : 0;
        $mornStart   = trim($body['morning_break_start'] ?? '10:00');
        $mornEnd     = trim($body['morning_break_end'] ?? '10:15');

        if ($mornEnabled) {
            if (!preg_match($timeRegex, $mornStart) || !preg_match($timeRegex, $mornEnd)) {
                return $this->errorResponse('Morning break times must be in 24-hour format (e.g. 10:00, 10:15).');
            }
            if ($mornStart >= $mornEnd) {
                return $this->errorResponse('Morning break start must be earlier than break end time.');
            }
        }

        $aftEnabled = isset($body['afternoon_break_enabled']) ? ((int) $body['afternoon_break_enabled'] ? 1 : 0) : 0;
        $aftStart   = trim($body['afternoon_break_start'] ?? '15:00');
        $aftEnd     = trim($body['afternoon_break_end'] ?? '15:15');

        if ($aftEnabled) {
            if (!preg_match($timeRegex, $aftStart) || !preg_match($timeRegex, $aftEnd)) {
                return $this->errorResponse('Afternoon break times must be in 24-hour format (e.g. 15:00, 15:15).');
            }
            if ($aftStart >= $aftEnd) {
                return $this->errorResponse('Afternoon break start must be earlier than break end time.');
            }
        }

        // 4. Weekend Exclusion Policy
        $excludeWeekends = isset($body['exclude_weekends']) ? ((int) $body['exclude_weekends'] ? 1 : 0) : 1;
        $excludeSaturday = isset($body['exclude_saturday']) ? ((int) $body['exclude_saturday'] ? 1 : 0) : 1;
        $excludeSunday   = isset($body['exclude_sunday']) ? ((int) $body['exclude_sunday'] ? 1 : 0) : 1;

        // Persist Settings
        $this->settingModel->setSetting('work_start_time', $startTime, 'Daily operational starting time (HH:mm)');
        $this->settingModel->setSetting('work_end_time', $endTime, 'Daily operational ending time (HH:mm)');
        $this->settingModel->setSetting('standard_daily_hours', (string) $dailyHours, 'Standard net operational hours per day');

        $this->settingModel->setSetting('daily_continuation_enabled', (string) $continuationEnabled, 'Enable automatic multi-day job continuation');
        $this->settingModel->setSetting('continuation_start_time', $continuationStart, 'Morning auto-continuation resume time (HH:mm)');
        $this->settingModel->setSetting('continuation_cutoff_time', $continuationCutoff, 'Evening auto-continuation cutoff time (HH:mm)');

        $this->settingModel->setSetting('lunch_break_enabled', (string) $lunchEnabled, 'Enable lunch break deduction');
        $this->settingModel->setSetting('lunch_start_time', $lunchStart, 'Daily lunch break start time (HH:mm)');
        $this->settingModel->setSetting('lunch_end_time', $lunchEnd, 'Daily lunch break end time (HH:mm)');

        $this->settingModel->setSetting('morning_break_enabled', (string) $mornEnabled, 'Enable morning rest break deduction');
        $this->settingModel->setSetting('morning_break_start', $mornStart, 'Morning break start time (HH:mm)');
        $this->settingModel->setSetting('morning_break_end', $mornEnd, 'Morning break end time (HH:mm)');

        $this->settingModel->setSetting('afternoon_break_enabled', (string) $aftEnabled, 'Enable afternoon rest break deduction');
        $this->settingModel->setSetting('afternoon_break_start', $aftStart, 'Afternoon break start time (HH:mm)');
        $this->settingModel->setSetting('afternoon_break_end', $aftEnd, 'Afternoon break end time (HH:mm)');

        $this->settingModel->setSetting('exclude_weekends', (string) $excludeWeekends, 'Exclude weekends from job deadlines and turnaround calculations');
        $this->settingModel->setSetting('exclude_saturday', (string) $excludeSaturday, 'Exclude Saturdays from working day calculations');
        $this->settingModel->setSetting('exclude_sunday', (string) $excludeSunday, 'Exclude Sundays from working day calculations');

        WorkCalendar::clearCache();

        $this->logAction('Working Hours & Schedule Updated', "Operational schedule updated: Hours {$startTime}-{$endTime}, Continuation: {$continuationStart}-{$continuationCutoff}, Weekends Excluded: " . ($excludeWeekends ? 'Yes' : 'No') . '.');

        return $this->successResponse('Operational schedule, job continuation, time breaks, and weekend policies successfully updated.', $this->buildWorkingHoursPayload());
    }
}
