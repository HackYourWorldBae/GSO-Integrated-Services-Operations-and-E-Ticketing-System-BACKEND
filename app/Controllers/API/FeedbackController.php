<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\TicketFeedbackModel;
use App\Models\TicketModel;
use App\Models\TicketLogModel;
use App\Models\NotificationModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * FeedbackController
 *
 * Handles post-completion evaluation and rating submission from requestors.
 *
 * Endpoints:
 *  POST /api/v1/feedback          - Submit feedback for a completed ticket
 *  GET  /api/v1/feedback/:ticketId - Get feedback for a specific ticket
 */
class FeedbackController extends BaseController
{
    private TicketFeedbackModel $feedbackModel;
    private TicketModel $ticketModel;
    private TicketLogModel $logModel;
    private NotificationModel $notificationModel;

    public function __construct()
    {
        $this->feedbackModel = new TicketFeedbackModel();
        $this->ticketModel   = new TicketModel();
        $this->logModel      = new TicketLogModel();
        $this->notificationModel = new NotificationModel();
    }

    /**
     * Submit a performance evaluation for a completed ticket.
     *
     * Body: {
     *   ticket_id: string,
     *   completion_status: 'on-time' | 'beyond-time' | 'not-completed',
     *   courtesy_rating: 1-5,
     *   quality_rating: 1-5,
     *   efficiency_rating: 1-5,
     *   timeliness_rating: 1-5,
     *   cleanliness_rating: 1-5,
     *   delay_reasons?: [reason_code, ...],
     *   remarks?: string
     * }
     */
    public function submit(): ResponseInterface
    {
        $body     = $this->request->getJSON(true) ?? [];
        $userId   = $this->currentUserId();
        $ticketId = sanitize_string($body['ticket_id'] ?? '');

        // --- Validate ticket_id ---
        if (empty($ticketId)) {
            return $this->errorResponse('ticket_id is required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        // Only requestor who owns the ticket can submit feedback
        if ($ticket['user_id'] !== $userId) {
            return $this->forbiddenResponse('You can only submit feedback for your own tickets.');
        }

        if (!in_array($ticket['status'], ['resolved', 'closed'], true)) {
            return $this->errorResponse('Feedback can only be submitted for completed tickets.');
        }

        // Check for duplicate feedback
        $existing = $this->feedbackModel->getByTicket($ticketId);
        if ($existing) {
            return $this->errorResponse('Feedback has already been submitted for this ticket.');
        }

        // --- Validate Ratings ---
        $ratingFields     = ['quality_rating', 'efficiency_rating', 'timeliness_rating'];
        $completionStatus = sanitize_string($body['completion_status'] ?? 'on-time');

        $validCompletionStatuses = ['on-time', 'beyond-time', 'not-completed'];
        if (!in_array($completionStatus, $validCompletionStatuses, true)) {
            return $this->errorResponse('Invalid completion_status value.');
        }

        $ratings = [];
        foreach ($ratingFields as $field) {
            $val = (int) ($body[$field] ?? 0);
            if ($completionStatus !== 'not-completed') {
                if ($val < 1 || $val > 5) {
                    return $this->errorResponse("Rating '{$field}' must be between 1 and 5.");
                }
            } else {
                $val = 0; // Not completed implies no star rating
            }
            $ratings[$field] = $val;
        }

        $db = Database::connect();
        $db->transStart();

        // --- Insert Feedback ---
        $feedbackId = $this->feedbackModel->insert(array_merge([
            'ticket_id'         => $ticketId,
            'user_id'           => $userId,
            'completion_status' => $completionStatus,
            'remarks'           => sanitize_string($body['remarks'] ?? ''),
            'created_at'        => date('Y-m-d H:i:s'),
        ], $ratings), true);

        // --- Insert Delay Reasons (bridge table, 3NF) ---
        $delayReasons = $body['delay_reasons'] ?? [];
        if (!empty($delayReasons) && is_array($delayReasons)) {
            foreach ($delayReasons as $reasonCode) {
                $code   = sanitize_string($reasonCode);
                $reason = $db->query("SELECT id FROM feedback_delay_reasons WHERE reason_code = ?", [$code])->getRowArray();

                if ($reason) {
                    $db->query(
                        "INSERT IGNORE INTO ticket_feedback_delay_items (feedback_id, delay_reason_id) VALUES (?, ?)",
                        [$feedbackId, $reason['id']]
                    );
                }
            }
        }

        // --- Mark ticket fully archived if not already ---
        $this->ticketModel->update($ticketId, [
            'is_archived' => 1,
            'status'      => 'closed',
            'status_label'=> 'Closed',
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->errorResponse('Failed to save feedback. Please try again.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->logModel->logAction($ticketId, $userId, 'Feedback Submitted', "Rating: {$completionStatus}");

        $admins = $db->query("SELECT id FROM users WHERE role IN ('admin', 'dispatcher') AND unit_id = ?", [$ticket['unit_id']])->getResultArray();
        foreach($admins as $admin) {
            $this->notificationModel->createNotification(
                $admin['id'], 
                'info', 
                "Feedback Received", 
                "User has submitted feedback for Ticket #{$ticketId}."
            );
        }

        return $this->successResponse('Feedback submitted successfully.', [
            'feedback_id' => $feedbackId,
            'ticket_id'   => $ticketId,
        ], ResponseInterface::HTTP_CREATED);
    }

    /**
     * Get feedback for a specific ticket.
     */
    public function show(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        $feedback = $this->feedbackModel->getByTicket($ticketId);

        if (!$feedback) {
            return $this->successResponse('No feedback submitted yet.', ['feedback' => null]);
        }

        // Fetch delay reasons
        $db      = Database::connect();
        $reasons = $db->query("
            SELECT fdr.reason_code, fdr.reason_label
            FROM ticket_feedback_delay_items tfdi
            JOIN feedback_delay_reasons fdr ON fdr.id = tfdi.delay_reason_id
            WHERE tfdi.feedback_id = ?
        ", [$feedback['id']])->getResultArray();

        $feedback['delay_reasons'] = $reasons;

        return $this->successResponse('Feedback retrieved.', ['feedback' => $feedback]);
    }
}
