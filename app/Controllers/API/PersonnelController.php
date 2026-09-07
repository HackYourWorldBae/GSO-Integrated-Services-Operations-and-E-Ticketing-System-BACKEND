<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\PersonnelModel;
use App\Models\PersonnelCategoryModel;
use App\Models\TicketLogModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PersonnelController
 *
 * Manages unit field staff (workers, technicians, etc.).
 *
 * Endpoints:
 *  GET   /api/v1/personnel/:unitCode              - Full roster for a unit (admin/dispatcher view)
 *  GET   /api/v1/personnel/:unitCode/available    - Available workers only (for dispatcher dropdowns)
 *  PATCH /api/v1/personnel/:id/status             - Toggle worker availability / leave status
 *  POST  /api/v1/personnel                        - Create a new personnel record
 *  PUT   /api/v1/personnel/:id                    - Update personnel info
 *  DELETE /api/v1/personnel/:id                   - Remove a personnel record
 */
class PersonnelController extends BaseController
{
    private PersonnelModel $personnelModel;
    private PersonnelCategoryModel $categoryModel;
    private TicketLogModel $logModel;

    // Unit code to ID map (mirrors DB seeds)
    private const UNIT_MAP = ['FGMU' => 1, 'LEAU' => 2, 'SSU' => 3];

    public function __construct()
    {
        $this->personnelModel = new PersonnelModel();
        $this->categoryModel  = new PersonnelCategoryModel();
        $this->logModel       = new TicketLogModel();
    }

    /**
     * Get the full roster for a unit, grouped by specialty.
     * Includes current active assignment info.
     */
    public function byUnit(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;
        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $personnel = $this->personnelModel->getByUnit($unitId);

        // Group by specialty for display
        $grouped = [];
        foreach ($personnel as $person) {
            $grouped[$person['specialty']][] = $person;
        }

        return $this->successResponse('Personnel roster retrieved.', [
            'unit'      => strtoupper($unitCode),
            'personnel' => $personnel,
            'grouped'   => $grouped,
        ]);
    }

    /**
     * Get only available workers for a unit (used in dispatcher assignment dropdowns).
     */
    public function available(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;
        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $workers = $this->personnelModel->getAvailableByUnit($unitId);

        return $this->successResponse('Available personnel retrieved.', ['personnel' => $workers]);
    }

    /**
     * Update a worker's availability status.
     *
     * Body: { status: 'available' | 'on_leave' }
     * Note: 'working' / 'on_trip' status is set automatically by DispatchController.
     */
    public function updateStatus(string $personnelId): ResponseInterface
    {
        $worker = $this->personnelModel->find($personnelId);
        if (!$worker) {
            return $this->notFoundResponse('Personnel');
        }

        if ($forbidden = $this->assertUnitAccess((int) $worker['unit_id'])) {
            return $forbidden;
        }

        $body   = $this->request->getJSON(true) ?? [];
        $status = sanitize_string($body['status'] ?? '');

        $allowedStatuses = ['available', 'on_leave', 'inactive', 'retired'];
        if (!in_array($status, $allowedStatuses, true)) {
            return $this->errorResponse("Status must be one of: " . implode(', ', $allowedStatuses));
        }

        $assignmentModel   = new \App\Models\TicketAssignmentModel();
        $ticketModel       = new \App\Models\TicketModel();
        $notificationModel = new \App\Models\NotificationModel();

        // Check for active assignments
        $activeAssignments = $assignmentModel->getByPersonnel($personnelId);

        if (in_array($status, ['inactive', 'retired'], true) && !empty($activeAssignments)) {
            // Automatically unassign all active and queued tasks and return to the unit queue
            foreach ($activeAssignments as $assignment) {
                $assignmentModel->update($assignment['id'], [
                    'completed_at'       => date('Y-m-d H:i:s'),
                    'reassignment_reason'=> "Staff member marked as {$status}",
                ]);

                $ticketModel->update($assignment['ticket_id'], [
                    'status'       => 'approved',
                    'status_label' => 'Approved (Pending Re-dispatch)',
                    'current_step' => 2,
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);

                $this->logModel->logAction(
                    $assignment['ticket_id'],
                    $this->currentUserId(),
                    'Worker Unassigned (Staff Retired/Inactive)',
                    "Staff {$worker['name']} was marked as {$status}. Task returned to unit dispatch queue for reassignment."
                );

                $ticket = $ticketModel->find($assignment['ticket_id']);
                if ($ticket) {
                    $notificationModel->createNotification(
                        $ticket['user_id'],
                        'info',
                        "Ticket #{$assignment['ticket_id']} Schedule Update",
                        "Staff assignment updated. Ticket queued for dispatch."
                    );
                }
            }
        } elseif ($status === 'on_leave' && !empty($activeAssignments)) {
            $leaveAction = sanitize_string($body['leave_action'] ?? '');
            $leaveReason = sanitize_string($body['leave_reason'] ?? 'Sick / Medical Leave');

            if (!in_array($leaveAction, ['reassign', 'extend'], true)) {
                return $this->errorResponse(
                    "Worker has active assignment(s). Please specify 'leave_action' ('reassign' or 'extend').",
                    ['active_assignments' => $activeAssignments],
                    ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            if ($leaveAction === 'reassign') {
                $targetWorkerId = sanitize_string($body['reassign_to_personnel_id'] ?? '');
                if (empty($targetWorkerId)) {
                    return $this->errorResponse('Target personnel ID is required for reassignment.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
                }

                $targetWorker = $this->personnelModel->find($targetWorkerId);
                if (!$targetWorker) {
                    return $this->notFoundResponse('Replacement Personnel');
                }

                if ((int) $targetWorker['unit_id'] !== (int) $worker['unit_id']) {
                    return $this->errorResponse("Replacement worker must belong to the same unit.");
                }

                if ($targetWorker['status'] === 'on_leave') {
                    return $this->errorResponse("Selected replacement worker is currently on leave.");
                }

                // Reassign all active assignments to target worker
                $hasRunningTicket = false;
                foreach ($activeAssignments as $assignment) {
                    $assignmentModel->update($assignment['id'], [
                        'personnel_id'       => $targetWorkerId,
                        'is_reassigned'      => 1,
                        'reassigned_from_id' => $personnelId,
                        'reassignment_reason'=> $leaveReason,
                    ]);

                    $ticket = $ticketModel->find($assignment['ticket_id']);
                    if ($ticket && (int) $ticket['current_step'] === 5) {
                        $hasRunningTicket = true;
                    }

                    $this->logModel->logAction(
                        $assignment['ticket_id'],
                        $this->currentUserId(),
                        'Worker Reassigned',
                        "Reassigned from {$worker['name']} (on leave: {$leaveReason}) to {$targetWorker['name']}."
                    );

                    if ($ticket) {
                        $notificationModel->createNotification(
                            $ticket['user_id'],
                            'info',
                            "Ticket #{$assignment['ticket_id']} Personnel Updated",
                            "Staff assignment updated to {$targetWorker['name']}."
                        );
                    }
                }

                // Set new worker status to working if any of the tickets was actively running
                if ($hasRunningTicket) {
                    $this->personnelModel->update($targetWorkerId, [
                        'status'     => 'working',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }

            } elseif ($leaveAction === 'extend') {
                $extensionDays = (int) ($body['extension_days'] ?? 1);
                $extendedDate  = sanitize_string($body['extended_completion_date'] ?? '');

                foreach ($activeAssignments as $assignment) {
                    $ticket = $ticketModel->find($assignment['ticket_id']);
                    if (!$ticket) {
                        continue;
                    }

                    $baseDate = !empty($ticket['extended_completion_date'])
                        ? new \DateTime($ticket['extended_completion_date'])
                        : (!empty($assignment['implementation_date'])
                            ? new \DateTime($assignment['implementation_date'])
                            : new \DateTime());

                    $newTargetDate = !empty($extendedDate)
                        ? $extendedDate
                        : \App\Libraries\WorkCalendar::addWorkingDays($baseDate, max(1, $extensionDays))->format('Y-m-d');

                    $totalDays = (int) ($ticket['extension_days'] ?? 0) + max(1, $extensionDays);

                    $ticketModel->update($ticket['id'], [
                        'extension_days'           => $totalDays,
                        'extended_completion_date' => $newTargetDate,
                        'extension_reason'         => "Assigned worker {$worker['name']} on leave ({$leaveReason})",
                        'updated_at'               => date('Y-m-d H:i:s'),
                    ]);

                    $assignmentModel->update($assignment['id'], [
                        'implementation_date' => $newTargetDate,
                    ]);

                    $this->logModel->logAction(
                        $ticket['id'],
                        $this->currentUserId(),
                        'Timeline Extended (Staff Leave)',
                        "Target completion adjusted to {$newTargetDate} (+{$extensionDays} working days) because {$worker['name']} is on leave: {$leaveReason}."
                    );

                    $notificationModel->createNotification(
                        $ticket['user_id'],
                        'warning',
                        "Ticket #{$ticket['id']} Extended",
                        "Schedule adjusted to {$newTargetDate} due to staff leave."
                    );
                }
            }
        }

        $this->personnelModel->update($personnelId, [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->successResponse("Personnel status updated to '{$status}'.", [
            'personnel_id' => $personnelId,
            'status'       => $status,
        ]);
    }

    /**
     * Create a new personnel record.
     *
     * Body: { name, specialty, unit_id, status?, user_id? }
     */
    public function create(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        $name      = sanitize_string($body['name'] ?? '');
        $specialty = sanitize_string($body['specialty'] ?? '');
        $unitId    = (int) ($body['unit_id'] ?? 0);
        $contact   = sanitize_string($body['contact_number'] ?? '');

        // If 'name' is not provided directly, compose it from name components
        if (empty($name) && !empty($body['first_name']) && !empty($body['last_name'])) {
            $parts = [sanitize_string($body['first_name'])];
            if (!empty($body['middle_initial'])) {
                $parts[] = rtrim(sanitize_string($body['middle_initial']), '.') . '.';
            }
            $parts[] = sanitize_string($body['last_name']);
            if (!empty($body['name_extension'])) {
                $parts[] = sanitize_string($body['name_extension']);
            }
            $name = implode(' ', $parts);
        }

        if (empty($name) || empty($specialty) || !$unitId) {
            return $this->errorResponse('name, specialty, and unit_id are required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($forbidden = $this->assertUnitAccess($unitId)) {
            return $forbidden;
        }

        if (!empty($contact) && !preg_match('/^[0-9]{11}$/', $contact)) {
            return $this->errorResponse('contact_number must be exactly 11 digits.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $personnelId = generate_uuid();

        $this->personnelModel->insert([
            'id'             => $personnelId,
            'unit_id'        => $unitId,
            'name'           => $name,
            'specialty'      => $specialty,
            'contact_number' => $contact !== '' ? $contact : null,
            'status'         => 'available',
        ]);

        return $this->successResponse('Personnel created successfully.', [
            'personnel_id' => $personnelId,
        ], ResponseInterface::HTTP_CREATED);
    }

    /**
     * Update an existing personnel record's name, specialty, or status.
     */
    public function update(string $personnelId): ResponseInterface
    {
        $worker = $this->personnelModel->find($personnelId);
        if (!$worker) {
            return $this->notFoundResponse('Personnel');
        }

        if ($forbidden = $this->assertUnitAccess((int) $worker['unit_id'])) {
            return $forbidden;
        }

        $body       = $this->request->getJSON(true) ?? [];
        $updateData = [];

        if (isset($body['name'])) {
            $updateData['name'] = sanitize_string($body['name']);
        }
        if (isset($body['specialty'])) {
            $updateData['specialty'] = sanitize_string($body['specialty']);
        }
        if (isset($body['contact_number'])) {
            $contact = sanitize_string($body['contact_number']);
            if ($contact !== '' && !preg_match('/^[0-9]{11}$/', $contact)) {
                return $this->errorResponse('contact_number must be exactly 11 digits.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
            $updateData['contact_number'] = $contact !== '' ? $contact : null;
        }

        if (!empty($updateData)) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            $this->personnelModel->update($personnelId, $updateData);
        }

        return $this->successResponse('Personnel updated.', ['personnel_id' => $personnelId]);
    }

    /**
     * Delete a personnel record.
     * Only allowed if the worker has no active assignments.
     */
    public function delete(string $personnelId): ResponseInterface
    {
        $worker = $this->personnelModel->find($personnelId);
        if (!$worker) {
            return $this->notFoundResponse('Personnel');
        }

        if ($forbidden = $this->assertUnitAccess((int) $worker['unit_id'])) {
            return $forbidden;
        }

        if (in_array($worker['status'], ['working', 'on_trip'], true)) {
            return $this->errorResponse('Cannot delete a worker who is currently active on a job.');
        }

        $this->personnelModel->delete($personnelId);

        return $this->successResponse('Personnel record deleted.', ['personnel_id' => $personnelId]);
    }

    // =========================================================================
    // Category Management
    // =========================================================================

    /**
     * List all personnel categories for a unit.
     * GET /api/v1/personnel/categories/:unitCode
     */
    public function categories(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;
        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $categories = $this->categoryModel->getByUnit($unitId);

        return $this->successResponse('Categories retrieved.', [
            'unit'       => strtoupper($unitCode),
            'categories' => $categories,
        ]);
    }

    /**
     * Create a new personnel category for a unit.
     * POST /api/v1/personnel/categories
     * Body: { unit_code: 'FGMU', name: 'Welder' }
     */
    public function createCategory(): ResponseInterface
    {
        $body     = $this->request->getJSON(true) ?? [];
        $unitCode = strtoupper(sanitize_string($body['unit_code'] ?? ''));
        $name     = sanitize_string($body['name'] ?? '');

        $unitId = self::UNIT_MAP[$unitCode] ?? null;
        if (!$unitId) {
            return $this->errorResponse('Valid unit_code is required (FGMU, LEAU, SSU).');
        }

        if ($forbidden = $this->assertUnitAccess($unitId)) {
            return $forbidden;
        }

        if (empty($name)) {
            return $this->errorResponse('Category name is required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Check for duplicates within this unit
        $existing = $this->categoryModel
            ->where('unit_id', $unitId)
            ->where('name', $name)
            ->first();

        if ($existing) {
            return $this->errorResponse('A category with this name already exists for this unit.', [], ResponseInterface::HTTP_CONFLICT);
        }

        $id = $this->categoryModel->insert([
            'unit_id'   => $unitId,
            'name'      => $name,
            'is_system' => 0,
        ], true);

        return $this->successResponse('Category created.', [
            'category' => ['id' => $id, 'unit_id' => $unitId, 'name' => $name, 'is_system' => 0],
        ], ResponseInterface::HTTP_CREATED);
    }
    /**
     * Update a personnel category name and cascade to personnel.
     * PATCH /api/v1/personnel/categories/:id
     * Body: { name: 'New Name' }
     */
    public function updateCategory(string $categoryId): ResponseInterface
    {
        $category = $this->categoryModel->find((int) $categoryId);
        if (!$category) {
            return $this->notFoundResponse('Category');
        }

        if ($forbidden = $this->assertUnitAccess((int) $category['unit_id'])) {
            return $forbidden;
        }

        $body = $this->request->getJSON(true) ?? [];
        $newName = sanitize_string($body['name'] ?? '');

        if (empty($newName)) {
            return $this->errorResponse('Category name is required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Check for duplicates
        $existing = $this->categoryModel
            ->where('unit_id', $category['unit_id'])
            ->where('name', $newName)
            ->where('id !=', $categoryId)
            ->first();

        if ($existing) {
            return $this->errorResponse('A category with this name already exists for this unit.', [], ResponseInterface::HTTP_CONFLICT);
        }

        $oldName = $category['name'];

        $this->categoryModel->update((int) $categoryId, ['name' => $newName]);

        // Cascade update to personnel
        $db = \Config\Database::connect();
        $db->table('personnel')
            ->where('unit_id', $category['unit_id'])
            ->where('specialty', $oldName)
            ->update(['specialty' => $newName]);

        return $this->successResponse('Category updated.', [
            'category_id' => (int) $categoryId,
            'new_name'    => $newName
        ]);
    }
    /**
     * Delete a personnel category.
     * DELETE /api/v1/personnel/categories/:id
     * Blocked if: (a) category is a system/seeded category, (b) category is in use by personnel.
     */
    public function deleteCategory(string $categoryId): ResponseInterface
    {
        $category = $this->categoryModel->find((int) $categoryId);
        if (!$category) {
            return $this->notFoundResponse('Category');
        }

        if ($forbidden = $this->assertUnitAccess((int) $category['unit_id'])) {
            return $forbidden;
        }

        if ($this->categoryModel->isInUse((int) $categoryId)) {
            return $this->errorResponse(
                'Cannot delete a category that is currently assigned to personnel. Reassign or remove those personnel first.',
                [],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->categoryModel->delete((int) $categoryId);

        return $this->successResponse('Category deleted.', ['category_id' => (int) $categoryId]);
    }
}
