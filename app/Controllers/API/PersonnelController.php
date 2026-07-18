<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\PersonnelModel;
use App\Models\TicketLogModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PersonnelController
 *
 * Manages unit field staff (workers, gardeners, drivers, etc.).
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
    private TicketLogModel $logModel;

    // Unit code to ID map (mirrors DB seeds)
    private const UNIT_MAP = ['FGMU' => 1, 'LEAU' => 2, 'SSU' => 3, 'TASU' => 4];

    public function __construct()
    {
        $this->personnelModel = new PersonnelModel();
        $this->logModel       = new TicketLogModel();
    }

    /**
     * Get the full roster for a unit, grouped by specialty.
     * Includes current active assignment info.
     */
    public function byUnit(string $unitCode): ResponseInterface
    {
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

        $body   = $this->request->getJSON(true) ?? [];
        $status = sanitize_string($body['status'] ?? '');

        $allowedStatuses = ['available', 'on_leave'];
        if (!in_array($status, $allowedStatuses, true)) {
            return $this->errorResponse("Status must be one of: " . implode(', ', $allowedStatuses));
        }

        // Cannot manually set a 'working' worker to another status via this endpoint;
        // that is managed automatically by Dispatch / Ticket completion flows.
        if (in_array($worker['status'], ['working', 'on_trip'], true)) {
            return $this->errorResponse("Cannot change status of a worker who is actively working or on a trip. Complete their current job first.");
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

        if (empty($name) || empty($specialty) || !$unitId) {
            return $this->errorResponse('name, specialty, and unit_id are required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $personnelId = generate_uuid();

        $this->personnelModel->insert([
            'id'        => $personnelId,
            'user_id'   => sanitize_string($body['user_id'] ?? '') ?: null,
            'unit_id'   => $unitId,
            'name'      => $name,
            'specialty' => $specialty,
            'status'    => 'available',
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

        $body       = $this->request->getJSON(true) ?? [];
        $updateData = [];

        if (isset($body['name'])) {
            $updateData['name'] = sanitize_string($body['name']);
        }
        if (isset($body['specialty'])) {
            $updateData['specialty'] = sanitize_string($body['specialty']);
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

        if (in_array($worker['status'], ['working', 'on_trip'], true)) {
            return $this->errorResponse('Cannot delete a worker who is currently active on a job.');
        }

        $this->personnelModel->delete($personnelId);

        return $this->successResponse('Personnel record deleted.', ['personnel_id' => $personnelId]);
    }
}
