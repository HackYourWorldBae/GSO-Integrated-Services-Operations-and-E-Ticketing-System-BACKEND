<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\TicketCollaborationModel;
use App\Models\TicketModel;
use App\Models\PersonnelModel;
use App\Models\TicketAssignmentModel;
use App\Models\NotificationModel;
use App\Models\TicketLogModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * CollaborationController
 *
 * Facilitates inter-unit collaboration on tickets:
 * - Primary unit invites another unit (e.g. FGMU invites LEAU for electrical support)
 * - Collaborating unit accepts/declines the request
 * - Collaborating unit assigns/shares their personnel/manpower to the ticket
 * - Cross-unit coordination and completion tracking
 */
class CollaborationController extends BaseController
{
    protected TicketCollaborationModel $collabModel;
    protected TicketModel $ticketModel;
    protected PersonnelModel $personnelModel;
    protected TicketAssignmentModel $assignmentModel;
    protected NotificationModel $notifModel;
    protected TicketLogModel $logModel;
    protected UserModel $userModel;

    public function __construct()
    {
        $this->collabModel     = new TicketCollaborationModel();
        $this->ticketModel     = new TicketModel();
        $this->personnelModel  = new PersonnelModel();
        $this->assignmentModel = new TicketAssignmentModel();
        $this->notifModel      = new NotificationModel();
        $this->logModel        = new TicketLogModel();
        $this->userModel       = new UserModel();
    }

    /**
     * Request collaboration from another unit on a ticket.
     * POST /api/v1/tickets/:ticketId/collaborations
     */
    public function requestCollaboration(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        $currentUserId = $this->currentUserId();
        $userRole      = $this->currentUserRole();
        $userUnitId    = $this->currentUserUnitId();

        // Must be staff / admin of the ticket's unit or superadmin/director
        if (!in_array($userRole, ['admin', 'director', 'superadmin'], true)) {
            return $this->forbiddenResponse('Only unit administrators may request cross-unit collaboration.');
        }

        if ($userRole === 'admin' && (int)$ticket['unit_id'] !== $userUnitId) {
            return $this->forbiddenResponse('You can only request collaboration for tickets assigned to your unit.');
        }

        $body = $this->request->getJSON(true) ?? [];
        $collaboratingUnitId = (int) ($body['collaborating_unit_id'] ?? 0);
        $reason              = trim((string) ($body['reason'] ?? ''));
        $scopeOfWork         = trim((string) ($body['scope_of_work'] ?? ''));

        if ($collaboratingUnitId <= 0 || !in_array($collaboratingUnitId, [1, 2, 3], true)) {
            return $this->errorResponse('Please specify a valid collaborating unit (FGMU, LEAU, or SSU).');
        }

        if ($collaboratingUnitId === (int)$ticket['unit_id']) {
            return $this->errorResponse('Collaborating unit must be different from the primary managing unit.');
        }

        if (empty($reason)) {
            return $this->errorResponse('Please provide a reason or objective for the collaboration request.');
        }

        // Check for existing pending or active collaboration with the same unit
        $existing = $this->collabModel->where('ticket_id', $ticketId)
                                      ->where('collaborating_unit_id', $collaboratingUnitId)
                                      ->whereIn('status', ['pending', 'accepted'])
                                      ->first();
        if ($existing) {
            return $this->errorResponse("An active or pending collaboration request already exists with this unit.");
        }

        $collabData = [
            'ticket_id'             => $ticketId,
            'requesting_unit_id'    => (int) $ticket['unit_id'],
            'collaborating_unit_id' => $collaboratingUnitId,
            'requested_by'          => $currentUserId,
            'reason'                => $reason,
            'scope_of_work'         => $scopeOfWork ?: null,
            'status'                => 'pending',
        ];

        $collabId = $this->collabModel->insert($collabData);
        if (!$collabId) {
            return $this->errorResponse('Failed to submit collaboration request.', $this->collabModel->errors());
        }

        // Determine unit codes for logging and notification
        $unitMap = [1 => 'FGMU', 2 => 'LEAU', 3 => 'SSU'];
        $reqUnit = $unitMap[(int)$ticket['unit_id']] ?? 'Unit';
        $collabUnit = $unitMap[$collaboratingUnitId] ?? 'Unit';

        // Notify admins of collaborating unit
        $collabAdmins = $this->userModel->where('role', 'admin')
                                        ->where('unit_id', $collaboratingUnitId)
                                        ->where('status', 'Active')
                                        ->findAll();

        foreach ($collabAdmins as $adminUser) {
            $this->notifModel->createNotification(
                $adminUser['id'],
                'collaboration_request',
                "Collaboration Request: {$ticketId}",
                "{$reqUnit} is requesting collaboration on ticket #{$ticketId}: {$reason}"
            );
        }

        // Log action on ticket
        $this->logModel->logAction(
            $ticketId,
            $currentUserId,
            'COLLABORATION_REQUESTED',
            "Requested collaboration with {$collabUnit}. Objective: {$reason}"
        );

        $createdCollab = $this->collabModel->find($collabId);
        return $this->successResponse('Collaboration request submitted successfully.', $createdCollab, ResponseInterface::HTTP_CREATED);
    }

    /**
     * Get all collaborations for a ticket, with assigned collaborating personnel.
     * GET /api/v1/tickets/:ticketId/collaborations
     */
    public function getTicketCollaborations(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        $collaborations = $this->collabModel->getByTicket($ticketId);

        // Fetch all assignments for this ticket and map personnel with their home unit
        $db = \Config\Database::connect();
        $assignments = $db->table('ticket_assignments ta')
            ->select('ta.*, p.name as worker_name, p.specialty as worker_specialty, p.unit_id as worker_unit_id, u.code as worker_unit_code, u.name as worker_unit_name')
            ->join('personnel p', 'p.id = ta.personnel_id', 'left')
            ->join('units u', 'u.id = p.unit_id', 'left')
            ->where('ta.ticket_id', $ticketId)
            ->where('ta.completed_at IS NULL')
            ->get()->getResultArray();

        return $this->successResponse('Collaborations retrieved successfully.', [
            'ticket_id'      => $ticketId,
            'collaborations' => $collaborations,
            'assignments'    => $assignments,
        ]);
    }

    /**
     * Respond to a collaboration request (Accept / Decline).
     * PATCH /api/v1/collaborations/:id/respond
     */
    public function respond(int $id): ResponseInterface
    {
        $collab = $this->collabModel->find($id);
        if (!$collab) {
            return $this->notFoundResponse('Collaboration request');
        }

        if ($collab['status'] !== 'pending') {
            return $this->errorResponse("This collaboration request has already been {$collab['status']}.");
        }

        $currentUserId = $this->currentUserId();
        $userRole      = $this->currentUserRole();
        $userUnitId    = $this->currentUserUnitId();

        // Must be admin of collaborating unit or director/superadmin
        if ($userRole === 'admin' && (int)$collab['collaborating_unit_id'] !== $userUnitId) {
            return $this->forbiddenResponse('Only the invited unit head can respond to this collaboration request.');
        }

        $body   = $this->request->getJSON(true) ?? [];
        $action = strtolower(trim((string) ($body['action'] ?? $body['status'] ?? '')));
        $notes  = trim((string) ($body['response_notes'] ?? $body['notes'] ?? ''));

        if (!in_array($action, ['accepted', 'declined'], true)) {
            return $this->errorResponse("Action must be either 'accepted' or 'declined'.");
        }

        $updateData = [
            'status'         => $action,
            'response_notes' => $notes ?: null,
            'responded_by'   => $currentUserId,
            'responded_at'   => date('Y-m-d H:i:s'),
        ];

        $this->collabModel->update($id, $updateData);

        $unitMap = [1 => 'FGMU', 2 => 'LEAU', 3 => 'SSU'];
        $collabUnit = $unitMap[(int)$collab['collaborating_unit_id']] ?? 'Unit';

        // Notify requesting unit admins
        $reqAdmins = $this->userModel->where('role', 'admin')
                                     ->where('unit_id', (int)$collab['requesting_unit_id'])
                                     ->where('status', 'Active')
                                     ->findAll();

        $statusLabel = ucfirst($action);
        foreach ($reqAdmins as $adminUser) {
            $this->notifModel->createNotification(
                $adminUser['id'],
                'collaboration_response',
                "Collaboration {$statusLabel}: {$collab['ticket_id']}",
                "{$collabUnit} has {$action} the collaboration request for ticket #{$collab['ticket_id']}." . ($notes ? " Note: {$notes}" : "")
            );
        }

        // Log ticket action
        $this->logModel->logAction(
            $collab['ticket_id'],
            $currentUserId,
            $action === 'accepted' ? 'COLLABORATION_ACCEPTED' : 'COLLABORATION_DECLINED',
            "{$collabUnit} {$action} collaboration request." . ($notes ? " Note: {$notes}" : "")
        );

        $updated = $this->collabModel->find($id);
        return $this->successResponse("Collaboration request successfully {$action}.", $updated);
    }

    /**
     * Assign personnel from the collaborating unit to the collaborative ticket.
     * POST /api/v1/collaborations/:id/assign-personnel
     */
    public function assignPersonnel(int $id): ResponseInterface
    {
        $collab = $this->collabModel->find($id);
        if (!$collab) {
            return $this->notFoundResponse('Collaboration request');
        }

        if ($collab['status'] !== 'accepted') {
            return $this->errorResponse('Collaboration request must be accepted before assigning personnel.');
        }

        $currentUserId = $this->currentUserId();
        $userRole      = $this->currentUserRole();
        $userUnitId    = $this->currentUserUnitId();

        // Must be admin of collaborating unit (or primary unit / director / superadmin)
        if ($userRole === 'admin' && !in_array($userUnitId, [(int)$collab['collaborating_unit_id'], (int)$collab['requesting_unit_id']], true)) {
            return $this->forbiddenResponse('Unauthorized. Only participating unit heads can assign personnel.');
        }

        $body        = $this->request->getJSON(true) ?? [];
        $personnelId = sanitize_string($body['personnel_id'] ?? '');
        $taskNotes   = trim((string) ($body['task_notes'] ?? 'Cross-Unit Collaborative Assignment'));
        $implDate    = sanitize_string($body['implementation_date'] ?? date('Y-m-d'));
        $workingDays = max(1, (int) ($body['working_days'] ?? 1));

        if (empty($personnelId)) {
            return $this->errorResponse('personnel_id is required.');
        }

        $personnel = $this->personnelModel->find($personnelId);
        if (!$personnel) {
            return $this->notFoundResponse('Personnel member');
        }

        // Check if already assigned
        $existing = $this->assignmentModel->where('ticket_id', $collab['ticket_id'])
                                          ->where('personnel_id', $personnelId)
                                          ->where('completed_at IS NULL')
                                          ->first();
        if ($existing) {
            return $this->errorResponse("Worker '{$personnel['name']}' is already assigned to this ticket.");
        }

        $assignmentData = [
            'ticket_id'           => $collab['ticket_id'],
            'personnel_id'        => $personnelId,
            'implementation_date' => $implDate,
            'working_days'        => $workingDays,
            'task_notes'          => $taskNotes,
            'dispatcher_notes'    => "Cross-unit assignment via collaboration #{$collab['id']}",
            'assigned_at'         => date('Y-m-d H:i:s'),
            'status'              => 'active',
        ];

        $assignmentId = $this->assignmentModel->insert($assignmentData);
        if (!$assignmentId) {
            return $this->errorResponse('Failed to assign personnel to collaboration.');
        }

        // Log action on ticket
        $this->logModel->logAction(
            $collab['ticket_id'],
            $currentUserId,
            'PERSONNEL_ASSIGNED',
            "Shared worker assigned: {$personnel['name']} ({$personnel['specialty']}) for task: {$taskNotes}."
        );

        return $this->successResponse("Worker '{$personnel['name']}' successfully assigned to collaborative ticket.", [
            'assignment_id' => $assignmentId,
            'ticket_id'     => $collab['ticket_id'],
            'worker_name'   => $personnel['name'],
        ], ResponseInterface::HTTP_CREATED);
    }

    /**
     * Complete collaboration task.
     * PATCH /api/v1/collaborations/:id/complete
     */
    public function complete(int $id): ResponseInterface
    {
        $collab = $this->collabModel->find($id);
        if (!$collab) {
            return $this->notFoundResponse('Collaboration request');
        }

        if ($collab['status'] !== 'accepted') {
            return $this->errorResponse('Only accepted collaborations can be marked as completed.');
        }

        $currentUserId = $this->currentUserId();
        $userRole      = $this->currentUserRole();
        $userUnitId    = $this->currentUserUnitId();

        if ($userRole === 'admin' && !in_array($userUnitId, [(int)$collab['collaborating_unit_id'], (int)$collab['requesting_unit_id']], true)) {
            return $this->forbiddenResponse('Unauthorized. Only participating unit heads can mark collaboration completed.');
        }

        $body  = $this->request->getJSON(true) ?? [];
        $notes = trim((string) ($body['completion_notes'] ?? $body['notes'] ?? ''));

        $this->collabModel->update($id, [
            'status'         => 'completed',
            'completed_at'   => date('Y-m-d H:i:s'),
            'response_notes' => $notes ?: $collab['response_notes'],
        ]);

        $this->logModel->logAction(
            $collab['ticket_id'],
            $currentUserId,
            'COLLABORATION_COMPLETED',
            "Inter-unit collaboration completed." . ($notes ? " Completion Notes: {$notes}" : "")
        );

        return $this->successResponse('Collaboration successfully marked as completed.');
    }

    /**
     * Get collaborations involving the current user's unit.
     * GET /api/v1/collaborations/my-unit
     */
    public function myUnitCollaborations(): ResponseInterface
    {
        $userRole   = $this->currentUserRole();
        $userUnitId = $this->currentUserUnitId();

        if ($userRole === 'admin' && !$userUnitId) {
            return $this->forbiddenResponse('Unit admin has no assigned unit.');
        }

        $unitId    = (int) ($this->request->getGet('unit_id') ?: ($userUnitId ?: 1));
        $direction = $this->request->getGet('direction') ?: 'all';

        $collaborations = $this->collabModel->getUnitCollaborations($unitId, $direction);

        return $this->successResponse('Unit collaborations retrieved successfully.', [
            'unit_id'        => $unitId,
            'collaborations' => $collaborations,
        ]);
    }
}
