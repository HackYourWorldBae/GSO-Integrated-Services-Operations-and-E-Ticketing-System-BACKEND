<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\TicketModel;
use App\Models\FgmuTicketDetailModel;
use App\Models\LeauTicketDetailModel;
use App\Models\SsuIncidentDetailModel;
use App\Models\TicketAttachmentModel;
use App\Models\TicketLogModel;
use App\Models\NotificationModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * TicketController
 *
 * Handles the full ticket lifecycle from submission to archival.
 *
 * Endpoints:
 *  POST  /api/v1/tickets/intake           - Consolidated multi-service form submission
 *  GET   /api/v1/tickets/my-requests      - Requestor's own active tickets
 *  GET   /api/v1/tickets/completed        - Requestor's completed/archived tickets
 *  GET   /api/v1/tickets/queue/:unitCode  - Pending queue for an admin sub-unit
 *  GET   /api/v1/tickets/:id              - Single ticket detail view
 *  PATCH /api/v1/tickets/:id/approve      - Admin approves a ticket
 *  PATCH /api/v1/tickets/:id/decline      - Admin declines a ticket
 *  PATCH /api/v1/tickets/:id/complete     - Mark ticket as resolved/completed
 *  GET   /api/v1/tickets/:id/logs         - Audit trail for a ticket
 */
class TicketController extends BaseController
{
    private TicketModel $ticketModel;
    private TicketLogModel $logModel;
    private NotificationModel $notificationModel;

    // Unit code => Unit ID mapping (mirrors the seeds in the schema)
    private const UNIT_MAP = [
        'FGMU' => 1,
        'LEAU' => 2,
        'SSU'  => 3,
    ];

    public function __construct()
    {
        $this->ticketModel       = new TicketModel();
        $this->logModel          = new TicketLogModel();
        $this->notificationModel = new NotificationModel();
    }

    // -------------------------------------------------------------------------
    // Requestor Dashboard
    // -------------------------------------------------------------------------

    /**
     * Get active (non-archived) tickets for the currently authenticated user.
     */
    public function myRequests(): ResponseInterface
    {
        $userId  = $this->currentUserId();
        $tickets = $this->ticketModel->getActiveByUser($userId);
        $tickets = $this->enrichTickets($tickets);

        return $this->successResponse('Active tickets retrieved.', ['tickets' => $tickets]);
    }

    /**
     * Get completed/archived tickets for the currently authenticated user.
     */
    public function completedRequests(): ResponseInterface
    {
        $userId  = $this->currentUserId();
        $tickets = $this->ticketModel->getArchivedByUser($userId);
        $tickets = $this->enrichTickets($tickets);

        return $this->successResponse('Completed tickets retrieved.', ['tickets' => $tickets]);
    }

    /**
     * Requestor self-service cancellation of their own pending ticket.
     * Only tickets in 'pending' status can be cancelled.
     *
     * PATCH /api/v1/tickets/:id/cancel
     */
    public function cancel(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        $userId = $this->currentUserId();
        $role   = $this->currentUserRole();

        // Must be the owner of the ticket or an elevated administrator
        if ((string) $ticket['user_id'] !== (string) $userId && !in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('You do not have permission to cancel this ticket.');
        }

        // Only pending tickets can be cancelled by the requester
        if ($ticket['status'] !== 'pending') {
            return $this->errorResponse(
                "Ticket cannot be cancelled because its current status is '{$ticket['status']}'. Only pending requests can be cancelled.",
                [],
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }

        $body   = $this->request->getJSON(true) ?? [];
        $reason = sanitize_string($body['reason'] ?? $body['cancellation_reason'] ?? 'Cancelled by requestor');

        $this->ticketModel->update($ticketId, [
            'status'         => 'cancelled',
            'status_label'   => 'Cancelled by Requestor',
            'decline_reason' => $reason,
            'is_archived'    => 1,
            'completed_at'   => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction(
            $ticketId,
            $userId,
            'Cancelled',
            "Ticket cancelled by requestor. Reason: {$reason}"
        );

        $this->notificationModel->createNotification(
            $ticket['user_id'],
            'info',
            "Ticket #{$ticketId} Cancelled",
            "Your service request #{$ticketId} has been successfully cancelled."
        );

        return $this->successResponse('Ticket cancelled successfully.', [
            'ticket_id' => $ticketId,
            'status'    => 'cancelled'
        ]);
    }

    // -------------------------------------------------------------------------
    // Admin & Dispatcher Queues
    // -------------------------------------------------------------------------

    /**
     * Get the pending ticket queue for a given unit.
     */
    public function pendingQueue(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;

        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $tickets = $this->ticketModel->getPendingQueue($unitId);
        $tickets = $this->enrichTickets($tickets);

        return $this->successResponse('Pending queue retrieved.', ['tickets' => $tickets, 'count' => count($tickets)]);
    }

    /**
     * Get approved tickets awaiting dispatch for a given unit.
     */
    public function dispatchQueue(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;

        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $tickets = $this->ticketModel->getDispatchQueue($unitId);
        $tickets = $this->enrichTickets($tickets);

        return $this->successResponse('Dispatch queue retrieved.', ['tickets' => $tickets, 'count' => count($tickets)]);
    }

    /**
     * Get in-progress tickets for a unit (auto-starts tickets scheduled for today).
     */
    public function activeTickets(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;

        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $tickets = $this->ticketModel->getActiveTickets($unitId);
        $tickets = $this->enrichTickets($tickets);

        $today = date('Y-m-d');

        $personnelModel = new \App\Models\PersonnelModel();
        $assignmentModel = new \App\Models\TicketAssignmentModel();
        $logModel = new \App\Models\TicketLogModel();

        foreach ($tickets as &$ticket) {
            $currentStep = (int) $ticket['current_step'];
            $needsStart = ($currentStep === 4);
            
            if ($needsStart && !empty($ticket['assignment']['implementation_date'])) {
                if ($ticket['assignment']['implementation_date'] <= $today) {
                    $workerStatus = 'working';
                    $statusLabel  = 'Job Started';
                    $newStep      = 5;
                    $now          = date('Y-m-d H:i:s');

                    $this->ticketModel->update($ticket['id'], [
                        'status_label' => $statusLabel,
                        'current_step' => $newStep,
                        'updated_at'   => $now,
                    ]);

                    $assignments = $assignmentModel->getByTicket($ticket['id']);
                    foreach ($assignments as $assignment) {
                        if (!empty($assignment['personnel_id'])) {
                            $personnelModel->update($assignment['personnel_id'], [
                                'status'     => $workerStatus,
                                'updated_at' => $now,
                            ]);
                        }
                        // Stamp dispatched_at if not yet set
                        if (empty($assignment['dispatched_at'])) {
                            $dispatchedTime = !empty($assignment['assigned_at']) ? $assignment['assigned_at'] : $now;
                            $assignmentModel->update($assignment['id'], [
                                'dispatched_at' => $dispatchedTime,
                            ]);
                            if (isset($ticket['assignment']['id']) && $ticket['assignment']['id'] == $assignment['id']) {
                                $ticket['assignment']['dispatched_at'] = $dispatchedTime;
                            }
                        }
                    }

                    $logModel->logAction($ticket['id'], $this->currentUserId(), 'Job Started', "System automatically started the job based on implementation date.");
                    
                    $ticket['current_step'] = $newStep;
                    $ticket['status_label'] = $statusLabel;
                }
            }
        }
        unset($ticket);

        return $this->successResponse('Active tickets retrieved.', ['tickets' => $tickets, 'count' => count($tickets)]);
    }

    /**
     * Get archived tickets for a unit (with optional search/filter params).
     */
    public function archives(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;

        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $filters = [
            'search'    => sanitize_string($this->request->getGet('search') ?? ''),
            'status'    => sanitize_string($this->request->getGet('status') ?? ''),
            'date_from' => sanitize_string($this->request->getGet('date_from') ?? ''),
            'date_to'   => sanitize_string($this->request->getGet('date_to') ?? ''),
        ];

        $tickets = $this->ticketModel->getArchivedByUnit($unitId, $filters);
        $tickets = $this->enrichTickets($tickets);

        return $this->successResponse('Archived tickets retrieved.', ['tickets' => $tickets, 'count' => count($tickets)]);
    }

    /**
     * Get unit dashboard stats (pending, processing, resolved counts).
     */
    public function unitStats(string $unitCode): ResponseInterface
    {
        $filters = [
            'period'  => $this->request->getGet('period') ?? 'all',
            'year'    => $this->request->getGet('year'),
            'quarter' => $this->request->getGet('quarter'),
            'month'   => $this->request->getGet('month'),
        ];

        if (strtoupper($unitCode) === 'ALL') {
            if ($this->currentUserRole() !== 'director') {
                return $this->forbiddenResponse('Only the director role can access global statistics.');
            }
            $stats = $this->ticketModel->getAdvancedStatsByUnit(null, $filters);
            return $this->successResponse('Global statistics retrieved.', ['stats' => $stats]);
        }

        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;

        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $stats = $this->ticketModel->getAdvancedStatsByUnit($unitId, $filters);
        return $this->successResponse('Unit statistics retrieved.', ['stats' => $stats]);
    }

    // -------------------------------------------------------------------------
    // Single Ticket
    // -------------------------------------------------------------------------

    /**
     * Get a single ticket with its unit-specific detail data and attachments.
     */
    public function show(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        // Authorization check: only ticket owner or staff roles can view ticket details
        $userId = $this->currentUserId();
        $role   = $this->currentUserRole();
        if ((string) $ticket['user_id'] !== (string) $userId && !$this->isStaffRole()) {
            return $this->forbiddenResponse('You do not have permission to view this ticket.');
        }

        // For staff roles, also ensure unit jurisdiction (director has campus-wide access)
        if ($this->isStaffRole() && in_array($role, ['admin', 'dispatcher'], true)) {
            if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
                return $forbidden;
            }
        }

        // Enrich with unit-specific details
        $enriched = $this->enrichTickets([$ticket]);
        $ticket   = $enriched[0];

        // Attachments
        $attachmentModel      = new TicketAttachmentModel();
        $ticket['attachments'] = $attachmentModel->getByTicket($ticketId);

        // Logs
        $ticket['logs'] = $this->logModel->getByTicket($ticketId);

        return $this->successResponse('Ticket retrieved.', ['ticket' => $ticket]);
    }

    // -------------------------------------------------------------------------
    // Ticket Status Transitions
    // -------------------------------------------------------------------------

    /**
     * Admin approves a ticket (pending -> approved, step 1 -> 2).
     */
    public function approve(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        if ($ticket['status'] !== 'pending') {
            return $this->errorResponse("Only pending tickets can be approved. Current status: {$ticket['status']}.");
        }

        $unitId = (int) $ticket['unit_id'];
        $currentStep = 3;
        $newStatus   = 'approved';
        $statusLabel = 'Queued for Dispatch';
        $logMessage  = 'Ticket approved — queued for dispatch.';
        // SSU Incident Reports are handled by /investigate, /notation, and /resolve endpoints.

        $body = $this->request->getJSON(true) ?? [];
        $isEmergency = isset($body['is_emergency']) ? (!empty($body['is_emergency']) ? 1 : 0) : null;

        // Auto-heal/ensure is_emergency column exists in tickets table
        try {
            $db = \Config\Database::connect();
            if (!$db->fieldExists('is_emergency', 'tickets')) {
                $forge = \Config\Database::forge();
                $forge->addColumn('tickets', [
                    'is_emergency' => [
                        'type'       => 'TINYINT',
                        'constraint' => 1,
                        'default'    => 0,
                        'null'       => false,
                        'after'      => 'status_label',
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            log_message('warning', 'Schema check for tickets.is_emergency: ' . $e->getMessage());
        }

        $updateData = [
            'status'       => $newStatus,
            'status_label' => $statusLabel,
            'current_step' => $currentStep,
            'reviewed_at'  => date('Y-m-d H:i:s'),
            'reviewed_by'  => $this->currentUserId(),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        if ($isEmergency !== null) {
            $updateData['is_emergency'] = $isEmergency;
            if ($isEmergency === 1) {
                $logMessage = 'Ticket approved as EMERGENCY PRIORITY by Director — queued for immediate dispatch.';
            }
        }

        $this->ticketModel->update($ticketId, $updateData);

        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Status Changed', $logMessage);

        $notifTitle = ($isEmergency === 1) ? "Ticket #{$ticketId} Approved (Emergency Priority)" : "Ticket #{$ticketId} Approved";
        $notifBody  = ($isEmergency === 1)
            ? "Your request for {$ticket['service_type']} has been approved as an EMERGENCY request by the Director."
            : "Your request for {$ticket['service_type']} has been approved.";

        $this->notificationModel->createNotification(
            $ticket['user_id'],
            'success',
            $notifTitle,
            $notifBody
        );

        return $this->successResponse('Ticket approved successfully.', [
            'ticket_id'    => $ticketId, 
            'status'       => 'approved',
            'is_emergency' => $isEmergency ?? (int) ($ticket['is_emergency'] ?? 0)
        ]);
    }

    // -------------------------------------------------------------------------
    // SSU Incident Report — Workflow Endpoints
    // -------------------------------------------------------------------------

    /**
     * Marks an SSU Incident Report as "Under Investigation".
     * Ticket is moved to the investigating queue (status = processing) but NOT archived.
     *
     * PATCH /tickets/:id/investigate
     */
    public function setUnderInvestigation(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess(3)) {
            return $forbidden;
        }

        if ((int) $ticket['unit_id'] !== 3 || $ticket['service_type'] !== 'Incident Report') {
            return $this->errorResponse('This action is only valid for SSU Incident Reports.');
        }

        if ($ticket['is_archived']) {
            return $this->errorResponse('Archived tickets cannot be modified.');
        }

        $this->ticketModel->update($ticketId, [
            'is_under_investigation' => 1,
            'status'                 => 'processing',
            'status_label'           => 'Under Investigation',
            'current_step'           => 3,
            'reviewed_at'            => date('Y-m-d H:i:s'),
            'reviewed_by'            => $this->currentUserId(),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction(
            $ticketId,
            $this->currentUserId(),
            'Status Changed',
            'Incident report flagged as Under Investigation by SSU staff.'
        );

        $this->notificationModel->createNotification(
            $ticket['user_id'],
            'info',
            "Incident #{$ticketId} Under Investigation",
            'Your incident report is now being actively investigated by SSU staff.'
        );

        return $this->successResponse(
            'Ticket marked as Under Investigation.',
            ['ticket_id' => $ticketId, 'status' => 'processing']
        );
    }

    /**
     * Reverts an SSU Incident Report from "Under Investigation" back to the pending queue.
     * Clears is_under_investigation without archiving.
     *
     * PATCH /tickets/:id/uninvestigate
     */
    public function unsetUnderInvestigation(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess(3)) {
            return $forbidden;
        }

        if ((int) $ticket['unit_id'] !== 3 || $ticket['service_type'] !== 'Incident Report') {
            return $this->errorResponse('This action is only valid for SSU Incident Reports.');
        }

        if ($ticket['is_archived']) {
            return $this->errorResponse('Archived tickets cannot be modified.');
        }

        $hasNotation = !empty($ticket['ssu_notation']);

        $this->ticketModel->update($ticketId, [
            'is_under_investigation' => 0,
            'status'                 => $hasNotation ? 'processing' : 'pending',
            'status_label'           => $hasNotation ? 'Notation Added' : 'Pending Review',
            'current_step'           => $hasNotation ? 3 : 2,
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction(
            $ticketId,
            $this->currentUserId(),
            'Status Changed',
            'Incident removed from active investigation queue by SSU staff.'
        );

        return $this->successResponse(
            'Ticket removed from Under Investigation.',
            ['ticket_id' => $ticketId]
        );
    }

    /**
     * Adds a recommendation/notation to an SSU Incident Report.
     * Ticket remains open — this is a communication to the reporter, not a resolution.
     * The notation is displayed as an extension card in the requestor dashboard,
     * outside the progress timeline.
     *
     * PATCH /tickets/:id/notation
     */
    public function addNotation(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess(3)) {
            return $forbidden;
        }

        if ((int) $ticket['unit_id'] !== 3 || $ticket['service_type'] !== 'Incident Report') {
            return $this->errorResponse('This action is only valid for SSU Incident Reports.');
        }

        if ($ticket['is_archived']) {
            return $this->errorResponse('Archived tickets cannot receive notations.');
        }

        $body     = $this->request->getJSON(true) ?? [];
        $notation = trim(sanitize_string($body['notation'] ?? ''));

        if (empty($notation)) {
            return $this->errorResponse(
                'A notation text is required.',
                ['notation' => ['Required.']],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $isUnderInvestigation = (bool) $ticket['is_under_investigation'];

        $this->ticketModel->update($ticketId, [
            'ssu_notation' => $notation,
            'status'       => 'processing',
            'status_label' => $isUnderInvestigation ? 'Under Investigation' : 'Notation Added',
            'current_step' => 3,
            'reviewed_at'  => date('Y-m-d H:i:s'),
            'reviewed_by'  => $this->currentUserId(),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction(
            $ticketId,
            $this->currentUserId(),
            'Notation Added',
            'SSU staff added a recommendation/notation to the incident report.'
        );

        $this->notificationModel->createNotification(
            $ticket['user_id'],
            'info',
            "SSU Update on Incident #{$ticketId}",
            'SSU staff has added a recommendation/notation to your incident report. Please check your ticket for details.'
        );

        return $this->successResponse(
            'Notation added successfully.',
            ['ticket_id' => $ticketId, 'notation' => $notation]
        );
    }

    /**
     * Resolves and archives an SSU Incident Report.
     * A notation MUST have been added first (business rule).
     * Investigation is optional.
     *
     * PATCH /tickets/:id/resolve
     */
    public function resolveIncident(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess(3)) {
            return $forbidden;
        }

        if ((int) $ticket['unit_id'] !== 3 || $ticket['service_type'] !== 'Incident Report') {
            return $this->errorResponse('This action is only valid for SSU Incident Reports.');
        }

        if ($ticket['is_archived']) {
            return $this->errorResponse('Ticket is already archived.');
        }

        if (empty($ticket['ssu_notation'])) {
            return $this->errorResponse(
                'A recommendation/notation must be added before this incident can be resolved.',
                [],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->ticketModel->update($ticketId, [
            'status'       => 'resolved',
            'status_label' => 'Resolved',
            'current_step' => 4,
            'is_archived'  => 1,
            'completed_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction(
            $ticketId,
            $this->currentUserId(),
            'Status Changed',
            'Incident report resolved and archived by SSU staff.'
        );

        $this->notificationModel->createNotification(
            $ticket['user_id'],
            'success',
            "Incident #{$ticketId} Resolved",
            'Your incident report has been resolved by SSU staff and has been archived.'
        );

        return $this->successResponse(
            'Incident report resolved and archived.',
            ['ticket_id' => $ticketId, 'status' => 'resolved']
        );
    }

    /**
     * Fetch the Under Investigation queue for a unit (SSU-only in practice).
     *
     * GET /tickets/investigating/:unitCode
     */
    public function investigatingQueue(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;

        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $tickets = $this->ticketModel->getUnderInvestigationQueue($unitId);
        $tickets = $this->enrichTickets($tickets);

        return $this->successResponse(
            'Under investigation queue retrieved.',
            ['tickets' => $tickets, 'count' => count($tickets)]
        );
    }

    /**
     * Admin declines a ticket (pending -> declined) with a reason.
     */
    public function decline(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        if ($ticket['status'] !== 'pending') {
            return $this->errorResponse("Only pending tickets can be declined. Current status: {$ticket['status']}.");
        }

        $body   = $this->request->getJSON(true) ?? [];
        $reason = sanitize_string($body['decline_reason'] ?? '');

        if (empty($reason)) {
            return $this->errorResponse('A decline reason is required.', ['decline_reason' => ['Required.']], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->ticketModel->update($ticketId, [
            'status'        => 'declined',
            'status_label'  => 'Declined',
            'decline_reason'=> $reason,
            'is_archived'   => 1,
            'current_step'  => 2,
            'reviewed_at'   => date('Y-m-d H:i:s'),
            'reviewed_by'   => $this->currentUserId(),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Declined', "Ticket declined. Reason: {$reason}");

        $this->notificationModel->createNotification(
            $ticket['user_id'], 
            'warning', 
            "Ticket #{$ticketId} Declined", 
            "Your request was declined. Reason: {$reason}"
        );

        return $this->successResponse('Ticket declined.', ['ticket_id' => $ticketId, 'status' => 'declined']);
    }

    /**
     * Extend a project or ticket timeline due to unforeseen circumstances (Admins & Dispatchers).
     *
     * Body:
     * {
     *   extension_days?: int,
     *   extension_reason: string,
     *   extended_date?: string (Y-m-d),
     *   overtime_hours?: float
     * }
     */
    public function extendTicket(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        $body = $this->request->getJSON(true) ?? [];
        $extensionDays = (int) ($body['extension_days'] ?? 0);
        $reason        = sanitize_string($body['extension_reason'] ?? '');
        $overtime      = (float) ($body['overtime_hours'] ?? 0.0);

        if (empty($reason)) {
            return $this->errorResponse('An extension reason is required explaining the unforeseen circumstances.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($extensionDays <= 0 && empty($body['extended_date']) && $overtime <= 0) {
            return $this->errorResponse('Please specify extension working days, a new target date, or overtime hours.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Calculate new target date using WorkCalendar
        $baseDate = !empty($ticket['extended_completion_date'])
            ? new \DateTime($ticket['extended_completion_date'])
            : (!empty($ticket['assignment']['implementation_date'])
                ? new \DateTime($ticket['assignment']['implementation_date'])
                : (!empty($ticket['project_target_date'])
                    ? new \DateTime($ticket['project_target_date'])
                    : new \DateTime()));

        $extendedDate = !empty($body['extended_date'])
            ? sanitize_string($body['extended_date'])
            : \App\Libraries\WorkCalendar::addWorkingDays($baseDate, max(1, $extensionDays))->format('Y-m-d');

        $totalExtensionDays = (int) ($ticket['extension_days'] ?? 0) + max(0, $extensionDays);
        $totalOvertime      = (float) ($ticket['overtime_hours'] ?? 0) + max(0.0, $overtime);

        $updateData = [
            'extension_days'           => $totalExtensionDays,
            'extended_completion_date' => $extendedDate,
            'extension_reason'         => $reason,
            'overtime_hours'           => $totalOvertime,
            'updated_at'               => date('Y-m-d H:i:s'),
        ];

        $this->ticketModel->update($ticketId, $updateData);

        // Update active assignment's implementation date & overtime hours
        $db = Database::connect();
        $db->table('ticket_assignments')
           ->where('ticket_id', $ticketId)
           ->where('completed_at IS NULL')
           ->update([
               'implementation_date' => $extendedDate,
               'overtime_hours'      => $totalOvertime,
           ]);

        // Audit log
        $logDetails = "Timeline Extended: +{$extensionDays} working day(s) to {$extendedDate}. Reason: {$reason}";
        if ($overtime > 0) {
            $logDetails .= " (Overtime logged: +{$overtime} hrs)";
        }
        $logModel = new \App\Models\TicketLogModel();
        $logModel->logAction($ticketId, $this->currentUserId(), 'Timeline Extended', $logDetails);

        // Notification to requestor
        $notificationModel = new \App\Models\NotificationModel();
        $notificationModel->createNotification(
            $ticket['user_id'],
            'warning',
            "Ticket #{$ticketId} Timeline Extended",
            "Project schedule adjusted to {$extendedDate} due to unforeseen circumstance: {$reason}"
        );

        return $this->successResponse('Ticket timeline extended successfully.', [
            'ticket_id'                => $ticketId,
            'extension_days'           => $totalExtensionDays,
            'extended_completion_date' => $extendedDate,
            'overtime_hours'           => $totalOvertime,
        ]);
    }

    /**
     * Mark a ticket as completed/resolved (processing -> resolved/closed).
     *
     * Body (Optional for FGMU/LEAU):
     * {
     *   materials?: [
     *     { material_name: string, quantity: number, unit_measurement: string, unit_price: number, total_price?: number }
     *   ],
     *   dispatcher_notes?: string
     * }
     */
    public function complete(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        if (!in_array($ticket['status'], ['processing', 'approved', 'resolved'])) {
            return $this->errorResponse("Only processing or approved tickets can be marked as completed.");
        }

        $body = $this->request->getJSON(true) ?? [];
        $unitId   = (int) $ticket['unit_id'];
        $unitCode = array_search($unitId, self::UNIT_MAP);

        $db = Database::connect();
        $db->transStart();

        // 1. Process Materials if provided (or if empty array sent to explicitly confirm liquidation)
        $materialsLogged = !empty($ticket['materials_logged']) ? 1 : 0;
        $activeAssignment = $db->query(
            "SELECT id FROM ticket_assignments WHERE ticket_id = ? ORDER BY assigned_at DESC LIMIT 1",
            [$ticketId]
        )->getRowArray();
        $assignmentId = $activeAssignment['id'] ?? null;

        if (isset($body['materials']) && is_array($body['materials'])) {
            $materialModel = new \App\Models\TicketMaterialModel();
            
            // Delete existing materials for this ticket to avoid duplicate submission on update
            $db->query("DELETE FROM ticket_materials WHERE ticket_id = ?", [$ticketId]);
            if ($assignmentId) {
                $db->query("DELETE FROM ticket_materials WHERE assignment_id = ?", [$assignmentId]);
            }

            foreach ($body['materials'] as $mat) {
                $name = sanitize_string($mat['material_name'] ?? $mat['name'] ?? '');
                if (empty($name)) {
                    continue;
                }

                $qty   = max(0.01, (float) ($mat['quantity'] ?? 1));
                $unit  = sanitize_string($mat['unit_measurement'] ?? $mat['unit'] ?? 'pcs');
                $price = max(0.00, (float) ($mat['unit_price'] ?? $mat['price'] ?? 0));
                $total = isset($mat['total_price']) ? (float) $mat['total_price'] : ($qty * $price);

                $materialModel->insert([
                    'ticket_id'        => $ticketId,
                    'assignment_id'    => $assignmentId,
                    'material_name'    => $name,
                    'quantity'         => $qty,
                    'unit_measurement' => $unit,
                    'unit_price'       => $price,
                    'total_price'      => $total,
                    'created_at'       => date('Y-m-d H:i:s'),
                ]);
            }

            $materialsLogged = 1;
        }

        // Check if user feedback already exists
        $feedbackModel = new \App\Models\TicketFeedbackModel();
        $hasFeedback   = !empty($feedbackModel->getByTicket($ticketId));

        // Determine status and archival state
        $isArchived = 0;
        // FGMU / LEAU
        if ($hasFeedback && $materialsLogged) {
            $isArchived  = 1;
            $newStatus   = 'closed';
            $statusLabel = 'Closed';
        } elseif ($materialsLogged) {
            $isArchived  = 0;
            $newStatus   = 'resolved';
            $statusLabel = 'Awaiting User Rating';
        } else {
            $isArchived  = 0;
            $newStatus   = 'resolved';
            $statusLabel = 'Awaiting Material Liquidation';
        }

        $updateData = [
            'status'           => $newStatus,
            'status_label'     => $statusLabel,
            'current_step'     => 6,
            'materials_logged' => $materialsLogged,
            'is_archived'      => $isArchived,
            'completed_at'     => $ticket['completed_at'] ?? date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ];

        $this->ticketModel->update($ticketId, $updateData);

        // Mark active assignment as completed and set worker to available
        $assignments = $db->query(
            "SELECT personnel_id FROM ticket_assignments WHERE ticket_id = ? AND completed_at IS NULL",
            [$ticketId]
        )->getResultArray();

        $personnelModel = new \App\Models\PersonnelModel();

        foreach ($assignments as $a) {
            if (!empty($a['personnel_id'])) {
                $curWorker = $personnelModel->find($a['personnel_id']);
                // Only set back to 'available' if the worker is not currently 'on_leave'
                if ($curWorker && $curWorker['status'] !== 'on_leave') {
                    $personnelModel->update($a['personnel_id'], [
                        'status'     => 'available',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }

        $assignmentUpdate = ['completed_at' => date('Y-m-d H:i:s')];
        if (!empty($body['dispatcher_notes'])) {
            $assignmentUpdate['dispatcher_notes'] = sanitize_string($body['dispatcher_notes']);
        }
        $db->table('ticket_assignments')
           ->where('ticket_id', $ticketId)
           ->where('completed_at IS NULL')
           ->update($assignmentUpdate);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->errorResponse('Failed to complete ticket.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $logMessage = ($materialsLogged && !empty($body['materials']))
            ? "Job Completed & Materials Logged (" . count($body['materials']) . " items listed)."
            : "Ticket marked as completed.";

        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Status Changed', $logMessage);

        $this->notificationModel->createNotification(
            $ticket['user_id'], 
            'success', 
            "Ticket #{$ticketId} Completed", 
            "Your request for {$ticket['service_type']} has been marked as completed."
        );

        return $this->successResponse('Ticket completed successfully.', [
            'ticket_id'        => $ticketId, 
            'status'           => $newStatus,
            'status_label'     => $statusLabel,
            'materials_logged' => $materialsLogged,
            'is_archived'      => $isArchived,
        ]);
    }

    // -------------------------------------------------------------------------
    // Consolidated Multi-Service Intake
    // -------------------------------------------------------------------------

    /**
     * Submit a consolidated service request from the ServicesListView/FormsView flow.
     *
     * Accepts a multi-unit payload:
     * {
     *   fgmu: { services: [...], details: {...} },
     *   leau: { services: [...], details: {...} },
     *   ssu:  { incidentReport: {...} },
     *   others: { description: "..." }
     * }
     *
     * All inserts are wrapped in a single DB transaction.
     */
    public function submitIntake(): ResponseInterface
    {
        $body   = $this->request->getJSON(true) ?? [];
        $userId = $this->currentUserId();

        $userModel = new \App\Models\UserModel();
        $user = $userModel->find($userId);
        if (!$user || empty($user['is_verified']) || (int)$user['is_verified'] !== 1) {
            return $this->errorResponse('Your account is pending verification by the Super Administrator before you can submit service requests.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        if (empty($body)) {
            return $this->errorResponse('Request body is empty.');
        }

        $db             = Database::connect();
        $createdTickets = [];

        $db->transStart();

        try {
            // --- 1. FGMU Intake ---
            if (!empty($body['fgmu']['services'])) {
                $fgmuDetails = sanitize_array($body['fgmu']['details'] ?? []);
                $fgmuModel   = new FgmuTicketDetailModel();

                $servicesList = array_map(function($srv) {
                    return sanitize_string($srv['service'] ?? '');
                }, $body['fgmu']['services']);
                $servicesList = array_filter($servicesList);

                if (!empty($servicesList)) {
                    $serviceString = $this->formatServicesList($servicesList);
                    if (empty($serviceString)) {
                        $serviceString = 'Facilities Maintenance';
                    }

                    $ticketId = $this->ticketModel->generateTicketId('FGMU', self::UNIT_MAP['FGMU'], 0);

                    $this->ticketModel->insert([
                        'id'          => $ticketId,
                        'user_id'     => $userId,
                        'unit_id'     => self::UNIT_MAP['FGMU'],
                        'title'       => sanitize_string($fgmuDetails['ticket_title'] ?? $serviceString),
                        'service_type'=> $serviceString,
                        'description' => sanitize_string($fgmuDetails['job_description'] ?? 'Facilities maintenance request.'),
                        'status'      => 'pending',
                        'status_label'=> 'Pending Approval',
                        'current_step'=> 2,
                        'location'    => sanitize_string($fgmuDetails['college_building'] ?? ''),
                        'office_room' => sanitize_string($fgmuDetails['office_room'] ?? ''),
                        'submitted_at'=> date('Y-m-d H:i:s'),
                        'updated_at'  => date('Y-m-d H:i:s'),
                    ]);

                    $fgmuModel->insert([
                        'ticket_id'       => $ticketId,
                        'college_building'=> sanitize_string($fgmuDetails['college_building'] ?? ''),
                        'office_room'     => sanitize_string($fgmuDetails['office_room'] ?? ''),
                        'source_of_fund'  => sanitize_string($fgmuDetails['source_of_fund'] ?? ''),
                    ]);

                    $this->logModel->logAction($ticketId, $userId, 'Ticket Submitted', "FGMU service request: {$serviceString}");
                    $createdTickets[] = $ticketId;
                }
            }

            // --- 2. LEAU Intake ---
            if (!empty($body['leau']['services'])) {
                $leauDetails = sanitize_array($body['leau']['details'] ?? []);
                $leauModel   = new LeauTicketDetailModel();

                $servicesList = array_map(function($srv) {
                    return sanitize_string($srv['service'] ?? '');
                }, $body['leau']['services']);
                $servicesList = array_filter($servicesList);

                if (!empty($servicesList)) {
                    $serviceString = $this->formatServicesList($servicesList);
                    if (empty($serviceString)) {
                        $serviceString = 'Janitorial & Landscaping';
                    }

                    $ticketId = $this->ticketModel->generateTicketId('LEAU', self::UNIT_MAP['LEAU'], 0);

                    $this->ticketModel->insert([
                        'id'          => $ticketId,
                        'user_id'     => $userId,
                        'unit_id'     => self::UNIT_MAP['LEAU'],
                        'title'       => sanitize_string($leauDetails['ticket_title'] ?? $serviceString),
                        'service_type'=> $serviceString,
                        'description' => sanitize_string($leauDetails['job_description'] ?? 'Grounds maintenance request.'),
                        'status'      => 'pending',
                        'status_label'=> 'Pending Approval',
                        'current_step'=> 2,
                        'location'    => sanitize_string($leauDetails['college_building'] ?? ''),
                        'office_room' => sanitize_string($leauDetails['office_room'] ?? ''),
                        'submitted_at'=> date('Y-m-d H:i:s'),
                        'updated_at'  => date('Y-m-d H:i:s'),
                    ]);

                    $leauModel->insert([
                        'ticket_id'       => $ticketId,
                        'college_building'=> sanitize_string($leauDetails['college_building'] ?? ''),
                        'office_room'     => sanitize_string($leauDetails['office_room'] ?? ''),
                        'source_of_fund'  => sanitize_string($leauDetails['source_of_fund'] ?? ''),
                    ]);

                    $this->logModel->logAction($ticketId, $userId, 'Ticket Submitted', "LEAU service request: {$serviceString}");
                    $createdTickets[] = $ticketId;
                }
            }

            // --- 3. SSU Incident Report ---
            if (!empty($body['ssu']['incidentReport'])) {
                $inc      = sanitize_array($body['ssu']['incidentReport']);
                $ticketId = $this->ticketModel->generateTicketId('SSU', self::UNIT_MAP['SSU']);
                $typeStr  = is_array($inc['incidents'] ?? null) ? implode(', ', $inc['incidents']) : ($inc['otherIncident'] ?? 'Incident');

                $this->ticketModel->insert([
                    'id'                    => $ticketId,
                    'user_id'               => $userId,
                    'unit_id'               => self::UNIT_MAP['SSU'],
                    'title'                 => 'Incident Report',
                    'service_type'          => 'Incident Report',
                    'description'           => sanitize_string($inc['how'] ?? 'Incident reported to campus security.'),
                    'status'                => 'pending',
                    'status_label'          => 'Pending Review',
                    'current_step'          => 2,
                    'is_under_investigation'=> 0,
                    'location'              => sanitize_string($inc['where'] ?? ''),
                    'submitted_at'          => date('Y-m-d H:i:s'),
                    'updated_at'            => date('Y-m-d H:i:s'),
                ]);

                (new SsuIncidentDetailModel())->insert([
                    'ticket_id'        => $ticketId,
                    'other_incident'   => sanitize_string($inc['otherIncident'] ?? ''),
                    'other_information'=> sanitize_string($inc['otherInformation'] ?? ''),
                    'follow_up'        => (bool) ($inc['followUp'] ?? false),
                    'who_involved'     => sanitize_string($inc['who'] ?? ''),
                    'where_occurred'   => sanitize_string($inc['where'] ?? ''),
                    'when_occurred'    => sanitize_string($inc['when'] ?? ''),
                    'how_narrative'    => sanitize_string($inc['how'] ?? ''),
                    'reporter_name'    => sanitize_string($inc['reportedBy']['printedName'] ?? ''),
                    'reporter_signature'=> $inc['reportedBy']['signature'] ?? null,
                ]);

                // Bridge table inserts for incident types, issues, and roles (3NF normalization)
                if (!empty($inc['incidents']) && is_array($inc['incidents'])) {
                    foreach ($inc['incidents'] as $type) {
                        $cleanType = sanitize_string($type);
                        $db->query("INSERT IGNORE INTO ssu_incident_types (type_name) VALUES (?)", [$cleanType]);
                        $row = $db->query("SELECT id FROM ssu_incident_types WHERE type_name = ?", [$cleanType])->getRowArray();
                        if ($row) {
                            $db->query("INSERT IGNORE INTO ssu_incident_type_items (ticket_id, incident_type_id) VALUES (?, ?)", [$ticketId, $row['id']]);
                        }
                    }
                }

                if (!empty($inc['information']) && is_array($inc['information'])) {
                    foreach ($inc['information'] as $info) {
                        $cleanInfo = sanitize_string($info);
                        $db->query("INSERT IGNORE INTO ssu_incident_issues (issue_name) VALUES (?)", [$cleanInfo]);
                        $row = $db->query("SELECT id FROM ssu_incident_issues WHERE issue_name = ?", [$cleanInfo])->getRowArray();
                        if ($row) {
                            $db->query("INSERT IGNORE INTO ssu_incident_issue_items (ticket_id, issue_id) VALUES (?, ?)", [$ticketId, $row['id']]);
                        }
                    }
                }

                $roles = $inc['reportedBy']['roles'] ?? [];
                if (!empty($roles) && is_array($roles)) {
                    foreach ($roles as $role) {
                        $cleanRole = sanitize_string($role);
                        $db->query("INSERT IGNORE INTO ssu_incident_roles (role_name) VALUES (?)", [$cleanRole]);
                        $row = $db->query("SELECT id FROM ssu_incident_roles WHERE role_name = ?", [$cleanRole])->getRowArray();
                        if ($row) {
                            $db->query("INSERT IGNORE INTO ssu_incident_role_items (ticket_id, role_id) VALUES (?, ?)", [$ticketId, $row['id']]);
                        }
                    }
                }

                $this->logModel->logAction($ticketId, $userId, 'Ticket Submitted', "SSU Incident Report: {$typeStr}");
                $createdTickets[] = $ticketId;
            }



        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[TicketController::submitIntake] DB Error: ' . $e->getMessage());

            return $this->errorResponse(
                'An error occurred while submitting your request. Please try again.',
                [],
                ResponseInterface::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->errorResponse(
                'Transaction failed. Please try again.',
                [],
                ResponseInterface::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        // --- Notify Admins ---
        foreach ($createdTickets as $tId) {
            $t = $this->ticketModel->find($tId);
            if ($t) {
                $admins = $db->query("SELECT id FROM users WHERE role IN ('admin', 'dispatcher') AND unit_id = ?", [$t['unit_id']])->getResultArray();
                foreach($admins as $admin) {
                    $this->notificationModel->createNotification(
                        $admin['id'], 
                        'info', 
                        "New Ticket Submitted", 
                        "Ticket #{$tId} for {$t['service_type']} requires review."
                    );
                }
            }
        }

        return $this->successResponse(
            count($createdTickets) . ' ticket(s) submitted successfully.',
            ['ticket_ids' => $createdTickets],
            ResponseInterface::HTTP_CREATED
        );
    }

    // -------------------------------------------------------------------------
    // Scheduled Projects (FGMU & LEAU Announcements)
    // -------------------------------------------------------------------------

    public function createProject(): ResponseInterface
    {
        $userId = $this->currentUserId();
        $role   = $this->currentUserRole();
        // Only allow admins or superadmins to create project announcements
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->errorResponse('Unauthorized to create project announcements. Only Unit Admins may post projects.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        $body = $this->request->getJSON(true) ?? [];
        $unitCode = strtoupper(sanitize_string($body['unit'] ?? ''));
        $unitId = self::UNIT_MAP[$unitCode] ?? null;

        if (!$unitId || !in_array($unitCode, ['FGMU', 'LEAU'])) {
            return $this->errorResponse('Projects can only be created for FGMU or LEAU.');
        }

        if ($forbidden = $this->assertUnitAccess($unitId)) {
            return $forbidden;
        }

        $title = sanitize_string($body['title'] ?? '');
        $description = sanitize_string($body['description'] ?? '');
        $location = sanitize_string($body['location'] ?? '');
        $duration = sanitize_string($body['duration'] ?? ''); // Duration e.g. "5 Working Days" or "5"
        $targetDate = sanitize_string($body['target_date'] ?? null);
        $targetDate = empty($targetDate) ? null : $targetDate;
        $remarks = sanitize_string($body['remarks'] ?? '');

        if (empty($title)) {
            return $this->errorResponse('Project title is required.');
        }

        $db = Database::connect();
        $db->transStart();

        try {
            // Generate dedicated Project ID with -PRJ- prefix (does not consume ticket -TIC- sequence)
            $projectId = $this->ticketModel->generateProjectId($unitCode, $unitId);

            $this->ticketModel->insert([
                'id'                      => $projectId,
                'user_id'                 => $userId, // The admin who created it
                'unit_id'                 => $unitId,
                'service_type'            => 'Office Project',
                'description'             => !empty($description) ? $description : $title,
                'status'                  => 'approved', // Auto-approve
                'status_label'            => 'Approved',
                'current_step'            => 2,
                'location'                => $location,
                'is_project'              => 1,
                'project_title'           => $title,
                'project_target_duration' => $duration,
                'project_target_date'     => $targetDate,
                'project_manpower'        => null,
                'project_remarks'         => $remarks,
                'submitted_at'            => date('Y-m-d H:i:s'),
                'reviewed_at'             => date('Y-m-d H:i:s'),
                'reviewed_by'             => $userId,
                'updated_at'              => date('Y-m-d H:i:s'),
            ]);

            // Also create the corresponding detail record
            if ($unitCode === 'FGMU') {
                (new FgmuTicketDetailModel())->insert([
                    'ticket_id'        => $projectId,
                    'college_building' => $location,
                    'office_room'      => '',
                ]);
            } else if ($unitCode === 'LEAU') {
                (new LeauTicketDetailModel())->insert([
                    'ticket_id'        => $projectId,
                    'college_building' => $location,
                    'office_room'      => '',
                ]);
            }

            $this->logModel->logAction($projectId, $userId, 'Project Created', "Scheduled project announcement created.");

        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[TicketController::createProject] ' . $e->getMessage());
            return $this->errorResponse('Failed to create project.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->errorResponse('Transaction failed.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->successResponse('Project announcement created successfully.', ['ticket_id' => $projectId, 'id' => $projectId], ResponseInterface::HTTP_CREATED);
    }

    public function getProjects(): ResponseInterface
    {
        $tickets = $this->ticketModel->where('is_project', 1)
                                     ->whereIn('status', ['pending', 'approved', 'processing'])
                                     ->orderBy('submitted_at', 'DESC')
                                     ->findAll();
        
        $tickets = $this->enrichTickets($tickets);
        return $this->successResponse('Active projects retrieved.', ['projects' => $tickets]);
    }

    public function getProjectArchives(): ResponseInterface
    {
        $tickets = $this->ticketModel->where('is_project', 1)
                                     ->whereIn('status', ['resolved', 'closed', 'completed'])
                                     ->orderBy('completed_at', 'DESC')
                                     ->findAll();
        
        $tickets = $this->enrichTickets($tickets);
        return $this->successResponse('Archived projects retrieved.', ['projects' => $tickets]);
    }

    public function updateProject(string $ticketId): ResponseInterface
    {
        $userId = $this->currentUserId();
        $role   = $this->currentUserRole();
        // Only allow admins or superadmins to update project announcements
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->errorResponse('Unauthorized to update project announcements. Only Unit Admins may manage projects.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket || !(bool)$ticket['is_project']) {
            return $this->notFoundResponse('Project');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        $body = $this->request->getJSON(true) ?? [];
        
        $updateData = ['updated_at' => date('Y-m-d H:i:s')];
        if (array_key_exists('actual_start', $body)) {
            $val = sanitize_string($body['actual_start'] ?? null);
            $updateData['project_actual_start'] = empty($val) ? null : $val;
        }
        if (array_key_exists('actual_completion', $body)) {
            $val = sanitize_string($body['actual_completion'] ?? null);
            $updateData['project_actual_completion'] = empty($val) ? null : $val;
        }
        if (array_key_exists('actual_working_days', $body)) {
            $updateData['project_working_days'] = !empty($body['actual_working_days']) ? (int) $body['actual_working_days'] : null;
        }
        if (array_key_exists('remarks', $body)) {
            $updateData['project_remarks'] = sanitize_string($body['remarks'] ?? '');
        }

        $this->ticketModel->update($ticketId, $updateData);
        $this->logModel->logAction($ticketId, $userId, 'Project Updated', "Project details were updated.");

        return $this->successResponse('Project updated successfully.', ['ticket_id' => $ticketId]);
    }

    /**
     * Get audit trail logs for a ticket.
     */
    public function logs(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        // Authorization check: only ticket owner or staff roles can view ticket audit logs
        $userId = $this->currentUserId();
        $role   = $this->currentUserRole();
        if ((string) $ticket['user_id'] !== (string) $userId && !$this->isStaffRole()) {
            return $this->forbiddenResponse('You do not have permission to view logs for this ticket.');
        }

        // For staff roles, also ensure unit jurisdiction (director has campus-wide access)
        if ($this->isStaffRole() && in_array($role, ['admin', 'dispatcher'], true)) {
            if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
                return $forbidden;
            }
        }

        $logs = $this->logModel->getByTicket($ticketId);

        return $this->successResponse('Ticket logs retrieved.', ['logs' => $logs]);
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Enrich a list of tickets with their unit-specific details.
     * Uses a single query per detail type to avoid N+1 queries.
     */
    private function enrichTickets(array $tickets): array
    {
        if (empty($tickets)) {
            return $tickets;
        }

        $ticketIds = array_column($tickets, 'id');
        $db        = Database::connect();

        // Load all relevant detail records in bulk
        $fgmuDetails  = $this->buildDetailMap($db, 'fgmu_ticket_details',      'ticket_id', $ticketIds);
        $leauDetails  = $this->buildDetailMap($db, 'leau_ticket_details',      'ticket_id', $ticketIds);
        $ssuIrDetails = $this->buildDetailMap($db, 'ssu_incident_details',     'ticket_id', $ticketIds);
        $assignmentsData = $this->buildAssignmentMap($db, $ticketIds);
        $assignments     = $assignmentsData['single'] ?? [];
        $assignmentsList = $assignmentsData['all'] ?? [];
        $feedbacks       = $this->buildDetailMap($db, 'ticket_feedbacks',         'ticket_id', $ticketIds);
        $materialsMap    = $this->buildMaterialsMap($db, $ticketIds);

        // Enrich SSU Incident Reports with 3NF bridge table arrays (incidents, information, roles)
        if (!empty($ssuIrDetails)) {
            $irIds = array_keys($ssuIrDetails);
            $placeholders = implode(',', array_fill(0, count($irIds), '?'));

            $typeRows = $db->query("
                SELECT i.ticket_id, t.type_name 
                FROM ssu_incident_type_items i 
                JOIN ssu_incident_types t ON t.id = i.incident_type_id 
                WHERE i.ticket_id IN ({$placeholders})
            ", $irIds)->getResultArray();

            $issueRows = $db->query("
                SELECT i.ticket_id, t.issue_name 
                FROM ssu_incident_issue_items i 
                JOIN ssu_incident_issues t ON t.id = i.issue_id 
                WHERE i.ticket_id IN ({$placeholders})
            ", $irIds)->getResultArray();

            $roleRows = $db->query("
                SELECT i.ticket_id, t.role_name 
                FROM ssu_incident_role_items i 
                JOIN ssu_incident_roles t ON t.id = i.role_id 
                WHERE i.ticket_id IN ({$placeholders})
            ", $irIds)->getResultArray();

            foreach ($ssuIrDetails as $id => &$detail) {
                $detail['incidents']   = array_values(array_column(array_filter($typeRows,  fn($r) => $r['ticket_id'] === $id), 'type_name'));
                $detail['information'] = array_values(array_column(array_filter($issueRows, fn($r) => $r['ticket_id'] === $id), 'issue_name'));
                $detail['roles']       = array_values(array_column(array_filter($roleRows,  fn($r) => $r['ticket_id'] === $id), 'role_name'));
                $detail['reportedBy']  = [
                    'printedName' => $detail['reporter_name'] ?? '',
                    'signature'   => $detail['reporter_signature'] ?? '',
                    'roles'       => $detail['roles'],
                ];
            }
            unset($detail);
        }

        $attachmentsMap = $this->buildAttachmentMap($db, $ticketIds);

        foreach ($tickets as &$ticket) {
            $id = $ticket['id'];
            $ticket['unit_id'] = (int) $ticket['unit_id'];
            
            // Critical for frontend timeline and categorizations
            $ticket['unit_code'] = array_search($ticket['unit_id'], self::UNIT_MAP) ?: null;

            $ticket['details'] = match((int) $ticket['unit_id']) {
                1       => $fgmuDetails[$id]  ?? null,
                2       => $leauDetails[$id]  ?? null,
                3       => $ssuIrDetails[$id] ?? null,
                default => null,
            };

            $ticket['assignment']          = $assignments[$id] ?? null;
            $ticket['assignments']         = $assignmentsList[$id] ?? [];
            $ticket['attachments']         = $attachmentsMap[$id] ?? [];
            $ticket['feedback']            = $feedbacks[$id] ?? null;
            $ticket['materials']           = $materialsMap[$id] ?? [];
            $ticket['total_material_cost'] = array_sum(array_column($ticket['materials'], 'total_price'));
            $ticket['materials_logged']    = !empty($ticket['materials_logged']) || !empty($ticket['materials']);
            $ticket['working_days']        = !empty($ticket['project_working_days']) 
                ? (int) $ticket['project_working_days'] 
                : (!empty($ticket['assignment']['working_days']) ? (int) $ticket['assignment']['working_days'] : null);

            $ticket['extension_days']           = (int) ($ticket['extension_days'] ?? 0);
            $ticket['extended_completion_date'] = $ticket['extended_completion_date'] ?? null;
            $ticket['extension_reason']         = $ticket['extension_reason'] ?? null;
            $ticket['overtime_hours']           = (float) ($ticket['overtime_hours'] ?? 0.0);
            $ticket['is_extended']              = ($ticket['extension_days'] > 0) || !empty($ticket['extended_completion_date']);
            $ticket['effective_target_date']    = !empty($ticket['extended_completion_date'])
                ? $ticket['extended_completion_date']
                : (!empty($ticket['assignment']['implementation_date'])
                    ? $ticket['assignment']['implementation_date']
                    : (!empty($ticket['project_target_date']) ? $ticket['project_target_date'] : null));

            $ticket['accomplishment_report_path'] = $ticket['accomplishment_report_path'] ?? null;
            $ticket['accomplishment_notes']       = $ticket['accomplishment_notes'] ?? null;
            $ticket['verification_status']        = $ticket['verification_status'] ?? 'pending_report';
            $ticket['verified_by_user_id']        = $ticket['verified_by_user_id'] ?? null;
            $ticket['verified_at']                = $ticket['verified_at'] ?? null;
            $ticket['eodb_tier']                  = $ticket['eodb_tier'] ?? null;
            $ticket['eodb_days']                  = !empty($ticket['eodb_days']) ? (int) $ticket['eodb_days'] : null;
            $ticket['target_completion_date']     = $ticket['target_completion_date'] ?? null;
            $ticket['is_emergency']               = (int) ($ticket['is_emergency'] ?? 0);
            $ticket['is_vip']                     = (int) ($ticket['is_vip'] ?? 0);

            // Compute business working hours (skipping weekends & holidays)
            $startTimeStr = $ticket['assignment']['dispatched_at'] 
                ?? $ticket['assignment']['assigned_at'] 
                ?? $ticket['project_actual_start'] 
                ?? $ticket['assignment']['implementation_date'] 
                ?? null;

            if ($startTimeStr && in_array($ticket['status'], ['processing', 'resolved', 'closed'], true)) {
                try {
                    // If date-only string (e.g. YYYY-MM-DD), anchor to standard work start hour (8:00 AM)
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($startTimeStr))) {
                        $startTimeStr = trim($startTimeStr) . ' 08:00:00';
                    }
                    $startDt = new \DateTime($startTimeStr);
                    $endDt   = !empty($ticket['completed_at']) 
                        ? new \DateTime($ticket['completed_at']) 
                        : new \DateTime();

                    $ticket['computed_working_hours'] = \App\Libraries\WorkCalendar::calculateWorkingHours(
                        $startDt,
                        $endDt,
                        $ticket['overtime_hours']
                    );
                    $ticket['computed_working_duration'] = \App\Libraries\WorkCalendar::formatDuration(
                        $ticket['computed_working_hours'],
                        $ticket['overtime_hours']
                    );
                } catch (\Exception $e) {
                    $ticket['computed_working_hours']    = 0.0;
                    $ticket['computed_working_duration'] = 'N/A';
                }
            } else {
                $ticket['computed_working_hours']    = null;
                $ticket['computed_working_duration'] = null;
            }
        }
        unset($ticket);

        return $tickets;
    }

    /**
     * Query ticket_materials and return a map keyed by ticket_id.
     */
    private function buildMaterialsMap(\CodeIgniter\Database\ConnectionInterface $db, array $ticketIds): array
    {
        if (empty($ticketIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $rows = $db->query("
            SELECT tm.*, COALESCE(tm.ticket_id, ta.ticket_id) AS matched_ticket_id
            FROM ticket_materials tm
            LEFT JOIN ticket_assignments ta ON ta.id = tm.assignment_id
            WHERE tm.ticket_id IN ({$placeholders}) OR ta.ticket_id IN ({$placeholders})
            ORDER BY tm.id ASC
        ", array_merge($ticketIds, $ticketIds))->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $tId = $row['matched_ticket_id'];
            $row['quantity']    = (float) ($row['quantity'] ?? 1);
            $row['unit_price']  = (float) ($row['unit_price'] ?? 0);
            $row['total_price'] = (float) ($row['total_price'] ?? ($row['quantity'] * $row['unit_price']));
            $map[$tId][] = $row;
        }

        return $map;
    }

    /**
     * Query a detail table and return a map keyed by ticket_id.
     */
    private function buildDetailMap(\CodeIgniter\Database\ConnectionInterface $db, string $table, string $keyColumn, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $db->query("SELECT * FROM {$table} WHERE {$keyColumn} IN ({$placeholders})", $ids)->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $map[$row[$keyColumn]] = $row;
        }

        return $map;
    }

    /**
     * Query attachments and return a grouped map keyed by ticket_id.
     */
    private function buildAttachmentMap(\CodeIgniter\Database\ConnectionInterface $db, array $ticketIds): array
    {
        if (empty($ticketIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $rows = $db->query("SELECT * FROM ticket_attachments WHERE ticket_id IN ({$placeholders})", $ticketIds)->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['ticket_id']][] = $row;
        }

        return $map;
    }

    /**
     * Query ticket_assignments and return a map keyed by ticket_id with single & full list formats.
     */
    private function buildAssignmentMap(\CodeIgniter\Database\ConnectionInterface $db, array $ticketIds): array
    {
        if (empty($ticketIds)) {
            return ['single' => [], 'all' => []];
        }

        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));

        $rows = $db->query("
            SELECT ta.*, p.name AS personnel_name, p.contact_number AS personnel_contact
            FROM ticket_assignments ta
            LEFT JOIN personnel p ON p.id = ta.personnel_id
            WHERE ta.ticket_id IN ({$placeholders})
            ORDER BY ta.assigned_at ASC
        ", $ticketIds)->getResultArray();

        $map = [];
        $listMap = [];
        foreach ($rows as $row) {
            $tId = $row['ticket_id'];
            $listMap[$tId][] = $row;
            $pName = trim((string)($row['personnel_name'] ?? ''));

            if (isset($map[$tId])) {
                if ($pName !== '') {
                    $existingNames = array_map('trim', explode(',', $map[$tId]['personnel_name']));
                    if (!in_array($pName, $existingNames, true)) {
                        $map[$tId]['personnel_name'] .= ', ' . $pName;
                    }
                }
            } else {
                $map[$tId] = $row;
                $map[$tId]['personnel_name'] = $pName;
            }
        }

        return ['single' => $map, 'all' => $listMap];
    }

    // -------------------------------------------------------------------------
    // Attachments (Upload & Download)
    // -------------------------------------------------------------------------

    /**
     * Upload one or more attachments to a specific ticket.
     */
    public function uploadAttachment(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        // Security check: only ticket owner or admins can upload
        $userId = $this->currentUserId();
        $role = $this->currentUserRole();
        if ($ticket['user_id'] !== $userId && !in_array($role, ['admin', 'dispatcher', 'director'])) {
            return $this->forbiddenResponse('You do not have permission to upload files to this ticket.');
        }

        $rawAttachments = $this->request->getFileMultiple('attachments');
        if (empty($rawAttachments)) {
            $files = $this->request->getFiles();
            $rawAttachments = $files['attachments'] ?? null;
        }

        if (empty($rawAttachments)) {
            $single = $this->request->getFile('attachment') ?? $this->request->getFile('attachments');
            if ($single) {
                $rawAttachments = [$single];
            }
        }

        if (!is_array($rawAttachments)) {
            $rawAttachments = $rawAttachments ? [$rawAttachments] : [];
        }

        $attachments = array_values(array_filter($rawAttachments, fn($f) => ($f instanceof \CodeIgniter\HTTP\Files\UploadedFile) && $f->getError() !== UPLOAD_ERR_NO_FILE));

        if (empty($attachments)) {
            return $this->errorResponse('No files uploaded. Use "attachments[]" key in your form-data.');
        }

        $attachmentModel = new TicketAttachmentModel();
        $uploadedData = [];
        $errors = [];

        // Determine year from created_at or fallback to current year
        $year = date('Y', strtotime($ticket['created_at'] ?? date('Y-m-d')));
        $uploadPath = WRITEPATH . "uploads/tickets/{$year}/{$ticketId}/";

        // Ensure upload directory exists
        if (!is_dir($uploadPath)) {
            @mkdir($uploadPath, 0775, true);
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/jpg',
            'application/pdf', 
            'application/msword', 
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip',
            'application/x-zip-compressed',
            'application/octet-stream',
            'application/x-download',
            'binary/octet-stream'
        ];

        foreach ($attachments as $file) {
            $clientName = $file->getClientName();
            $ext = strtolower($file->getClientExtension());

            if ($file->isValid() && !$file->hasMoved()) {
                // Validate size (5MB max)
                $sizeMb = $file->getSizeByUnit('mb');
                if ($sizeMb > 5) {
                    $errors[] = $clientName . ' exceeds the 5MB size limit.';
                    continue;
                }

                // Validate mime type & extension
                $mime = $file->getMimeType();
                $clientMime = $file->getClientMimeType();

                if (!in_array($ext, $allowedExtensions)) {
                    $errors[] = $clientName . ' has an unsupported file extension (.' . $ext . ').';
                    continue;
                }

                if (!in_array($mime, $allowedMimes) && !in_array($clientMime, $allowedMimes)) {
                    $errors[] = $clientName . ' has an invalid file type (' . $mime . ').';
                    continue;
                }

                // Normalize stored MIME type based on verified extension
                if ($ext === 'docx') {
                    $storedMime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                } elseif ($ext === 'doc') {
                    $storedMime = 'application/msword';
                } elseif ($ext === 'pdf') {
                    $storedMime = 'application/pdf';
                } elseif ($ext === 'xlsx') {
                    $storedMime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                } elseif ($ext === 'xls') {
                    $storedMime = 'application/vnd.ms-excel';
                } else {
                    $storedMime = $mime;
                }

                $securityService = new \App\Libraries\FileSecurityService();
                $inspection = $securityService->inspectFile($file->getTempName(), $clientName, $mime);
                if (!$inspection['safe']) {
                    $errors[] = $clientName . ' rejected: ' . $inspection['reason'];
                    continue;
                }

                $newName = $file->getRandomName();
                $fileSize = $file->getSize();

                if ($file->move($uploadPath, $newName)) {
                    $savedFullPath = $uploadPath . $newName;

                    // Encrypt document at rest using AES-256-GCM
                    $isEncrypted   = 0;
                    $encryptionIv  = null;
                    $encryptionTag = null;
                    try {
                        $encResult = $securityService->encryptFile($savedFullPath, $savedFullPath);
                        $isEncrypted   = 1;
                        $encryptionIv  = $encResult['iv'];
                        $encryptionTag = $encResult['tag'];
                    } catch (\Throwable $e) {
                        log_message('error', 'Attachment encryption error: ' . $e->getMessage());
                    }

                    $record = [
                        'ticket_id'       => $ticketId,
                        'file_name'       => $clientName,
                        'file_path'       => "tickets/{$year}/{$ticketId}/{$newName}",
                        'file_type'       => $storedMime,
                        'file_size_bytes' => $fileSize,
                        'is_encrypted'    => $isEncrypted,
                        'encryption_iv'   => $encryptionIv,
                        'encryption_tag'  => $encryptionTag,
                        'uploaded_at'     => date('Y-m-d H:i:s'),
                    ];
                    
                    $attachmentModel->insert($record);
                    $record['id'] = $attachmentModel->getInsertID();
                    $uploadedData[] = $record;
                } else {
                    $errors[] = "Failed to save " . $clientName;
                }
            } else {
                if ($file->getError() !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = "Error uploading " . $clientName . " - " . $file->getErrorString();
                }
            }
        }

        if (empty($uploadedData) && !empty($errors)) {
            return $this->errorResponse('File upload failed.', $errors);
        }

        return $this->successResponse('Files uploaded successfully.', [
            'attachments' => $uploadedData,
            'errors'      => $errors
        ]);
    }

    /**
     * Download or view an attachment securely with AES-256 decryption.
     */
    public function downloadAttachment(int $attachmentId)
    {
        $attachmentModel = new TicketAttachmentModel();
        $attachment = $attachmentModel->find($attachmentId);

        if (!$attachment) {
            return $this->response->setStatusCode(404)->setBody('Attachment not found.');
        }

        $ticket = $this->ticketModel->find($attachment['ticket_id']);
        if (!$ticket) {
            return $this->response->setStatusCode(404)->setBody('Associated ticket not found.');
        }

        // Security check
        $userId = $this->currentUserId();
        $role = $this->currentUserRole();
        if ($ticket['user_id'] !== $userId && !in_array($role, ['admin', 'dispatcher', 'director', 'worker', 'superadmin'])) {
            return $this->response->setStatusCode(403)->setBody('Forbidden.');
        }

        $fullPath = WRITEPATH . 'uploads/' . $attachment['file_path'];

        if (!is_file($fullPath)) {
            return $this->response->setStatusCode(404)->setBody('File not found on server.');
        }

        // If file was encrypted at rest, decrypt on-the-fly
        if (!empty($attachment['is_encrypted']) && !empty($attachment['encryption_iv']) && !empty($attachment['encryption_tag'])) {
            $securityService = new \App\Libraries\FileSecurityService();
            $decrypted = $securityService->decryptFile($fullPath, $attachment['encryption_iv'], $attachment['encryption_tag']);
            if ($decrypted === null) {
                return $this->response->setStatusCode(500)->setBody('Integrity check failed: unable to decrypt attachment.');
            }

            return $this->response
                ->setHeader('Content-Type', $attachment['file_type'] ?: 'application/octet-stream')
                ->setHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($attachment['file_name']) . '"')
                ->setBody($decrypted);
        }

        return $this->response->download($fullPath, null)->setFileName($attachment['file_name']);
    }

    /**
     * Upload Accomplishment Report for a ticket.
     * Mandatory proof of completion before a ticket can be closed.
     */
    public function uploadAccomplishment(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        $file = $this->request->getFile('accomplishment_report');
        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return $this->errorResponse('Accomplishment report file is required ("accomplishment_report").');
        }

        $securityService = new \App\Libraries\FileSecurityService();
        $inspection = $securityService->inspectFile($file->getTempName(), $file->getClientName(), $file->getClientMimeType());
        if (!$inspection['safe']) {
            return $this->errorResponse('Security violation: ' . $inspection['reason'], [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $year = date('Y');
        $uploadPath = WRITEPATH . "uploads/accomplishments/{$year}/{$ticketId}/";
        if (!is_dir($uploadPath)) {
            @mkdir($uploadPath, 0775, true);
        }

        $newName = $file->getRandomName();
        if (!$file->move($uploadPath, $newName)) {
            return $this->errorResponse('Failed to store accomplishment report.');
        }

        $savedFullPath = $uploadPath . $newName;
        try {
            $securityService->encryptSelfContained($savedFullPath, $savedFullPath);
        } catch (\Throwable $e) {
            log_message('error', 'Failed to encrypt accomplishment report: ' . $e->getMessage());
        }

        $reportRelPath = "accomplishments/{$year}/{$ticketId}/{$newName}";
        $notes = sanitize_string($this->request->getPost('notes') ?? '');

        $this->ticketModel->update($ticketId, [
            'accomplishment_report_path' => $reportRelPath,
            'accomplishment_notes'       => $notes,
            'verification_status'        => 'pending_verification',
            'status'                     => 'resolved',
            'status_label'               => 'Resolved (Pending Verification)',
            'updated_at'                 => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction(
            $ticketId,
            $this->currentUserId(),
            'Accomplishment Report Uploaded',
            "Accomplishment report uploaded: {$file->getClientName()}. Awaiting verification and ticket closure."
        );

        $notificationModel = new \App\Models\NotificationModel();
        $notificationModel->createNotification(
            $ticket['user_id'],
            'info',
            "Ticket #{$ticketId} Accomplishment Submitted",
            "Field services have been completed with accomplishment report. Verification in progress."
        );

        return $this->successResponse('Accomplishment report submitted successfully. Ticket is pending verification.', [
            'verification_status' => 'pending_verification',
            'report_path'         => $reportRelPath,
        ]);
    }

    /**
     * Download or preview accomplishment report securely with AES-256 decryption.
     */
    public function downloadAccomplishment(string $ticketId)
    {
        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket || empty($ticket['accomplishment_report_path'])) {
            return $this->response->setStatusCode(404)->setBody('Accomplishment report not found.');
        }

        $userId = $this->currentUserId();
        $role   = $this->currentUserRole();
        if ((string)$ticket['user_id'] !== (string)$userId && !in_array($role, ['admin', 'dispatcher', 'director', 'worker', 'superadmin'], true)) {
            return $this->response->setStatusCode(403)->setBody('Forbidden.');
        }

        $fullPath = WRITEPATH . 'uploads/' . $ticket['accomplishment_report_path'];
        if (!is_file($fullPath)) {
            return $this->response->setStatusCode(404)->setBody('File not found on server.');
        }

        $securityService = new \App\Libraries\FileSecurityService();
        $decrypted = $securityService->decryptSelfContained($fullPath);
        if ($decrypted === null) {
            return $this->response->setStatusCode(500)->setBody('Failed to decrypt accomplishment report.');
        }

        $ext  = strtolower(pathinfo($ticket['accomplishment_report_path'], PATHINFO_EXTENSION));
        $mime = match($ext) {
            'pdf'          => 'application/pdf',
            'png'          => 'image/png',
            'jpg', 'jpeg'  => 'image/jpeg',
            'webp'         => 'image/webp',
            default        => 'application/octet-stream',
        };

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'inline; filename="Accomplishment_Report_' . $ticketId . '.' . $ext . '"')
            ->setBody($decrypted);
    }

    /**
     * Officially verify services and close ticket.
     */
    public function verifyAndClose(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        $userId = $this->currentUserId();
        $role   = $this->currentUserRole();
        if ((string)$ticket['user_id'] !== (string)$userId && !in_array($role, ['admin', 'dispatcher', 'director', 'superadmin'], true)) {
            return $this->forbiddenResponse('You do not have permission to verify and close this ticket.');
        }

        $db = Database::connect();
        $db->transStart();

        $this->ticketModel->update($ticketId, [
            'status'              => 'closed',
            'status_label'        => 'Closed / Verified',
            'verification_status' => 'verified_closed',
            'verified_by_user_id' => $userId,
            'verified_at'         => date('Y-m-d H:i:s'),
            'completed_at'        => $ticket['completed_at'] ?? date('Y-m-d H:i:s'),
            'current_step'        => 6,
            'is_archived'         => 1,
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        // Complete any active assignments
        $db->table('ticket_assignments')
           ->where('ticket_id', $ticketId)
           ->where('completed_at IS NULL')
           ->update(['completed_at' => date('Y-m-d H:i:s'), 'status' => 'completed']);

        // Set worker back to available if not on leave
        $assignments = $db->table('ticket_assignments')->where('ticket_id', $ticketId)->get()->getResultArray();
        $personnelModel = new \App\Models\PersonnelModel();
        foreach ($assignments as $a) {
            if (!empty($a['personnel_id'])) {
                $worker = $personnelModel->find($a['personnel_id']);
                if ($worker && $worker['status'] !== 'on_leave') {
                    $personnelModel->update($a['personnel_id'], [
                        'status'     => 'available',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->errorResponse('Failed to close ticket.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->logModel->logAction(
            $ticketId,
            $userId,
            'Ticket Verified & Closed',
            'Ticket verified and officially marked as Closed.'
        );

        $notificationModel = new \App\Models\NotificationModel();
        $notificationModel->createNotification(
            $ticket['user_id'],
            'success',
            "Ticket #{$ticketId} Closed",
            "Your service request has been verified and officially closed. Thank you!"
        );

        return $this->successResponse('Ticket verified and closed successfully.');
    }

    /**
     * Update EODB turnaround classification on a ticket.
     * 3 days: Simple, 7 days: Moderate, 21 days: Complex
     */
    public function updateEodb(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        $body = $this->request->getJSON(true) ?? [];
        $tier = sanitize_string($body['eodb_tier'] ?? 'simple_3d');

        $days = match($tier) {
            'moderate_7d' => 7,
            'complex_21d' => 21,
            default       => 3,
        };

        $implDate = !empty($body['implementation_date'])
            ? new \DateTime($body['implementation_date'])
            : new \DateTime();

        $targetDate = \App\Libraries\WorkCalendar::addWorkingDays($implDate, $days)->format('Y-m-d H:i:s');

        $this->ticketModel->update($ticketId, [
            'eodb_tier'              => $tier,
            'eodb_days'              => $days,
            'target_completion_date' => $targetDate,
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction(
            $ticketId,
            $this->currentUserId(),
            'EODB Classification Set',
            "EODB tier set to {$tier} ({$days} working days). Target completion: {$targetDate}."
        );

        return $this->successResponse('EODB classification updated successfully.', [
            'eodb_tier'              => $tier,
            'eodb_days'              => $days,
            'target_completion_date' => $targetDate,
        ]);
    }
    private function formatServicesList(array $services): string
    {
        if (empty($services)) {
            return '';
        }

        $formatted = [];
        $count = count($services);
        
        foreach (array_values($services) as $i => $service) {
            $service = trim($service);
            if ($i < $count - 1) {
                $service = preg_replace('/(?:\s+Works?|\s+works?)$/i', '', $service);
            }
            $formatted[] = $service;
        }
        
        return implode(', ', $formatted);
    }
}
