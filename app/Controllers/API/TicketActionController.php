<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\TicketModel;
use App\Models\TicketLogModel;
use App\Models\NotificationModel;
use App\Models\UserModel;
use App\Libraries\ResendEmailService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * TicketActionController - Handles administrative ticket lifecycle actions and state transitions.
 *
 * Scopes:
 * - PATCH /api/v1/tickets/{id}/approve
 * - PATCH /api/v1/tickets/{id}/delay-approval
 * - PATCH /api/v1/tickets/{id}/resume-approval
 * - PATCH /api/v1/tickets/{id}/decline
 * - PATCH /api/v1/tickets/{id}/complete
 * - POST  /api/v1/tickets/{id}/materials
 * - PATCH /api/v1/tickets/{id}/extend
 * - PATCH /api/v1/tickets/{id}/investigate
 * - PATCH /api/v1/tickets/{id}/uninvestigate
 * - PATCH /api/v1/tickets/{id}/notation
 * - PATCH /api/v1/tickets/{id}/resolve
 * - POST/PATCH /api/v1/tickets/{id}/verify-close
 * - PATCH /api/v1/tickets/{id}/eodb
 */
class TicketActionController extends BaseController
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

    /**
     * Helper to send an email status notification to the ticket requestor.
     */
    private function notifyRequestorByEmail(string $userId, string $ticketId, string $statusLabel, string $message, array $extra = []): void
    {
        try {
            $userModel = new UserModel();
            $user = $userModel->find($userId);
            if ($user && !empty($user['email'])) {
                // Respect per-requestor opt-in (wired to registered/updated email)
                if (array_key_exists('email_notifications_enabled', $user) && (int) $user['email_notifications_enabled'] === 0) {
                    log_message('info', "[TicketActionController::notifyRequestorByEmail] Skipped — requestor opted out ({$userId})");
                    return;
                }
                $reqName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                if (empty($reqName)) {
                    $reqName = 'Campus Member';
                }
                $emailService = new ResendEmailService();
                $emailService->sendTicketStatusUpdate($user['email'], $reqName, $ticketId, $statusLabel, $message, $extra);
            }
        } catch (\Throwable $e) {
            log_message('error', '[TicketActionController::notifyRequestorByEmail] ' . $e->getMessage());
        }
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

        $serviceLower = strtolower((string) ($ticket['service_type'] ?? ''));
        $isBorrowing  = str_contains($serviceLower, 'borrowing of plants') || str_contains($serviceLower, 'borrowing of tools') || str_contains($serviceLower, 'borrowing request');

        if ($isBorrowing) {
            $statusLabel = 'Approved - Awaiting Inventory Assignment';
            $logMessage  = 'Borrowing request approved by Director — awaiting inventory assignment.';
        } else {
            $statusLabel = 'Queued for Dispatch';
            $logMessage  = 'Ticket approved — queued for dispatch.';
        }
        // SSU Incident Reports are handled by /investigate, /notation, and /resolve endpoints.

        $body = $this->request->getJSON(true) ?? [];
        $isEmergency = isset($body['is_emergency']) ? (!empty($body['is_emergency']) ? 1 : 0) : null;

        $updateData = [
            'status'                => $newStatus,
            'status_label'          => $statusLabel,
            'is_approval_delayed'   => 0,
            'approval_delay_reason' => null,
            'current_step'          => $currentStep,
            'reviewed_at'           => date('Y-m-d H:i:s'),
            'reviewed_by'           => $this->currentUserId(),
            'updated_at'            => date('Y-m-d H:i:s'),
        ];

        if ($isEmergency !== null) {
            $updateData['is_emergency'] = $isEmergency;
            if ($isEmergency === 1) {
                $logMessage = $isBorrowing
                    ? 'Borrowing request approved as EMERGENCY PRIORITY by Director — awaiting immediate inventory assignment.'
                    : 'Ticket approved as EMERGENCY PRIORITY by Director — queued for immediate dispatch.';
            }
        }

        $this->ticketModel->update($ticketId, $updateData);

        // Borrowing sync: LEAU borrowing tickets move from pending_director -> approved_director
        // so they appear in the LEAU Admin approved queue ready for inventory assignment.
        try {
            if ($isBorrowing) {
                $db = Database::connect();
                $db->table('borrowing_requests')
                    ->where('ticket_id', $ticketId)
                    ->where('status', 'pending_director')
                    ->update(['status' => 'approved_director', 'updated_at' => date('Y-m-d H:i:s')]);
                $logMessage .= ' Borrowing director approval synced.';
            }
        } catch (\Throwable $borrowSyncErr) {
            log_message('error', '[TicketActionController::approve] Borrowing sync failed: ' . $borrowSyncErr->getMessage());
        }

        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Status Changed', $logMessage);

        $notifTitle = ($isEmergency === 1) ? "Ticket #{$ticketId} Approved (Emergency Priority)" : "Ticket #{$ticketId} Approved";
        $notifBody  = ($isEmergency === 1)
            ? "Your request for {$ticket['service_type']} has been approved as an EMERGENCY request by the Director."
            : ($isBorrowing
                ? "Your borrowing request for {$ticket['service_type']} has been approved by the Director. LEAU Admin will assign inventory."
                : "Your request for {$ticket['service_type']} has been approved.");

        $this->notificationModel->createNotification(
            $ticket['user_id'],
            'success',
            $notifTitle,
            $notifBody
        );

        $this->notifyRequestorByEmail(
            $ticket['user_id'],
            $ticketId,
            ($isEmergency === 1 ? 'Approved (Emergency Priority)' : 'Approved'),
            $notifBody
        );

        // Notify destination Unit Admins that a ticket has been approved by the Director and is ready for dispatch
        $dbConn = \Config\Database::connect();
        $unitAdmins = $dbConn->query(
            "SELECT id FROM users WHERE role = 'admin' AND unit_id = ? AND status = 'Active'",
            [$unitId]
        )->getResultArray();
        $adminNotifTitle = ($isEmergency === 1)
            ? "New Emergency Ticket Approved (#{$ticketId})"
            : "New Ticket Approved (#{$ticketId})";
        $adminNotifBody = ($isEmergency === 1)
            ? "Ticket #{$ticketId} ({$ticket['service_type']}) has been approved as EMERGENCY PRIORITY by the Director and requires immediate dispatch."
            : ($isBorrowing
                ? "Borrowing ticket #{$ticketId} ({$ticket['service_type']}) has been approved by the Director and is awaiting inventory assignment."
                : "Ticket #{$ticketId} ({$ticket['service_type']}) has been approved by the Director and is ready for dispatch.");

        foreach ($unitAdmins as $uAdmin) {
            $this->notificationModel->createNotification(
                $uAdmin['id'],
                'info',
                $adminNotifTitle,
                $adminNotifBody
            );
        }

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

        $this->notifyRequestorByEmail(
            $ticket['user_id'],
            $ticketId,
            'Under Investigation',
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

        // If the ticket is not yet under investigation, preserve 'pending' status so it
        // remains visible in the Submitted queue. Only use 'processing' when already flagged.
        $this->ticketModel->update($ticketId, [
            'ssu_notation' => $notation,
            'status'       => $isUnderInvestigation ? 'processing' : 'pending',
            'status_label' => $isUnderInvestigation ? 'Under Investigation' : 'Pending Review (Notated)',
            'current_step' => $isUnderInvestigation ? 3 : 2,
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

        $this->notifyRequestorByEmail(
            $ticket['user_id'],
            $ticketId,
            'SSU Notation Added',
            'SSU staff has added a recommendation/notation to your incident report: "' . $notation . '"'
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

        $this->notifyRequestorByEmail(
            $ticket['user_id'],
            $ticketId,
            'Incident Resolved',
            'Your incident report has been resolved by SSU staff and has been archived.'
        );

        return $this->successResponse(
            'Incident report resolved and archived.',
            ['ticket_id' => $ticketId, 'status' => 'resolved']
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
            'status'              => 'declined',
            'status_label'        => 'Declined',
            'is_approval_delayed' => 0,
            'decline_reason'      => $reason,
            'is_archived'         => 1,
            'current_step'        => 2,
            'reviewed_at'         => date('Y-m-d H:i:s'),
            'reviewed_by'         => $this->currentUserId(),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        // Borrowing sync: declined borrowing tickets are cancelled in the borrowing workflow.
        try {
            $serviceLower = strtolower((string) ($ticket['service_type'] ?? ''));
            if (str_contains($serviceLower, 'borrowing of plants') || str_contains($serviceLower, 'borrowing of tools')) {
                $db = Database::connect();
                $db->table('borrowing_requests')
                    ->where('ticket_id', $ticketId)
                    ->where('status', 'pending_director')
                    ->update(['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')]);
            }
        } catch (\Throwable $borrowSyncErr) {
            log_message('error', '[TicketActionController::decline] Borrowing sync failed: ' . $borrowSyncErr->getMessage());
        }

        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Declined', "Ticket declined. Reason: {$reason}");

        $this->notificationModel->createNotification(
            $ticket['user_id'], 
            'warning', 
            "Ticket #{$ticketId} Declined", 
            "Your request was declined. Reason: {$reason}"
        );

        $this->notifyRequestorByEmail(
            $ticket['user_id'],
            $ticketId,
            'Declined',
            "Your request was declined. Reason: {$reason}",
            ['Reason for Decline' => $reason]
        );

        return $this->successResponse('Ticket declined.', ['ticket_id' => $ticketId, 'status' => 'declined']);
    }

    /**
     * Move a pending ticket from general approval queue to "Approval Delayed" state.
     * Used when awaiting materials procurement, administrative clearance, or site assessment.
     *
     * PATCH /tickets/:id/delay-approval
     */
    public function delayApproval(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        if ($ticket['status'] !== 'pending') {
            return $this->errorResponse("Only pending tickets can have their approval delayed. Current status: {$ticket['status']}.");
        }

        $body    = $this->request->getJSON(true) ?? [];
        $rawPost = $this->request->getRawInput() ?? [];
        $reason  = sanitize_string(
            $body['reason'] 
            ?? $body['delay_reason'] 
            ?? $rawPost['reason'] 
            ?? $rawPost['delay_reason'] 
            ?? $this->request->getPost('reason') 
            ?? $this->request->getPost('delay_reason') 
            ?? ''
        );

        if (empty($reason)) {
            return $this->errorResponse('A delay reason is required (e.g. Awaiting procurement of materials).', [
                'reason' => ['Required.']
            ], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $now    = date('Y-m-d H:i:s');
        $userId = $this->currentUserId();

        $this->ticketModel->update($ticketId, [
            'is_approval_delayed'   => 1,
            'approval_delay_reason' => $reason,
            'approval_delayed_at'   => $now,
            'approval_delayed_by'   => $userId,
            'status_label'          => 'Approval Delayed',
            'updated_at'            => $now,
        ]);

        $this->logModel->logAction(
            $ticketId,
            $userId,
            'Approval Delayed',
            "Ticket approval delayed. Reason: {$reason}"
        );

        $this->notificationModel->createNotification(
            $ticket['user_id'],
            'info',
            "Ticket #{$ticketId} Approval Delayed",
            "Your ticket for {$ticket['service_type']} has been delayed for approval: {$reason}"
        );

        $this->notifyRequestorByEmail(
            $ticket['user_id'],
            $ticketId,
            'Approval Delayed',
            "Your ticket for {$ticket['service_type']} has been held for additional administrative review: {$reason}",
            ['Hold Reason' => $reason]
        );

        return $this->successResponse('Ticket approval delayed successfully.', [
            'ticket_id'             => $ticketId,
            'is_approval_delayed'   => 1,
            'approval_delay_reason' => $reason,
            'status_label'          => 'Approval Delayed'
        ]);
    }

    /**
     * Resume ticket approval workflow, returning the ticket to the general pending approval queue.
     *
     * PATCH /tickets/:id/resume-approval
     */
    public function resumeApproval(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        if (empty($ticket['is_approval_delayed'])) {
            return $this->errorResponse('This ticket is not currently in an approval delayed state.');
        }

        $now    = date('Y-m-d H:i:s');
        $userId = $this->currentUserId();

        $this->ticketModel->update($ticketId, [
            'is_approval_delayed'   => 0,
            'status_label'          => 'Pending Approval',
            'updated_at'            => $now,
        ]);

        $this->logModel->logAction(
            $ticketId,
            $userId,
            'Approval Resumed',
            'Ticket returned to general pending approval queue.'
        );

        $this->notificationModel->createNotification(
            $ticket['user_id'],
            'info',
            "Ticket #{$ticketId} Approval Resumed",
            "Your ticket for {$ticket['service_type']} has been returned to the active approval queue."
        );

        $this->notifyRequestorByEmail(
            $ticket['user_id'],
            $ticketId,
            'Approval Resumed',
            "Your ticket for {$ticket['service_type']} has been returned to the active approval queue."
        );

        return $this->successResponse('Ticket returned to general pending approval queue.', [
            'ticket_id'           => $ticketId,
            'is_approval_delayed' => 0,
            'status_label'        => 'Pending Approval'
        ]);
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

        if ($forbidden = $this->assertTicketAccess($ticket)) {
            return $forbidden;
        }

        // Only the requesting (primary) unit may complete a collaboration ticket.
        $collabModel = new \App\Models\TicketCollaborationModel();
        if ($collabModel->hasLiveCollaboration($ticketId)) {
            $userUnitId = $this->currentUserUnitId();
            if ($userUnitId && (int)$ticket['unit_id'] !== $userUnitId
                && !in_array($this->currentUserRole(), ['director', 'superadmin'], true)) {
                return $this->forbiddenResponse('Only the requesting unit may complete a collaboration ticket.');
            }
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
        $isLaborOnly = !empty($body['is_labor_only']) || !empty($ticket['is_labor_only']);
        $materialsLogged = (!empty($ticket['materials_logged']) || $isLaborOnly) ? 1 : 0;
        $activeAssignment = $db->query(
            "SELECT id FROM ticket_assignments WHERE ticket_id = ? ORDER BY assigned_at DESC LIMIT 1",
            [$ticketId]
        )->getRowArray();
        $assignmentId = $activeAssignment['id'] ?? null;

        if ($isLaborOnly) {
            // Labor only service: clean up materials if empty sent
            if (isset($body['materials']) && empty($body['materials'])) {
                $db->query("DELETE FROM ticket_materials WHERE ticket_id = ?", [$ticketId]);
                if ($assignmentId) {
                    $db->query("DELETE FROM ticket_materials WHERE assignment_id = ?", [$assignmentId]);
                }
            }
            $materialsLogged = 1;
        } elseif (isset($body['materials']) && is_array($body['materials'])) {
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
                    'stage'            => 'finalized',
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
        if ($hasFeedback && ($materialsLogged || $isLaborOnly)) {
            $isArchived  = 1;
            $newStatus   = 'closed';
            $statusLabel = 'Closed';
        } elseif ($materialsLogged || $isLaborOnly) {
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
            'is_labor_only'    => $isLaborOnly ? 1 : 0,
            'materials_stage'  => 'finalized',
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
        foreach ($assignments as $ass) {
            if (!empty($ass['personnel_id'])) {
                $personnelModel->update($ass['personnel_id'], [
                    'status'     => 'available',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $assignmentUpdate = [
            'completed_at' => date('Y-m-d H:i:s'),
            'status'       => 'completed',
        ];
        if (isset($body['dispatcher_notes'])) {
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

        if ($isLaborOnly) {
            $logMessage = "Job Completed (Labor Only Service).";
        } elseif ($materialsLogged && !empty($body['materials'])) {
            $logMessage = "Job Completed & Materials Finalized (" . count($body['materials']) . " items listed).";
        } else {
            $logMessage = "Ticket marked as completed.";
        }

        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Status Changed', $logMessage);

        $this->notificationModel->createNotification(
            $ticket['user_id'], 
            'success', 
            "Ticket #{$ticketId} Completed", 
            "Your request for {$ticket['service_type']} has been marked as completed."
        );

        $this->notifyRequestorByEmail(
            $ticket['user_id'],
            $ticketId,
            'Completed',
            "Work on your service request #{$ticketId} ({$ticket['service_type']}) has been marked as completed. Please sign in to review accomplishment details."
        );

        return $this->successResponse('Ticket completed successfully.', [
            'ticket_id'        => $ticketId, 
            'status'           => $newStatus,
            'status_label'     => $statusLabel,
            'materials_logged' => $materialsLogged,
            'is_labor_only'    => $isLaborOnly ? 1 : 0,
            'materials_stage'  => 'finalized',
            'is_archived'      => $isArchived,
        ]);
    }

    /**
     * Save materials for initial assessment (before job starts) or ongoing adjustments (in progress).
     *
     * POST /tickets/:id/materials
     * Body: {
     *   materials?: [ { material_name, quantity, unit_measurement, unit_price, total_price } ],
     *   is_labor_only?: boolean,
     *   stage?: 'assessment' | 'ongoing',
     *   notes?: string
     * }
     */
    public function saveMaterials(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        $body = $this->request->getJSON(true) ?? [];
        $isLaborOnly = !empty($body['is_labor_only']);
        $rawStage = sanitize_string($body['stage'] ?? '');
        $stage = in_array($rawStage, ['assessment', 'ongoing'], true)
            ? $rawStage
            : ($ticket['status'] === 'processing' ? 'ongoing' : 'assessment');
        $notes = sanitize_string($body['notes'] ?? '');

        $db = Database::connect();
        $db->transStart();

        $materialModel = new \App\Models\TicketMaterialModel();

        // Find active assignment if any
        $activeAssignment = $db->query(
            "SELECT id FROM ticket_assignments WHERE ticket_id = ? ORDER BY assigned_at DESC LIMIT 1",
            [$ticketId]
        )->getRowArray();
        $assignmentId = $activeAssignment['id'] ?? null;

        // Delete existing materials for this ticket to maintain current active list
        $db->query("DELETE FROM ticket_materials WHERE ticket_id = ?", [$ticketId]);
        if ($assignmentId) {
            $db->query("DELETE FROM ticket_materials WHERE assignment_id = ?", [$assignmentId]);
        }

        $savedMaterials = [];
        $totalCost = 0.0;

        if ($isLaborOnly) {
            $this->ticketModel->update($ticketId, [
                'is_labor_only'    => 1,
                'materials_stage'  => $stage,
                'materials_logged' => 1,
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);

            $logDetail = ($stage === 'assessment')
                ? "Initial assessment marked as Labor Only service (no materials required)."
                : "Materials adjusted to Labor Only service." . (!empty($notes) ? " (Notes: {$notes})" : "");
            $this->logModel->logAction($ticketId, $this->currentUserId(), 'Materials Updated', $logDetail);
        } else {
            $rawMaterials = $body['materials'] ?? [];
            if (is_array($rawMaterials)) {
                foreach ($rawMaterials as $mat) {
                    $name = sanitize_string($mat['material_name'] ?? $mat['name'] ?? '');
                    if (empty($name)) {
                        continue;
                    }

                    $qty   = max(0.01, (float) ($mat['quantity'] ?? 1));
                    $unit  = sanitize_string($mat['unit_measurement'] ?? $mat['unit'] ?? 'pcs');
                    $price = max(0.00, (float) ($mat['unit_price'] ?? $mat['price'] ?? 0));
                    $lineTotal = isset($mat['total_price']) ? (float) $mat['total_price'] : ($qty * $price);
                    $totalCost += $lineTotal;

                    $insertedId = $materialModel->insert([
                        'ticket_id'        => $ticketId,
                        'assignment_id'    => $assignmentId,
                        'material_name'    => $name,
                        'quantity'         => $qty,
                        'unit_measurement' => $unit,
                        'unit_price'       => $price,
                        'total_price'      => $lineTotal,
                        'stage'            => $stage,
                        'created_at'       => date('Y-m-d H:i:s'),
                    ], true);

                    $savedMaterials[] = [
                        'id'               => $insertedId,
                        'ticket_id'        => $ticketId,
                        'assignment_id'    => $assignmentId,
                        'material_name'    => $name,
                        'quantity'         => $qty,
                        'unit_measurement' => $unit,
                        'unit_price'       => $price,
                        'total_price'      => $lineTotal,
                        'stage'            => $stage,
                    ];
                }
            }

            $this->ticketModel->update($ticketId, [
                'is_labor_only'    => 0,
                'materials_stage'  => $stage,
                'materials_logged' => !empty($savedMaterials) ? 1 : 0,
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);

            $stageLabel = ($stage === 'assessment') ? 'Initial Material Assessment' : 'Materials Adjusted (Ongoing)';
            $logDetail = "{$stageLabel}: " . count($savedMaterials) . " item(s) recorded. Total: ₱" . number_format($totalCost, 2);
            if (!empty($notes)) {
                $logDetail .= " (Notes: {$notes})";
            }
            $this->logModel->logAction($ticketId, $this->currentUserId(), 'Materials Updated', $logDetail);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->errorResponse('Failed to save materials. Please try again.');
        }

        return $this->successResponse('Materials updated successfully.', [
            'ticket_id'        => $ticketId,
            'is_labor_only'    => $isLaborOnly ? 1 : 0,
            'materials_stage'  => $stage,
            'materials_logged' => ($isLaborOnly || !empty($savedMaterials)) ? 1 : 0,
            'total_cost'       => $totalCost,
            'materials'        => $savedMaterials,
        ]);
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
        if ((string)$ticket['user_id'] !== (string)$userId && !in_array($role, ['admin', 'director', 'superadmin'], true)) {
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

        $this->notifyRequestorByEmail(
            $ticket['user_id'],
            $ticketId,
            'Verified & Closed',
            "Your service request #{$ticketId} has been officially verified and closed. Thank you for using GSO Integrated Services!"
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

}
