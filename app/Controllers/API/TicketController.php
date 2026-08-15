<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\TicketModel;
use App\Models\FgmuTicketDetailModel;
use App\Models\LeauTicketDetailModel;
use App\Models\SsuVehiclePassDetailModel;
use App\Models\SsuIncidentDetailModel;
use App\Models\TasuBookingDetailModel;
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
        'TASU' => 4,
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

    // -------------------------------------------------------------------------
    // Admin & Dispatcher Queues
    // -------------------------------------------------------------------------

    /**
     * Get the pending ticket queue for a given unit.
     */
    public function pendingQueue(string $unitCode): ResponseInterface
    {
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
        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;

        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $tickets = $this->ticketModel->getActiveTickets($unitId);
        $tickets = $this->enrichTickets($tickets);

        $today = date('Y-m-d');
        $isTasu = $unitId === 4;

        $personnelModel = clone $this->ticketModel; // We don't have it directly injected, let's load it
        $personnelModel = new \App\Models\PersonnelModel();
        $assignmentModel = new \App\Models\TicketAssignmentModel();
        $logModel = new \App\Models\TicketLogModel();

        foreach ($tickets as &$ticket) {
            $currentStep = (int) $ticket['current_step'];
            $needsStart = $isTasu ? ($currentStep === 3) : ($currentStep === 4);
            
            if ($needsStart && !empty($ticket['assignment']['implementation_date'])) {
                if ($ticket['assignment']['implementation_date'] <= $today) {
                    $workerStatus = $isTasu ? 'on_trip' : 'working';
                    $statusLabel  = $isTasu ? 'On Route / In Progress' : 'Job Started';
                    $newStep      = $isTasu ? 4 : 5;

                    $this->ticketModel->update($ticket['id'], [
                        'status_label' => $statusLabel,
                        'current_step' => $newStep,
                        'updated_at'   => date('Y-m-d H:i:s'),
                    ]);

                    $assignments = $assignmentModel->getByTicket($ticket['id']);
                    foreach ($assignments as $assignment) {
                        if (!empty($assignment['personnel_id'])) {
                            $personnelModel->update($assignment['personnel_id'], [
                                'status'     => $workerStatus,
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]);
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
        if (strtoupper($unitCode) === 'ALL') {
            $stats = $this->ticketModel->getAdvancedStatsByUnit(null);
            return $this->successResponse('Global statistics retrieved.', ['stats' => $stats]);
        }

        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;

        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $stats = $this->ticketModel->getAdvancedStatsByUnit($unitId);
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

        if ($ticket['status'] !== 'pending') {
            return $this->errorResponse("Only pending tickets can be approved. Current status: {$ticket['status']}.");
        }

        $unitId = (int) $ticket['unit_id'];
        $isTasu = $unitId === 4;

        $currentStep = 3;
        if ($isTasu) {
            $currentStep = 2;
        } elseif ($unitId === 3 && $ticket['service_type'] === 'Vehicle Pass Application') {
            $currentStep = 4;
        }

        $newStatus   = 'approved';
        $statusLabel = 'Queued for Dispatch';
        $logMessage  = 'Ticket approved — queued for dispatch.';

        if ($isTasu) {
            $statusLabel = 'Trip Scheduled';
        } elseif ($unitId === 3 && $ticket['service_type'] === 'Vehicle Pass Application') {
            $statusLabel = 'Ready for Pickup';
            $logMessage  = 'Ticket approved — vehicle pass sticker is ready for pickup.';
        }
        // SSU Incident Reports are handled by /investigate, /notation, and /resolve endpoints.

        $updateData = [
            'status'       => $newStatus,
            'status_label' => $statusLabel,
            // FGMU/LEAU: step 3 | TASU: step 2 | SSU Vehicle Pass: step 4
            'current_step' => $currentStep,
            'reviewed_at'  => date('Y-m-d H:i:s'),
            'reviewed_by'  => $this->currentUserId(),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        $this->ticketModel->update($ticketId, $updateData);

        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Status Changed', $logMessage);

        $this->notificationModel->createNotification(
            $ticket['user_id'],
            'success',
            "Ticket #{$ticketId} Approved",
            "Your request for {$ticket['service_type']} has been approved."
        );

        return $this->successResponse('Ticket approved successfully.', ['ticket_id' => $ticketId, 'status' => 'approved']);
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
            'current_step'  => 1,
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
     * Mark a ticket as completed/resolved (processing -> resolved, step 3 -> 4).
     * Triggers archival and awaits user feedback.
     */
    public function complete(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if (!in_array($ticket['status'], ['processing', 'approved'])) {
            return $this->errorResponse("Only processing or approved tickets can be marked as completed.");
        }

        $unitId   = (int) $ticket['unit_id'];
        $unitCode = array_search($unitId, self::UNIT_MAP);

        $statusLabel = match($unitCode) {
            'TASU'  => 'Trip Completed',
            'SSU'   => 'Sticker Issued',
            default => 'Completed',
        };

        $newStatus = ($unitCode === 'SSU') ? 'closed' : 'resolved';
        $logMessage = ($unitCode === 'SSU') ? 'Sticker pass issued. Ticket marked as closed.' : 'Ticket marked as completed. Awaiting user feedback.';

        $updateData = [
            'status'       => $newStatus,
            'status_label' => $statusLabel,
            'current_step' => ($unitCode === 'TASU') ? 4 : (($unitCode === 'SSU') ? 5 : 6),
            'completed_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        
        if ($unitCode === 'SSU') {
            $updateData['is_archived'] = 1;
        }

        $this->ticketModel->update($ticketId, $updateData);

        // Mark the active assignment as completed and set worker to available
        $db = Database::connect();
        
        // Find personnel assigned to this ticket before we close it
        $assignments = $db->query(
            "SELECT personnel_id FROM ticket_assignments WHERE ticket_id = ? AND completed_at IS NULL",
            [$ticketId]
        )->getResultArray();

        $personnelModel = new \App\Models\PersonnelModel();
        foreach ($assignments as $a) {
            $personnelModel->update($a['personnel_id'], [
                'status' => 'available',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        $db->query(
            "UPDATE ticket_assignments SET completed_at = NOW() WHERE ticket_id = ? AND completed_at IS NULL",
            [$ticketId]
        );

        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Status Changed', $logMessage);

        $this->notificationModel->createNotification(
            $ticket['user_id'], 
            'success', 
            "Ticket #{$ticketId} Completed", 
            "Your request for {$ticket['service_type']} has been marked as completed."
        );

        return $this->successResponse('Ticket completed and archived.', ['ticket_id' => $ticketId, 'status' => $newStatus]);
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
     *   ssu:  { vehiclePass: {...}, incidentReport: {...} },
     *   tasu: { ... }
     * }
     *
     * All inserts are wrapped in a single DB transaction.
     */
    public function submitIntake(): ResponseInterface
    {
        $body   = $this->request->getJSON(true) ?? [];
        $userId = $this->currentUserId();

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

                foreach ($body['fgmu']['services'] as $idx => $srv) {
                    $ticketId = $this->ticketModel->generateTicketId('FGMU', self::UNIT_MAP['FGMU'], $idx);
                    $service  = sanitize_string($srv['service'] ?? 'Facilities Maintenance');

                    $this->ticketModel->insert([
                        'id'          => $ticketId,
                        'user_id'     => $userId,
                        'unit_id'     => self::UNIT_MAP['FGMU'],
                        'service_type'=> $service,
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

                    $this->logModel->logAction($ticketId, $userId, 'Ticket Submitted', "FGMU service request: {$service}");
                    $createdTickets[] = $ticketId;
                }
            }

            // --- 2. LEAU Intake ---
            if (!empty($body['leau']['services'])) {
                $leauDetails = sanitize_array($body['leau']['details'] ?? []);
                $leauModel   = new LeauTicketDetailModel();

                foreach ($body['leau']['services'] as $idx => $srv) {
                    $ticketId = $this->ticketModel->generateTicketId('LEAU', self::UNIT_MAP['LEAU'], $idx);
                    $service  = sanitize_string($srv['service'] ?? 'Janitorial & Landscaping');

                    $this->ticketModel->insert([
                        'id'          => $ticketId,
                        'user_id'     => $userId,
                        'unit_id'     => self::UNIT_MAP['LEAU'],
                        'service_type'=> $service,
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

                    $this->logModel->logAction($ticketId, $userId, 'Ticket Submitted', "LEAU service request: {$service}");
                    $createdTickets[] = $ticketId;
                }
            }

            // --- 3. SSU Vehicle Pass ---
            if (!empty($body['ssu']['vehiclePass'])) {
                $vp        = sanitize_array($body['ssu']['vehiclePass']);
                $vd        = $vp['vehicleDetails'] ?? [];
                $ticketId  = $this->ticketModel->generateTicketId('SSU', self::UNIT_MAP['SSU']);
                $makeSeries= sanitize_string($vd['makeSeries'] ?? 'Vehicle');
                $plateNo   = sanitize_string($vd['plateNo'] ?? 'N/A');

                $this->ticketModel->insert([
                    'id'          => $ticketId,
                    'user_id'     => $userId,
                    'unit_id'     => self::UNIT_MAP['SSU'],
                    'service_type'=> 'Vehicle Pass Application',
                    'description' => "Vehicle pass application for {$makeSeries} ({$plateNo})",
                    'status'      => 'pending',
                    'status_label'=> 'Pending Verification',
                    'current_step'=> 1,
                    'submitted_at'=> date('Y-m-d H:i:s'),
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);

                (new SsuVehiclePassDetailModel())->insert([
                    'ticket_id'        => $ticketId,
                    'account_type'     => sanitize_string($vp['accountType'] ?? ''),
                    'applicant_name'   => sanitize_string($vp['name'] ?? ''),
                    'college_office'   => sanitize_string($vp['collegeOffice'] ?? ''),
                    'contact_no'       => sanitize_string($vp['contactNo'] ?? ''),
                    'driver_name'      => sanitize_string($vp['driverName'] ?? ''),
                    'driver_contact'   => sanitize_string($vp['driverContact'] ?? ''),
                    'house_street'     => sanitize_string($vp['houseStreet'] ?? ''),
                    'barangay'         => sanitize_string($vp['barangay'] ?? ''),
                    'city_municipality'=> sanitize_string($vp['cityMunicipality'] ?? ''),
                    'province'         => sanitize_string($vp['province'] ?? ''),
                    'registered_owner' => sanitize_string($vd['registeredOwner'] ?? ''),
                    'plate_no'         => $plateNo,
                    'make_series'      => $makeSeries,
                    'type_color'       => sanitize_string($vd['typeColor'] ?? ''),
                    'id_type_no'       => sanitize_string($vp['idTypeNo'] ?? ''),
                    'valid_until'      => !empty($vp['validUntil']) ? $vp['validUntil'] : null,
                    'privacy_agreed'   => (bool) ($vp['privacyAgreed'] ?? false),
                    'disclosure_agreed'=> (bool) ($vp['disclosureAgreed'] ?? false),
                    'applicant_signature'=> $vp['signature'] ?? null,
                ]);

                $this->logModel->logAction($ticketId, $userId, 'Ticket Submitted', "SSU Vehicle Pass for {$plateNo}");
                $createdTickets[] = $ticketId;
            }

            // --- 4. SSU Incident Report ---
            if (!empty($body['ssu']['incidentReport'])) {
                $inc      = sanitize_array($body['ssu']['incidentReport']);
                $ticketId = $this->ticketModel->generateTicketId('SSU', self::UNIT_MAP['SSU']);
                $typeStr  = is_array($inc['incidents'] ?? null) ? implode(', ', $inc['incidents']) : ($inc['otherIncident'] ?? 'Incident');

                $this->ticketModel->insert([
                    'id'                    => $ticketId,
                    'user_id'               => $userId,
                    'unit_id'               => self::UNIT_MAP['SSU'],
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

            // --- 5. TASU Vehicle Booking ---
            if (!empty($body['tasu'])) {
                $ts       = sanitize_array($body['tasu']);
                $ticketId = $this->ticketModel->generateTicketId('TASU', self::UNIT_MAP['TASU']);

                $this->ticketModel->insert([
                    'id'          => $ticketId,
                    'user_id'     => $userId,
                    'unit_id'     => self::UNIT_MAP['TASU'],
                    'service_type'=> 'Vehicle Request',
                    'description' => sanitize_string($ts['purposeOfTravel'] ?? 'University vehicle booking request.'),
                    'status'      => 'pending',
                    'status_label'=> 'Pending Approval',
                    'current_step'=> 1,
                    'location'    => sanitize_string($ts['destination'] ?? ''),
                    'submitted_at'=> date('Y-m-d H:i:s'),
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);

                (new TasuBookingDetailModel())->insert([
                    'ticket_id'                => $ticketId,
                    'request_time'             => sanitize_string($ts['time'] ?? ''),
                    'requesting_personnel'      => sanitize_string($ts['requestingPersonnel'] ?? ''),
                    'office_college_department' => sanitize_string($ts['officeCollegeDepartment'] ?? ''),
                    'agency_address'            => sanitize_string($ts['agencyAddress'] ?? ''),
                    'num_passengers'            => max(1, (int) ($ts['numberOfPassengers'] ?? 1)),
                    'date_of_travel'            => $ts['dateOfTravel'] ?? null,
                    'destination'               => sanitize_string($ts['destination'] ?? ''),
                    'purpose_of_travel'         => sanitize_string($ts['purposeOfTravel'] ?? ''),
                ]);

                $this->logModel->logAction($ticketId, $userId, 'Ticket Submitted', "TASU Vehicle Request to: " . ($ts['destination'] ?? 'N/A'));
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
        // Only allow admins or dispatchers to create projects
        $user = (new \App\Models\UserModel())->find($userId);
        if (!$user || !in_array($user['role'], ['admin', 'dispatcher'])) {
            return $this->errorResponse('Unauthorized to create projects.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        $body = $this->request->getJSON(true) ?? [];
        $unitCode = strtoupper(sanitize_string($body['unit'] ?? ''));
        $unitId = self::UNIT_MAP[$unitCode] ?? null;

        if (!$unitId || !in_array($unitCode, ['FGMU', 'LEAU'])) {
            return $this->errorResponse('Projects can only be created for FGMU or LEAU.');
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
                                     ->where('is_archived', 0)
                                     ->orderBy('submitted_at', 'DESC')
                                     ->findAll();
        
        $tickets = $this->enrichTickets($tickets);
        return $this->successResponse('Active projects retrieved.', ['projects' => $tickets]);
    }

    public function getProjectArchives(): ResponseInterface
    {
        $tickets = $this->ticketModel->where('is_project', 1)
                                     ->where('is_archived', 1)
                                     ->orderBy('completed_at', 'DESC')
                                     ->findAll();
        
        $tickets = $this->enrichTickets($tickets);
        return $this->successResponse('Archived projects retrieved.', ['projects' => $tickets]);
    }

    public function updateProject(string $ticketId): ResponseInterface
    {
        $userId = $this->currentUserId();
        $user = (new \App\Models\UserModel())->find($userId);
        if (!$user || !in_array($user['role'], ['admin', 'dispatcher'])) {
            return $this->errorResponse('Unauthorized to update projects.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket || !(bool)$ticket['is_project']) {
            return $this->notFoundResponse('Project');
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
        $ssuVpDetails = $this->buildDetailMap($db, 'ssu_vehicle_pass_details', 'ticket_id', $ticketIds);
        $ssuIrDetails = $this->buildDetailMap($db, 'ssu_incident_details',     'ticket_id', $ticketIds);
        $tasuDetails  = $this->buildDetailMap($db, 'tasu_booking_details',     'ticket_id', $ticketIds);
        $assignments  = $this->buildAssignmentMap($db, $ticketIds);
        $feedbacks    = $this->buildDetailMap($db, 'ticket_feedbacks',         'ticket_id', $ticketIds);

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
                3       => $ssuVpDetails[$id] ?? $ssuIrDetails[$id] ?? null,
                4       => $tasuDetails[$id]  ?? null,
                default => null,
            };

            $ticket['assignment']  = $assignments[$id] ?? null;
            $ticket['attachments'] = $attachmentsMap[$id] ?? [];
            $ticket['feedback']    = $feedbacks[$id] ?? null;
        }
        unset($ticket);

        return $tickets;
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
     * Query ticket_assignments and return a map keyed by ticket_id.
     */
    private function buildAssignmentMap(\CodeIgniter\Database\ConnectionInterface $db, array $ticketIds): array
    {
        if (empty($ticketIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));

        $rows = $db->query("
            SELECT ta.*, p.name AS personnel_name, v.model_name AS vehicle_name, v.plate_no AS vehicle_plate
            FROM ticket_assignments ta
            LEFT JOIN personnel p ON p.id = ta.personnel_id
            LEFT JOIN vehicles v  ON v.id = ta.vehicle_id
            WHERE ta.ticket_id IN ({$placeholders})
              AND ta.completed_at IS NULL
        ", $ticketIds)->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            if (isset($map[$row['ticket_id']])) {
                $map[$row['ticket_id']]['personnel_name'] .= ', ' . $row['personnel_name'];
            } else {
                $map[$row['ticket_id']] = $row;
            }
        }

        return $map;
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

        $files = $this->request->getFiles();

        $attachments = $this->request->getFileMultiple('attachments') ?? ($files['attachments'] ?? null);

        if (empty($attachments)) {
            return $this->errorResponse('No files uploaded. Use "attachments[]" key in your form-data.');
        }

        $attachments = $files['attachments'];
        if (!is_array($attachments)) {
            $attachments = [$attachments];
        }

        $attachmentModel = new TicketAttachmentModel();
        $uploadedData = [];
        $errors = [];

        // Determine year from created_at or fallback to current year
        $year = date('Y', strtotime($ticket['created_at'] ?? date('Y-m-d')));
        $uploadPath = WRITEPATH . "uploads/tickets/{$year}/{$ticketId}/";

        foreach ($attachments as $file) {
            if ($file->isValid() && !$file->hasMoved()) {
                // Validate size (5MB max)
                $sizeMb = $file->getSizeByUnit('mb');
                if ($sizeMb > 5) {
                    $errors[] = $file->getName() . ' exceeds the 5MB size limit.';
                    continue;
                }

                // Validate mime type
                $mime = $file->getMimeType();
                $allowedMimes = [
                    'image/jpeg', 'image/png', 'image/jpg',
                    'application/pdf', 
                    'application/msword', 
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                ];

                if (!in_array($mime, $allowedMimes)) {
                    $errors[] = $file->getName() . ' has an invalid file type.';
                    continue;
                }

                $newName = $file->getRandomName();
                $originalName = $file->getClientName();
                $fileSize = $file->getSize();

                if ($file->move($uploadPath, $newName)) {
                    $record = [
                        'ticket_id'       => $ticketId,
                        'file_name'       => $originalName,
                        'file_path'       => "tickets/{$year}/{$ticketId}/{$newName}",
                        'file_type'       => $mime,
                        'file_size_bytes' => $fileSize,
                        'uploaded_at'     => date('Y-m-d H:i:s'),
                    ];
                    
                    $attachmentModel->insert($record);
                    $record['id'] = $attachmentModel->getInsertID();
                    $uploadedData[] = $record;
                } else {
                    $errors[] = "Failed to move " . $file->getName();
                }
            } else {
                if ($file->getError() !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = "Error uploading " . $file->getName() . " - " . $file->getErrorString();
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
     * Download or view an attachment securely.
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
        if ($ticket['user_id'] !== $userId && !in_array($role, ['admin', 'dispatcher', 'director', 'worker', 'driver'])) {
            return $this->response->setStatusCode(403)->setBody('Forbidden.');
        }

        $fullPath = WRITEPATH . 'uploads/' . $attachment['file_path'];

        if (!is_file($fullPath)) {
            return $this->response->setStatusCode(404)->setBody('File not found on server.');
        }

        return $this->response->download($fullPath, null)->setFileName($attachment['file_name']);
    }
}
