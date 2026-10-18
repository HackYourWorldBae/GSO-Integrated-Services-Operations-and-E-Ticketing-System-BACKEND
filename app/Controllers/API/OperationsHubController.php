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

        $workingHours = [
            'work_start_time'      => $this->settingModel->getSetting('work_start_time', '08:00'),
            'work_end_time'        => $this->settingModel->getSetting('work_end_time', '17:00'),
            'lunch_start_time'     => $this->settingModel->getSetting('lunch_start_time', '12:00'),
            'lunch_end_time'       => $this->settingModel->getSetting('lunch_end_time', '13:00'),
            'standard_daily_hours' => (float) $this->settingModel->getSetting('standard_daily_hours', '8.0'),
        ];

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

        $currentUserId = $this->currentUserId() ?? 'system';
        $this->activityLogModel->log(
            $currentUserId,
            'Material Created',
            "Added '{$name}' ({$unitCode} - {$category}) to central materials catalog.",
            $this->request->getIPAddress()
        );

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

        $currentUserId = $this->currentUserId() ?? 'system';
        $this->activityLogModel->log(
            $currentUserId,
            'Material Updated',
            "Updated material #{$id} ({$updated['name']} - {$updated['unit_code']}) in central catalog.",
            $this->request->getIPAddress()
        );

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

        $currentUserId = $this->currentUserId() ?? 'system';
        $this->activityLogModel->log(
            $currentUserId,
            'Material Deleted',
            "Removed '{$material['name']}' ({$material['unit_code']}) from central materials catalog.",
            $this->request->getIPAddress()
        );

        return $this->successResponse("Material '{$material['name']}' successfully removed from catalog.");
    }

    /**
     * Seed or restore baseline materials.
     * POST /api/v1/operations/materials/seed-defaults
     */
    public function seedDefaults(): ResponseInterface
    {
        $seeded = $this->materialModel->seedDefaultsIfEmpty();

        $currentUserId = $this->currentUserId() ?? 'system';
        $this->activityLogModel->log(
            $currentUserId,
            'Catalog Defaults Seeded',
            "Baseline FGMU and LEAU material library checked/seeded ({$seeded} items).",
            $this->request->getIPAddress()
        );

        return $this->successResponse("Baseline materials catalog verified. Total items: {$seeded}.");
    }

    // =========================================================================
    // 3. Special Non-Working Holidays Management
    // =========================================================================

    /**
     * List all special non-working holiday dates.
     * GET /api/v1/operations/holidays
     */
    public function getHolidays(): ResponseInterface
    {
        $this->holidayModel->seedDefaultsIfEmpty();

        $activeOnly = $this->request->getGet('active_only') === '1';
        $builder = $this->holidayModel->orderBy('holiday_date', 'ASC');
        if ($activeOnly) {
            $builder->where('is_active', 1);
        }
        $holidays = $builder->findAll();

        return $this->successResponse('Special holidays retrieved.', [
            'holidays' => $holidays,
            'total'    => count($holidays),
        ]);
    }

    /**
     * Register a new special non-working holiday date.
     * POST /api/v1/operations/holidays
     */
    public function createHoliday(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        $holidayDate = trim($body['holiday_date'] ?? '');
        $name        = sanitize_string($body['name'] ?? '');
        $type        = trim($body['type'] ?? 'special_non_working');
        $isRecurring = !empty($body['is_recurring']) ? 1 : 0;
        $isActive    = isset($body['is_active']) ? ((int) $body['is_active'] ? 1 : 0) : 1;
        $notes       = sanitize_string($body['notes'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) {
            return $this->errorResponse('Valid holiday date in YYYY-MM-DD format is required.');
        }

        if (empty($name)) {
            return $this->errorResponse('Holiday name/description is required.');
        }

        // Check if exact date already registered
        $existing = $this->holidayModel->where('holiday_date', $holidayDate)->first();
        if ($existing) {
            return $this->errorResponse("A holiday entry for {$holidayDate} ('{$existing['name']}') already exists.");
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
            return $this->errorResponse('Failed to record special holiday.', $this->holidayModel->errors());
        }

        WorkCalendar::clearCache();

        $currentUserId = $this->currentUserId() ?? 'system';
        $this->activityLogModel->log(
            $currentUserId,
            'Holiday Added',
            "Registered special non-working holiday '{$name}' on {$holidayDate}.",
            $this->request->getIPAddress()
        );

        $created = $this->holidayModel->find($id);
        return $this->successResponse("Special holiday '{$name}' on {$holidayDate} registered successfully.", $created, ResponseInterface::HTTP_CREATED);
    }

    /**
     * Update an existing special holiday entry.
     * PATCH /api/v1/operations/holidays/(:num)
     */
    public function updateHoliday(int $id): ResponseInterface
    {
        $holiday = $this->holidayModel->find($id);
        if (!$holiday) {
            return $this->notFoundResponse('Special Holiday');
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
                return $this->errorResponse("Another holiday entry already exists for {$hDate}.");
            }
            $updateData['holiday_date'] = $hDate;
        }

        if (isset($body['name'])) {
            $name = sanitize_string($body['name']);
            if (empty($name)) {
                return $this->errorResponse('Holiday name cannot be empty.');
            }
            $updateData['name'] = $name;
        }

        if (isset($body['type'])) {
            $updateData['type'] = trim($body['type']);
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

        $currentUserId = $this->currentUserId() ?? 'system';
        $this->activityLogModel->log(
            $currentUserId,
            'Holiday Updated',
            "Updated holiday #{$id} ({$updated['name']} on {$updated['holiday_date']}).",
            $this->request->getIPAddress()
        );

        return $this->successResponse("Holiday entry '{$updated['name']}' updated.", $updated);
    }

    /**
     * Delete a special holiday entry.
     * DELETE /api/v1/operations/holidays/(:num)
     */
    public function deleteHoliday(int $id): ResponseInterface
    {
        $holiday = $this->holidayModel->find($id);
        if (!$holiday) {
            return $this->notFoundResponse('Special Holiday');
        }

        $this->holidayModel->delete($id);
        WorkCalendar::clearCache();

        $currentUserId = $this->currentUserId() ?? 'system';
        $this->activityLogModel->log(
            $currentUserId,
            'Holiday Deleted',
            "Deleted special holiday '{$holiday['name']}' on {$holiday['holiday_date']}.",
            $this->request->getIPAddress()
        );

        return $this->successResponse("Holiday entry '{$holiday['name']}' deleted.");
    }

    // =========================================================================
    // 4. Working Hours & SLA Duration Configuration
    // =========================================================================

    /**
     * Get operational working hours.
     * GET /api/v1/operations/working-hours
     */
    public function getWorkingHours(): ResponseInterface
    {
        $config = [
            'work_start_time'      => $this->settingModel->getSetting('work_start_time', '08:00'),
            'work_end_time'        => $this->settingModel->getSetting('work_end_time', '17:00'),
            'lunch_start_time'     => $this->settingModel->getSetting('lunch_start_time', '12:00'),
            'lunch_end_time'       => $this->settingModel->getSetting('lunch_end_time', '13:00'),
            'standard_daily_hours' => (float) $this->settingModel->getSetting('standard_daily_hours', '8.0'),
        ];

        return $this->successResponse('Working hours retrieved.', $config);
    }

    /**
     * Update operational working hours.
     * POST /api/v1/operations/working-hours
     */
    public function updateWorkingHours(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        $startTime  = trim($body['work_start_time'] ?? '08:00');
        $endTime    = trim($body['work_end_time'] ?? '17:00');
        $lunchStart = trim($body['lunch_start_time'] ?? '12:00');
        $lunchEnd   = trim($body['lunch_end_time'] ?? '13:00');
        $dailyHours = (float) ($body['standard_daily_hours'] ?? 8.0);

        // Validate time format: HH:mm
        $timeRegex = '/^([01]\d|2[0-3]):([0-5]\d)$/';
        if (!preg_match($timeRegex, $startTime) || !preg_match($timeRegex, $endTime)) {
            return $this->errorResponse('Start time and end time must be in 24-hour format (e.g. 08:00, 17:00).');
        }

        if (!preg_match($timeRegex, $lunchStart) || !preg_match($timeRegex, $lunchEnd)) {
            return $this->errorResponse('Lunch break times must be in 24-hour format (e.g. 12:00, 13:00).');
        }

        // Logical ordering checks
        if ($startTime >= $endTime) {
            return $this->errorResponse('Work start time must be earlier than work end time.');
        }

        if ($lunchStart >= $lunchEnd) {
            return $this->errorResponse('Lunch start time must be earlier than lunch end time.');
        }

        if ($lunchStart < $startTime || $lunchEnd > $endTime) {
            return $this->errorResponse('Lunch break must fall completely within working hours.');
        }

        if ($dailyHours <= 0 || $dailyHours > 24) {
            return $this->errorResponse('Standard daily hours must be between 1 and 24 hours.');
        }

        $this->settingModel->setSetting('work_start_time', $startTime, 'Daily operational starting time (HH:mm)');
        $this->settingModel->setSetting('work_end_time', $endTime, 'Daily operational ending time (HH:mm)');
        $this->settingModel->setSetting('lunch_start_time', $lunchStart, 'Daily lunch break start time (HH:mm)');
        $this->settingModel->setSetting('lunch_end_time', $lunchEnd, 'Daily lunch break end time (HH:mm)');
        $this->settingModel->setSetting('standard_daily_hours', (string) $dailyHours, 'Standard net operational hours per day');

        WorkCalendar::clearCache();

        $currentUserId = $this->currentUserId() ?? 'system';
        $this->activityLogModel->log(
            $currentUserId,
            'Working Hours Updated',
            "Operational hours updated: {$startTime} - {$endTime} (Lunch: {$lunchStart} - {$lunchEnd}, {$dailyHours}h/day).",
            $this->request->getIPAddress()
        );

        return $this->successResponse('Working hours and SLA duration parameters successfully updated.', [
            'work_start_time'      => $startTime,
            'work_end_time'        => $endTime,
            'lunch_start_time'     => $lunchStart,
            'lunch_end_time'       => $lunchEnd,
            'standard_daily_hours' => $dailyHours,
        ]);
    }
}
