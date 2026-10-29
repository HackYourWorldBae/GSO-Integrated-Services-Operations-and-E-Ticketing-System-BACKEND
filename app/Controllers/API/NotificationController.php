<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\NotificationModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * NotificationController
 *
 * Handles user notification endpoints.
 * Extends BaseController to inherit RequestContext-based JWT user resolution,
 * which replaced the deprecated $request->jwt_payload dynamic property
 * that was broken by the concurrent users update.
 */
class NotificationController extends BaseController
{
    private NotificationModel $notificationModel;

    public function __construct()
    {
        $this->notificationModel = new NotificationModel();
    }

    /**
     * GET /api/v1/notifications
     * Fetch the authenticated user's notifications.
     */
    public function index(): ResponseInterface
    {
        $userId = $this->currentUserId();

        if (!$userId) {
            return $this->errorResponse('Unauthorized.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $userRole = strtolower($this->currentUserRole() ?? '');

        $query = $this->notificationModel->where('user_id', $userId);
        if ($userRole === 'admin') {
            // Role-Based Notification Defense:
            // Unit Admins only receive notifications of tickets once approved/actionable by their unit;
            // they must never receive or see 'awaiting approval' submissions.
            $query->notLike('title', 'Awaiting Approval')
                  ->notLike('message', 'requires your review and approval');
        }

        $notifications = $query
            ->orderBy('id', 'DESC')
            ->limit(50)
            ->findAll();

        $ticketModel = new \App\Models\TicketModel();
        $ticketIds = [];
        $bogusWords = [
            'submitted', 'created', 'approved', 'declined', 'cancelled', 
            'completed', 'resolved', 'dispatched', 'updated', 'review', 
            'details', 'new', 'under', 'investigation', 'notation', 
            'request', 'ticket', 'incident', 'report'
        ];

        foreach ($notifications as &$n) {
            $ticketId = null;
            $text = ($n['title'] ?? '') . ' ' . ($n['message'] ?? '');

            // 1. Try unit ticket/project code pattern (e.g. FGMU-TIC-4-2026, LEAU-TIC-1-2026, SSU-TIC-2-2026, FGMU-PRJ-1-2026)
            if (preg_match('/\b((?:FGMU|LEAU|SSU)-(?:TIC|PRJ|INC)-[A-Za-z0-9\-_]+)\b/i', $text, $matches)) {
                $ticketId = $matches[1];
            }
            // 2. Try explicit labeled hash (e.g. Ticket #12345, Incident #45, Request #67)
            elseif (preg_match('/(?:Ticket|Incident|Request|Report)\s*#\s*([A-Za-z0-9\-_]+)/i', $text, $matches)) {
                $candidate = $matches[1];
                if (!in_array(strtolower($candidate), $bogusWords, true)) {
                    $ticketId = $candidate;
                }
            }
            // 3. Try general hash (e.g. #FGMU-2026-001 or #1234)
            elseif (preg_match('/#([A-Za-z0-9\-_]+)/', $text, $matches)) {
                $candidate = $matches[1];
                if (!in_array(strtolower($candidate), $bogusWords, true)) {
                    $ticketId = $candidate;
                }
            }

            // Sanitize against common status words
            if ($ticketId && in_array(strtolower($ticketId), $bogusWords, true)) {
                $ticketId = null;
            }

            $n['ticket_id'] = $ticketId;
            if ($ticketId) {
                $ticketIds[] = $ticketId;
            }
        }
        unset($n);

        // Fallback Healing: resolve ticket_id for historical notifications where ticket ID was omitted in text
        $missingIndices = [];
        foreach ($notifications as $idx => $n) {
            if (empty($n['ticket_id']) && !empty($n['created_at'])) {
                $text = strtolower(($n['title'] ?? '') . ' ' . ($n['message'] ?? ''));
                if (!str_contains($text, 'registration') && !str_contains($text, 'new user') && !str_contains($text, 'identity') && !str_contains($text, 'otp') && !str_contains($text, 'password')) {
                    $missingIndices[] = $idx;
                }
            }
        }

        if (!empty($missingIndices)) {
            try {
                $db = \Config\Database::connect();
                foreach ($missingIndices as $idx) {
                    $n = $notifications[$idx];
                    $notifTime = strtotime($n['created_at']);
                    if (!$notifTime) continue;
                    $timeFrom = date('Y-m-d H:i:s', $notifTime - 180);
                    $timeTo   = date('Y-m-d H:i:s', $notifTime + 180);

                    // 1. Check ticket_logs near notification timestamp
                    $logRow = $db->table('ticket_logs')
                        ->select('ticket_id')
                        ->where('created_at >=', $timeFrom)
                        ->where('created_at <=', $timeTo)
                        ->orderBy('id', 'DESC')
                        ->get(1)
                        ->getRowArray();

                    if (!empty($logRow['ticket_id'])) {
                        $notifications[$idx]['ticket_id'] = $logRow['ticket_id'];
                        $ticketIds[] = $logRow['ticket_id'];
                    } else {
                        // 2. Fallback: check requester recent tickets
                        $userTicket = $ticketModel->select('id')
                            ->where('user_id', $userId)
                            ->where('updated_at >=', date('Y-m-d H:i:s', $notifTime - 600))
                            ->where('updated_at <=', date('Y-m-d H:i:s', $notifTime + 600))
                            ->orderBy('updated_at', 'DESC')
                            ->first();

                        if (!empty($userTicket['id'])) {
                            $notifications[$idx]['ticket_id'] = $userTicket['id'];
                            $ticketIds[] = $userTicket['id'];
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal fallback
            }
        }

        if (!empty($ticketIds)) {
            $foundTickets = $ticketModel->select('id, unit_id, status, is_archived, service_type')
                ->whereIn('id', array_unique($ticketIds))
                ->findAll();
            $ticketsMap = [];
            foreach ($foundTickets as $t) {
                $ticketsMap[$t['id']] = $t;
            }
            foreach ($notifications as &$n) {
                if (!empty($n['ticket_id']) && isset($ticketsMap[$n['ticket_id']])) {
                    $t = $ticketsMap[$n['ticket_id']];
                    $n['unit_id']     = (int) $t['unit_id'];
                    $n['status']      = $t['status'];
                    $n['is_archived'] = (int) $t['is_archived'];
                }
            }
            unset($n);
        }

        // Clean ticket numbers from notification titles
        foreach ($notifications as &$n) {
            $n['title'] = NotificationModel::cleanTitle($n['title'] ?? '');
        }
        unset($n);

        $unreadQuery = $this->notificationModel
            ->where('user_id', $userId)
            ->where('is_read', 0);
        if ($userRole === 'admin') {
            $unreadQuery->notLike('title', 'Awaiting Approval')
                        ->notLike('message', 'requires your review and approval');
        }
        $unreadCount = $unreadQuery->countAllResults();

        return $this->successResponse('Notifications fetched successfully.', [
            'notifications' => $notifications,
            'unread_count'  => (int) $unreadCount,
        ]);
    }

    /**
     * POST /api/v1/notifications/read/{id}
     * Mark a specific notification as read.
     */
    public function markAsRead(int $id): ResponseInterface
    {
        $userId = $this->currentUserId();

        if (!$userId) {
            return $this->errorResponse('Unauthorized.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $notif = $this->notificationModel->find($id);

        if (!$notif || $notif['user_id'] !== $userId) {
            return $this->notFoundResponse('Notification');
        }

        $this->notificationModel->update($id, ['is_read' => 1]);

        return $this->successResponse('Notification marked as read.');
    }

    /**
     * POST /api/v1/notifications/read-all
     * Mark all of the user's notifications as read.
     */
    public function markAllAsRead(): ResponseInterface
    {
        $userId = $this->currentUserId();

        if (!$userId) {
            return $this->errorResponse('Unauthorized.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $this->notificationModel
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->set(['is_read' => 1])
            ->update();

        return $this->successResponse('All notifications marked as read.');
    }

    /**
     * DELETE /api/v1/notifications/clear
     * Delete all read notifications for the user.
     */
    public function clearRead(): ResponseInterface
    {
        $userId = $this->currentUserId();

        if (!$userId) {
            return $this->errorResponse('Unauthorized.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $this->notificationModel
            ->where('user_id', $userId)
            ->where('is_read', 1)
            ->delete();

        return $this->successResponse('Read notifications cleared successfully.');
    }
}
