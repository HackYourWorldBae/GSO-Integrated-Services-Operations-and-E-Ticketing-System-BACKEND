<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\PersonnelModel;
use App\Models\TicketModel;
use App\Models\TicketAssignmentModel;
use App\Models\TicketMaterialModel;
use App\Models\TicketLogModel;
use App\Models\NotificationModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * DispatchController
 *
 * Handles dispatcher operations: assigning personnel to approved tickets,
 * updating assignment schedules and notes, logging materials, and tracking workers.
 *
 * Endpoints:
 *  POST  /api/v1/dispatch/assign             - Assign worker to a ticket
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
    private NotificationModel $notificationModel;

    public function __construct()
    {
        $this->personnelModel  = new PersonnelModel();
        $this->ticketModel     = new TicketModel();
        $this->assignmentModel = new TicketAssignmentModel();
        $this->materialModel   = new TicketMaterialModel();
        $this->logModel        = new TicketLogModel();
        $this->notificationModel = new NotificationModel();
    }

    /**
     * Assign a personnel member to an approved ticket.
     *
     * Request body:
     * {
     *   ticket_id: string,
     *   personnel_id: int,
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

        if (!in_array($ticket['status'], ['approved', 'pending', 'processing'], true)) {
            return $this->errorResponse("Ticket must be approved, pending, or processing before assigning. Current status: {$ticket['status']}.");
        }

        $worker = $this->personnelModel->find($personnelId);
        if (!$worker) {
            return $this->notFoundResponse('Personnel');
        }

        $implementationDate = sanitize_string($body['implementation_date'] ?? date('Y-m-d'));
        $workingDays        = !empty($body['working_days']) ? (int) $body['working_days'] : null;
        $taskNotes          = sanitize_string($body['task_notes'] ?? '');
        $dispatcherNotes    = sanitize_string($body['dispatcher_notes'] ?? '');

        // When just dispatching, the worker is not working yet!
        // We leave the status as 'available'. The database handles the assignment via the `ticket_assignments` table.
        $workerStatus = 'available';
        
        // Update ticket to indicate it's been dispatched but NOT started
        $statusLabel  = 'Dispatched / Scheduled';

        // --- Insert assignment record ---
        $assignmentId = $this->assignmentModel->insert([
            'ticket_id'          => $ticketId,
            'personnel_id'       => $personnelId,
            'implementation_date'=> $implementationDate,
            'working_days'       => $workingDays,
            'task_notes'         => $taskNotes,
            'dispatcher_notes'   => $dispatcherNotes,
            'assigned_at'        => date('Y-m-d H:i:s'),
        ], true); // true = return inserted ID

        // --- Update ticket status to processing ---
        // Setting it to 'processing' moves it out of the dispatcher's pending queue,
        // but 'status_label' ensures everyone knows it's only dispatched, not started.
        $this->ticketModel->update($ticketId, [
            'status'               => 'processing',
            'status_label'         => $statusLabel,
            'current_step'         => 4, // 4 = Dispatch & Schedule for FGMU/LEAU
            'project_working_days' => $workingDays,
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        // --- Update worker status (remain available until they start the job) ---
        $this->personnelModel->update($personnelId, [
            'status'     => $workerStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // --- If the ticket is a project, persist the dispatcher-set scheduling fields ---
        if (!empty($ticket['is_project'])) {
            $projectUpdate = ['updated_at' => date('Y-m-d H:i:s')];

            if ($implementationDate) {
                $projectUpdate['project_target_date'] = $implementationDate;
            }

            if ($workingDays !== null) {
                $projectUpdate['project_target_duration'] = $workingDays . ' Working Days';
            }

            $this->ticketModel->update($ticketId, $projectUpdate);
        }
        $detail = "Assigned to {$worker['name']}";
        if ($implementationDate) {
            $detail .= " — scheduled for {$implementationDate}";
        }
        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Worker Assigned', $detail);

        $this->notificationModel->createNotification(
            $ticket['user_id'], 
            'info', 
            "Ticket #{$ticketId} Dispatched", 
            $implementationDate ? "Your ticket has been scheduled for {$implementationDate}." : "A worker has been assigned to your ticket."
        );

        return $this->successResponse('Worker assigned successfully.', [
            'assignment_id' => $assignmentId,
            'ticket_id'     => $ticketId,
            'personnel_id'  => $personnelId,
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
     * Body: { materials: [{ name: string, quantity: number, unit: string, unit_price?: number, total_price?: number }] }
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

        $totalCost = 0.0;
        foreach ($materials as $mat) {
            $name = sanitize_string($mat['name'] ?? $mat['material_name'] ?? '');
            if (empty($name)) {
                continue;
            }

            $qty   = max(0.01, (float) ($mat['quantity'] ?? 1));
            $unit  = sanitize_string($mat['unit'] ?? $mat['unit_measurement'] ?? 'pcs');
            $price = max(0.00, (float) ($mat['unit_price'] ?? $mat['price'] ?? 0));
            $lineTotal = isset($mat['total_price']) ? (float) $mat['total_price'] : ($qty * $price);
            $totalCost += $lineTotal;

            $this->materialModel->insert([
                'ticket_id'        => $assignment['ticket_id'],
                'assignment_id'    => $assignmentId,
                'material_name'    => $name,
                'quantity'         => $qty,
                'unit_measurement' => $unit,
                'unit_price'       => $price,
                'total_price'      => $lineTotal,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        }

        $this->ticketModel->update($assignment['ticket_id'], [
            'materials_logged' => 1,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction($assignment['ticket_id'], $this->currentUserId(), 'Materials Added', count($materials) . ' material(s) listed. Total: ₱' . number_format($totalCost, 2));

        return $this->successResponse('Materials added.', [
            'assignment_id' => $assignmentId,
            'ticket_id'     => $assignment['ticket_id'],
            'total_cost'    => $totalCost
        ], ResponseInterface::HTTP_CREATED);
    }

    /**
     * Start a job (Dispatcher).
     * Updates the assignment to working, sets ticket step to 4 or 5, and changes personnel statuses.
     *
     * Body: { ticket_id }
     */
    public function startJob(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];
        $ticketId = sanitize_string($body['ticket_id'] ?? '');

        if (!$ticketId) {
            return $this->errorResponse('ticket_id is required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket) return $this->notFoundResponse('Ticket');

        $workerStatus = 'working';
        $statusLabel  = 'Job Started';

        // Set ticket step to 5 (Job Started)
        $this->ticketModel->update($ticketId, [
            'status_label' => $statusLabel,
            'current_step' => 5,
            'project_actual_start' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        // Find all assignments for this ticket, update personnel status,
        // and stamp dispatched_at & implementation_date so the frontend can compute accurate job duration.
        $assignments = $this->assignmentModel->getByTicket($ticketId);
        foreach ($assignments as $assignment) {
            if (!empty($assignment['personnel_id'])) {
                $this->personnelModel->update($assignment['personnel_id'], [
                    'status'     => $workerStatus,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            // Record the actual start time for duration tracking and update implementation_date
            $this->assignmentModel->update($assignment['id'], [
                'dispatched_at'       => date('Y-m-d H:i:s'),
                'implementation_date' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Job Started', "Dispatcher actively started the job.");

        return $this->successResponse('Job started successfully.');
    }
}
