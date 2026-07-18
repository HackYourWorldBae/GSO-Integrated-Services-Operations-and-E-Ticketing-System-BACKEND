<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\PersonnelModel;
use App\Models\TicketModel;
use App\Models\TicketAssignmentModel;
use App\Models\TicketMaterialModel;
use App\Models\TicketLogModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * DispatchController
 *
 * Handles dispatcher operations: assigning personnel/vehicles to approved tickets,
 * updating schedules, managing materials, and marking jobs complete.
 *
 * Endpoints:
 *  POST  /api/v1/dispatch/assign             - Assign worker/driver (and vehicle) to a ticket
 *  PATCH /api/v1/dispatch/assignments/:id    - Update assignment notes / schedule date
 *  POST  /api/v1/dispatch/assignments/:id/materials - Add materials to an assignment
 *  GET   /api/v1/dispatch/worker/:personnelId - Worker's current assignment (worker dashboard)
 *  GET   /api/v1/dispatch/worker/:personnelId/history - Worker's completed job history
 */
class DispatchController extends BaseController
{
    private PersonnelModel $personnelModel;
    private TicketModel $ticketModel;
    private TicketAssignmentModel $assignmentModel;
    private TicketMaterialModel $materialModel;
    private TicketLogModel $logModel;

    public function __construct()
    {
        $this->personnelModel  = new PersonnelModel();
        $this->ticketModel     = new TicketModel();
        $this->assignmentModel = new TicketAssignmentModel();
        $this->materialModel   = new TicketMaterialModel();
        $this->logModel        = new TicketLogModel();
    }

    /**
     * Assign a personnel member (and optionally a vehicle) to an approved ticket.
     *
     * Body: {
     *   ticket_id: string,
     *   personnel_id: string,
     *   vehicle_id?: int,
     *   implementation_date?: string,
     *   task_notes?: string,
     *   dispatcher_notes?: string
     * }
     */
    public function assign(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        // --- Validation ---
        $ticketId    = sanitize_string($body['ticket_id'] ?? '');
        $personnelId = sanitize_string($body['personnel_id'] ?? '');

        if (empty($ticketId) || empty($personnelId)) {
            return $this->errorResponse(
                'ticket_id and personnel_id are required.',
                [],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if (!in_array($ticket['status'], ['approved', 'pending'], true)) {
            return $this->errorResponse("Ticket must be approved or pending before assigning. Current status: {$ticket['status']}.");
        }

        $worker = $this->personnelModel->find($personnelId);
        if (!$worker) {
            return $this->notFoundResponse('Personnel');
        }

        $vehicleId          = !empty($body['vehicle_id']) ? (int) $body['vehicle_id'] : null;
        $implementationDate = sanitize_string($body['implementation_date'] ?? date('Y-m-d'));
        $taskNotes          = sanitize_string($body['task_notes'] ?? '');
        $dispatcherNotes    = sanitize_string($body['dispatcher_notes'] ?? '');

        // Determine TASU-specific labels
        $isTasu      = ((int) $ticket['unit_id']) === 4;
        $workerStatus = $isTasu ? 'on_trip' : 'working';
        $statusLabel  = $isTasu ? 'On Route / In Progress' : 'Job Started';

        // --- Insert assignment record ---
        $assignmentId = $this->assignmentModel->insert([
            'ticket_id'          => $ticketId,
            'personnel_id'       => $personnelId,
            'vehicle_id'         => $vehicleId,
            'implementation_date'=> $implementationDate,
            'task_notes'         => $taskNotes,
            'dispatcher_notes'   => $dispatcherNotes,
            'assigned_at'        => date('Y-m-d H:i:s'),
        ], true); // true = return inserted ID

        // --- Update ticket status to processing ---
        $this->ticketModel->update($ticketId, [
            'status'       => 'processing',
            'status_label' => $statusLabel,
            'current_step' => 3,
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        // --- Update worker status ---
        $this->personnelModel->update($personnelId, [
            'status'     => $workerStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // --- Update vehicle status if provided ---
        if ($vehicleId) {
            $db = \Config\Database::connect();
            $db->query("UPDATE vehicles SET status = 'in_use', updated_at = NOW() WHERE id = ?", [$vehicleId]);
        }

        // --- Audit log ---
        $detail = "Assigned to {$worker['name']}";
        if ($implementationDate) {
            $detail .= " — scheduled for {$implementationDate}";
        }
        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Worker Assigned', $detail);

        return $this->successResponse('Worker assigned successfully.', [
            'assignment_id' => $assignmentId,
            'ticket_id'     => $ticketId,
            'personnel_id'  => $personnelId,
            'vehicle_id'    => $vehicleId,
            'status'        => 'processing',
        ], ResponseInterface::HTTP_CREATED);
    }

    /**
     * Update an assignment's schedule date or notes.
     */
    public function updateAssignment(int $assignmentId): ResponseInterface
    {
        $assignment = $this->assignmentModel->find($assignmentId);
        if (!$assignment) {
            return $this->notFoundResponse('Assignment');
        }

        $body = $this->request->getJSON(true) ?? [];

        $updateData = [];
        if (isset($body['implementation_date'])) {
            $updateData['implementation_date'] = sanitize_string($body['implementation_date']);
        }
        if (isset($body['task_notes'])) {
            $updateData['task_notes'] = sanitize_string($body['task_notes']);
        }
        if (isset($body['dispatcher_notes'])) {
            $updateData['dispatcher_notes'] = sanitize_string($body['dispatcher_notes']);
        }

        if (!empty($updateData)) {
            $this->assignmentModel->update($assignmentId, $updateData);
            $this->logModel->logAction($assignment['ticket_id'], $this->currentUserId(), 'Assignment Updated', json_encode($updateData));
        }

        return $this->successResponse('Assignment updated.', ['assignment_id' => $assignmentId]);
    }

    /**
     * Add materials list to an assignment.
     *
     * Body: { materials: [{ name: string, quantity: int, unit: string }] }
     */
    public function addMaterials(int $assignmentId): ResponseInterface
    {
        $assignment = $this->assignmentModel->find($assignmentId);
        if (!$assignment) {
            return $this->notFoundResponse('Assignment');
        }

        $body      = $this->request->getJSON(true) ?? [];
        $materials = $body['materials'] ?? [];

        if (empty($materials) || !is_array($materials)) {
            return $this->errorResponse('materials array is required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($materials as $mat) {
            $name = sanitize_string($mat['name'] ?? '');
            if (empty($name)) {
                continue;
            }

            $this->materialModel->insert([
                'assignment_id'    => $assignmentId,
                'material_name'    => $name,
                'quantity'         => max(1, (int) ($mat['quantity'] ?? 1)),
                'unit_measurement' => sanitize_string($mat['unit'] ?? ''),
            ]);
        }

        $this->logModel->logAction($assignment['ticket_id'], $this->currentUserId(), 'Materials Added', count($materials) . ' material(s) listed.');

        return $this->successResponse('Materials added.', ['assignment_id' => $assignmentId], ResponseInterface::HTTP_CREATED);
    }

    /**
     * Get the current active assignment for a worker (worker dashboard).
     */
    public function workerDashboard(string $personnelId): ResponseInterface
    {
        $worker = $this->personnelModel->find($personnelId);
        if (!$worker) {
            return $this->notFoundResponse('Personnel');
        }

        $activeJob = $this->assignmentModel->getWorkerDashboard($personnelId);

        return $this->successResponse('Worker dashboard data retrieved.', [
            'worker'     => $worker,
            'active_job' => $activeJob,
        ]);
    }

    /**
     * Get completed job history for a worker.
     */
    public function workerHistory(string $personnelId): ResponseInterface
    {
        $history = $this->assignmentModel->getWorkerHistory($personnelId);

        return $this->successResponse('Worker history retrieved.', ['history' => $history]);
    }
}
