<?php

namespace App\Controllers\API;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;
use App\Models\NotificationModel;

class NotificationController extends ResourceController
{
    use ResponseTrait;

    protected $notificationModel;

    public function __construct()
    {
        $this->notificationModel = new NotificationModel();
    }

    /**
     * Get the current authenticated user's ID
     */
    protected function currentUserId(): ?string
    {
        return $this->request->jwt_payload['id'] ?? null;
    }

    /**
     * Helper to return standard success JSON
     */
    protected function successResponse(string $message, $data = [], int $status = 200)
    {
        return $this->respond([
            'status'  => $status,
            'message' => $message,
            'data'    => $data
        ], $status);
    }

    /**
     * Helper to return standard error JSON
     */
    protected function errorResponse(string $message, $errors = [], int $status = 400)
    {
        $response = [
            'status'  => $status,
            'message' => $message,
        ];
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }
        return $this->respond($response, $status);
    }

    /**
     * GET /api/notifications
     * Fetch the user's notifications.
     */
    public function index()
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            return $this->errorResponse('Unauthorized', [], 401);
        }

        $notifications = $this->notificationModel
            ->where('user_id', $userId)
            ->orderBy('id', 'DESC')
            ->findAll();

        $unreadCount = count(array_filter($notifications, fn($n) => $n['is_read'] == 0));

        return $this->successResponse('Notifications fetched successfully', [
            'notifications' => $notifications,
            'unread_count'  => $unreadCount
        ]);
    }

    /**
     * POST /api/notifications/read/{id}
     * Mark a specific notification as read.
     */
    public function markAsRead($id)
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            return $this->errorResponse('Unauthorized', [], 401);
        }

        $notif = $this->notificationModel->find($id);
        if (!$notif || $notif['user_id'] != $userId) {
            return $this->errorResponse('Notification not found', [], 404);
        }

        $this->notificationModel->update($id, ['is_read' => 1]);

        return $this->successResponse('Notification marked as read');
    }

    /**
     * POST /api/notifications/read-all
     * Mark all of the user's notifications as read.
     */
    public function markAllAsRead()
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            return $this->errorResponse('Unauthorized', [], 401);
        }

        $this->notificationModel
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->set(['is_read' => 1])
            ->update();

        return $this->successResponse('All notifications marked as read');
    }

    /**
     * DELETE /api/notifications/clear
     * Delete all READ notifications for the user.
     */
    public function clearRead()
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            return $this->errorResponse('Unauthorized', [], 401);
        }

        $this->notificationModel
            ->where('user_id', $userId)
            ->where('is_read', 1)
            ->delete();

        return $this->successResponse('Read notifications cleared successfully');
    }
}
