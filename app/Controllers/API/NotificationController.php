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

        $notifications = $this->notificationModel
            ->where('user_id', $userId)
            ->orderBy('id', 'DESC')
            ->findAll();

        $unreadCount = count(array_filter($notifications, fn($n) => $n['is_read'] == 0));

        return $this->successResponse('Notifications fetched successfully.', [
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
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
