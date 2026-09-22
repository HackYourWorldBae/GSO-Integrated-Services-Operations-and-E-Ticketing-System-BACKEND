<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\BorrowingRequestModel;
use App\Models\InventoryItemModel;
use App\Models\TicketModel;
use App\Models\TicketLogModel;
use App\Models\NotificationModel;
use App\Models\UserModel;
use App\Libraries\ResendEmailService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * BorrowingController - Handles LEAU borrowing workflow.
 *
 * Workflow:
 * 1. Requestor submits borrowing request (ticket created via intake)
 * 2. Director reviews and approves/rejects (pending_director -> approved_director)
 * 3. LEAU Admin assigns inventory (approved_director -> inventory_assigned)
 * 4. LEAU Admin marks ready for pickup (inventory_assigned -> ready_for_pickup)
 * 5. Borrower picks up item (ready_for_pickup -> picked_up)
 * 6. System auto-marks overdue if past expected_return_date (picked_up -> overdue)
 * 7. LEAU Admin/Borrower marks returned (picked_up/overdue -> returned)
 * 8. Auto-archive on return (no rating form)
 */
class BorrowingController extends BaseController
{
    private BorrowingRequestModel $borrowingModel;
    private InventoryItemModel $inventoryModel;
    private TicketModel $ticketModel;
    private TicketLogModel $logModel;
    private NotificationModel $notificationModel;

    private const UNIT_MAP = [
        'FGMU' => 1,
        'LEAU' => 2,
        'SSU'  => 3,
    ];

    public function __construct()
    {
        $this->borrowingModel   = new BorrowingRequestModel();
        $this->inventoryModel   = new InventoryItemModel();
        $this->ticketModel      = new TicketModel();
        $this->logModel         = new TicketLogModel();
        $this->notificationModel = new NotificationModel();
    }

    // -------------------------------------------------------------------------
    // Director Actions
    // -------------------------------------------------------------------------

    /**
     * Director approves a borrowing request.
     * PATCH /api/v1/borrowing/{ticketId}/director-approve
     */
    public function directorApprove(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['director', 'superadmin'], true)) {
            return $this->forbiddenResponse('Only Director or Superadmin can approve borrowing requests.');
        }

        $borrowing = $this->borrowingModel->getByTicket($ticketId);
        if (!$borrowing) {
            return $this->notFoundResponse('Borrowing request not found for this ticket.');
        }

        if ($borrowing['status'] !== 'pending_director') {
            return $this->errorResponse("Borrowing request is not pending director approval. Current status: {$borrowing['status']}.");
        }

        $body = $this->request->getJSON(true) ?? [];
        $notes = sanitize_string($body['notes'] ?? '');

        $this->borrowingModel->update($borrowing['id'], [
            'status'           => 'approved_director',
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $this->ticketModel->update($ticketId, [
            'status'                => 'approved',
            'status_label'          => 'Approved - Awaiting Inventory Assignment',
            'is_approval_delayed'   => 0,
            'approval_delay_reason' => null,
            'current_step'          => 3,
            'reviewed_at'           => date('Y-m-d H:i:s'),
            'reviewed_by'           => $this->currentUserId(),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Borrowing Director Approved', "Director approved borrowing request. Notes: {$notes}");

        $this->notificationModel->createNotification(
            $ticket['user_id'],
            'success',
            "Borrowing Request Approved",
            "Your borrowing request #{$ticketId} has been approved by the Director. LEAU Admin will now assign inventory."
        );

        $this->sendBorrowingEmail($ticket['user_id'], $ticketId, 'approved_director', "Your borrowing request has been approved by the Director.");

        return $this->successResponse('Borrowing request approved by Director.', [
            'borrowing_id' => $borrowing['id'],
            'status'       => 'approved_director',
        ]);
    }

    /**
     * Director rejects a borrowing request.
     * PATCH /api/v1/borrowing/{ticketId}/director-reject
     */
    public function directorReject(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['director', 'superadmin'], true)) {
            return $this->forbiddenResponse('Only Director or Superadmin can reject borrowing requests.');
        }

        $borrowing = $this->borrowingModel->getByTicket($ticketId);
        if (!$borrowing) {
            return $this->notFoundResponse('Borrowing request not found for this ticket.');
        }

        if ($borrowing['status'] !== 'pending_director') {
            return $this->errorResponse("Borrowing request is not pending director approval. Current status: {$borrowing['status']}.");
        }

        $body = $this->request->getJSON(true) ?? [];
        $reason = sanitize_string($body['reason'] ?? 'Rejected by Director');

        $this->borrowingModel->update($borrowing['id'], [
            'status'           => 'cancelled',
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $this->ticketModel->update($ticketId, [
            'status'         => 'declined',
            'status_label'   => 'Declined by Director',
            'decline_reason' => $reason,
            'is_archived'    => 1,
            'completed_at'   => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Borrowing Director Rejected', "Director rejected borrowing request. Reason: {$reason}");

        $this->notificationModel->createNotification(
            $ticket['user_id'],
            'error',
            "Borrowing Request Declined",
            "Your borrowing request #{$ticketId} has been declined. Reason: {$reason}"
        );

        $this->sendBorrowingEmail($ticket['user_id'], $ticketId, 'declined', "Your borrowing request has been declined. Reason: {$reason}");

        return $this->successResponse('Borrowing request rejected by Director.', [
            'borrowing_id' => $borrowing['id'],
            'status'       => 'cancelled',
        ]);
    }

    // -------------------------------------------------------------------------
    // LEAU Admin Actions
    // -------------------------------------------------------------------------

    /**
     * LEAU Admin assigns inventory to an approved borrowing request.
     * POST /api/v1/borrowing/{ticketId}/assign-inventory
     */
    public function assignInventory(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('Only LEAU Admin can assign inventory.');
        }

        if ((int) $ticket['unit_id'] !== 2) {
            return $this->errorResponse('Inventory assignment is only available for LEAU tickets.');
        }

        $borrowing = $this->borrowingModel->getByTicket($ticketId);
        if (!$borrowing) {
            return $this->notFoundResponse('Borrowing request not found for this ticket.');
        }

        if ($borrowing['status'] !== 'approved_director') {
            return $this->errorResponse("Borrowing request must be approved by Director first. Current status: {$borrowing['status']}.");
        }

        $body = $this->request->getJSON(true) ?? [];

        $inventoryId = sanitize_string($body['inventory_id'] ?? '');
        $assignedQuantity = (int) ($body['assigned_quantity'] ?? 1);

        if (empty($inventoryId)) {
            return $this->errorResponse('inventory_id is required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $inventory = $this->inventoryModel->find($inventoryId);
        if (!$inventory) {
            return $this->notFoundResponse('Inventory item');
        }

        if ((int) $inventory['unit_id'] !== 2) {
            return $this->errorResponse('Inventory item does not belong to LEAU.');
        }

        if ((int) $inventory['quantity_available'] < $assignedQuantity) {
            return $this->errorResponse("Insufficient quantity available. Available: {$inventory['quantity_available']}, Requested: {$assignedQuantity}.");
        }

        $db = Database::connect();
        $db->transStart();

        try {
            // Update inventory availability
            $newAvailable = (int) $inventory['quantity_available'] - $assignedQuantity;
            $this->inventoryModel->update($inventoryId, [
                'quantity_available' => $newAvailable,
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);

            // Update borrowing request
            $this->borrowingModel->update($borrowing['id'], [
                'status'               => 'inventory_assigned',
                'assigned_inventory_id'=> $inventoryId,
                'assigned_quantity'    => $assignedQuantity,
                'updated_at'           => date('Y-m-d H:i:s'),
            ]);

            // Update ticket
            $this->ticketModel->update($ticketId, [
                'status'       => 'processing',
                'status_label' => 'Inventory Assigned - Awaiting Pickup Prep',
                'current_step' => 4,
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);

            $this->logModel->logAction($ticketId, $this->currentUserId(), 'Inventory Assigned', "Assigned {$assignedQuantity} unit(s) of '{$inventory['name']}' (ID: {$inventoryId}) to borrowing request.");

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->errorResponse('Transaction failed.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
            }

            $this->notificationModel->createNotification(
                $ticket['user_id'],
                'info',
                "Inventory Assigned",
                "Inventory has been assigned to your borrowing request #{$ticketId}. LEAU Admin will prepare it for pickup."
            );

            return $this->successResponse('Inventory assigned successfully.', [
                'borrowing_id'    => $borrowing['id'],
                'inventory_id'    => $inventoryId,
                'assigned_quantity' => $assignedQuantity,
                'status'          => 'inventory_assigned',
            ]);
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[BorrowingController::assignInventory] ' . $e->getMessage());
            return $this->errorResponse('Failed to assign inventory.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * LEAU Admin marks borrowing as ready for pickup.
     * PATCH /api/v1/borrowing/{ticketId}/ready-for-pickup
     */
    public function readyForPickup(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('Only LEAU Admin can mark ready for pickup.');
        }

        $borrowing = $this->borrowingModel->getByTicket($ticketId);
        if (!$borrowing) {
            return $this->notFoundResponse('Borrowing request not found for this ticket.');
        }

        if ($borrowing['status'] !== 'inventory_assigned') {
            return $this->errorResponse("Borrowing request must have inventory assigned first. Current status: {$borrowing['status']}.");
        }

        $body = $this->request->getJSON(true) ?? [];
        $notes = sanitize_string($body['notes'] ?? '');

        $this->borrowingModel->update($borrowing['id'], [
            'status'     => 'ready_for_pickup',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->ticketModel->update($ticketId, [
            'status'       => 'processing',
            'status_label' => 'Ready for Pickup',
            'current_step' => 5,
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Ready for Pickup', "Marked as ready for pickup. Notes: {$notes}");

        $this->notificationModel->createNotification(
            $ticket['user_id'],
            'success',
            "Ready for Pickup",
            "Your borrowing request #{$ticketId} is ready for pickup. Please visit LEAU office to collect the item(s)."
        );

        $this->sendBorrowingEmail($ticket['user_id'], $ticketId, 'ready_for_pickup', "Your borrowing request is ready for pickup. Please visit LEAU office to collect the item(s).");

        return $this->successResponse('Marked as ready for pickup.', [
            'borrowing_id' => $borrowing['id'],
            'status'       => 'ready_for_pickup',
        ]);
    }

    /**
     * LEAU Admin marks item as picked up by borrower.
     * PATCH /api/v1/borrowing/{ticketId}/pickup
     */
    public function pickup(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('Only LEAU Admin can record pickup.');
        }

        $borrowing = $this->borrowingModel->getByTicket($ticketId);
        if (!$borrowing) {
            return $this->notFoundResponse('Borrowing request not found for this ticket.');
        }

        if ($borrowing['status'] !== 'ready_for_pickup') {
            return $this->errorResponse("Borrowing request must be ready for pickup first. Current status: {$borrowing['status']}.");
        }

        $body = $this->request->getJSON(true) ?? [];
        $pickedUpBy = sanitize_string($body['picked_up_by'] ?? $this->currentUserId());
        $notes = sanitize_string($body['notes'] ?? '');

        $this->borrowingModel->update($borrowing['id'], [
            'status'          => 'picked_up',
            'picked_up_at'    => date('Y-m-d H:i:s'),
            'picked_up_by'    => $pickedUpBy,
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->ticketModel->update($ticketId, [
            'status'       => 'processing',
            'status_label' => 'Item Picked Up',
            'current_step' => 6,
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction($ticketId, $this->currentUserId(), 'Item Picked Up', "Item picked up by borrower. Notes: {$notes}");

        $this->notificationModel->createNotification(
            $ticket['user_id'],
            'info',
            "Item Picked Up",
            "You have picked up the item(s) for borrowing request #{$ticketId}. Expected return date: " . date('M d, Y', strtotime($borrowing['expected_return_date'])) . "."
        );

        return $this->successResponse('Pickup recorded.', [
            'borrowing_id' => $borrowing['id'],
            'status'       => 'picked_up',
        ]);
    }

    /**
     * LEAU Admin marks item as returned.
     * PATCH /api/v1/borrowing/{ticketId}/return
     */
    public function returnItem(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('Only LEAU Admin can record return.');
        }

        $borrowing = $this->borrowingModel->getByTicket($ticketId);
        if (!$borrowing) {
            return $this->notFoundResponse('Borrowing request not found for this ticket.');
        }

        if (!in_array($borrowing['status'], ['picked_up', 'overdue'], true)) {
            return $this->errorResponse("Borrowing request must be picked up or overdue to be returned. Current status: {$borrowing['status']}.");
        }

        $body = $this->request->getJSON(true) ?? [];

        $returnCondition = sanitize_string($body['return_condition'] ?? 'good');
        $returnNotes = sanitize_string($body['return_notes'] ?? '');
        $returnedBy = sanitize_string($body['returned_by'] ?? $this->currentUserId());

        // Validate return condition
        $validConditions = ['excellent', 'good', 'fair', 'damaged', 'lost'];
        if (!in_array($returnCondition, $validConditions, true)) {
            $returnCondition = 'good';
        }

        $db = Database::connect();
        $db->transStart();

        try {
            // Restore inventory quantity
            if ($borrowing['assigned_inventory_id'] && $borrowing['assigned_quantity']) {
                $inventory = $this->inventoryModel->find($borrowing['assigned_inventory_id']);
                if ($inventory) {
                    $newAvailable = (int) $inventory['quantity_available'] + (int) $borrowing['assigned_quantity'];
                    $this->inventoryModel->update($borrowing['assigned_inventory_id'], [
                        'quantity_available' => $newAvailable,
                        'updated_at'         => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            // Update borrowing request
            $this->borrowingModel->update($borrowing['id'], [
                'status'            => 'returned',
                'returned_at'       => date('Y-m-d H:i:s'),
                'returned_by'       => $returnedBy,
                'return_condition'  => $returnCondition,
                'return_notes'      => $returnNotes,
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);

            // Update ticket - auto archive, no rating form
            $this->ticketModel->update($ticketId, [
                'status'         => 'closed',
                'status_label'   => 'Returned & Completed',
                'is_archived'    => 1,
                'completed_at'   => date('Y-m-d H:i:s'),
                'current_step'   => 7,
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);

            $this->logModel->logAction($ticketId, $this->currentUserId(), 'Item Returned', "Item returned. Condition: {$returnCondition}. Notes: {$returnNotes}");

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->errorResponse('Transaction failed.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
            }

            $this->notificationModel->createNotification(
                $ticket['user_id'],
                'success',
                "Item Returned",
                "Your borrowed item(s) for request #{$ticketId} have been returned and the request is now complete."
            );

            return $this->successResponse('Item returned and request completed.', [
                'borrowing_id' => $borrowing['id'],
                'status'       => 'returned',
            ]);
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[BorrowingController::returnItem] ' . $e->getMessage());
            return $this->errorResponse('Failed to process return.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * LEAU Admin cancels a borrowing request.
     * PATCH /api/v1/borrowing/{ticketId}/cancel
     */
    public function cancel(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('Only LEAU Admin can cancel borrowing requests.');
        }

        $borrowing = $this->borrowingModel->getByTicket($ticketId);
        if (!$borrowing) {
            return $this->notFoundResponse('Borrowing request not found for this ticket.');
        }

        if (in_array($borrowing['status'], ['returned', 'cancelled'], true)) {
            return $this->errorResponse("Cannot cancel a borrowing request that is already {$borrowing['status']}.");
        }

        $body = $this->request->getJSON(true) ?? [];
        $reason = sanitize_string($body['reason'] ?? 'Cancelled by LEAU Admin');

        $db = Database::connect();
        $db->transStart();

        try {
            // Restore inventory if assigned
            if ($borrowing['assigned_inventory_id'] && $borrowing['assigned_quantity'] && in_array($borrowing['status'], ['inventory_assigned', 'ready_for_pickup', 'picked_up', 'overdue'], true)) {
                $inventory = $this->inventoryModel->find($borrowing['assigned_inventory_id']);
                if ($inventory) {
                    $newAvailable = (int) $inventory['quantity_available'] + (int) $borrowing['assigned_quantity'];
                    $this->inventoryModel->update($borrowing['assigned_inventory_id'], [
                        'quantity_available' => $newAvailable,
                        'updated_at'         => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            $this->borrowingModel->update($borrowing['id'], [
                'status'     => 'cancelled',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $this->ticketModel->update($ticketId, [
                'status'         => 'cancelled',
                'status_label'   => 'Cancelled',
                'decline_reason' => $reason,
                'is_archived'    => 1,
                'completed_at'   => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);

            $this->logModel->logAction($ticketId, $this->currentUserId(), 'Borrowing Cancelled', "Borrowing request cancelled. Reason: {$reason}");

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->errorResponse('Transaction failed.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
            }

            $this->notificationModel->createNotification(
                $ticket['user_id'],
                'warning',
                "Borrowing Request Cancelled",
                "Your borrowing request #{$ticketId} has been cancelled. Reason: {$reason}"
            );

            return $this->successResponse('Borrowing request cancelled.', [
                'borrowing_id' => $borrowing['id'],
                'status'       => 'cancelled',
            ]);
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[BorrowingController::cancel] ' . $e->getMessage());
            return $this->errorResponse('Failed to cancel borrowing request.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // Read Endpoints
    // -------------------------------------------------------------------------

    /**
     * Get borrowing request by ticket ID.
     * GET /api/v1/borrowing/{ticketId}
     */
    public function getByTicket(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        // Authorization check
        $userId = $this->currentUserId();
        $role   = $this->currentUserRole();
        if ((string) $ticket['user_id'] !== (string) $userId && !$this->isStaffRole()) {
            return $this->forbiddenResponse('You do not have permission to view this borrowing request.');
        }

        if ($this->isStaffRole() && $role === 'admin') {
            if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
                return $forbidden;
            }
        }

        $borrowing = $this->borrowingModel->getByTicket($ticketId);
        if (!$borrowing) {
            return $this->notFoundResponse('Borrowing request not found for this ticket.');
        }

        // Get attachments
        $attachmentModel = new \App\Models\BorrowingAttachmentModel();
        $attachments = $attachmentModel->getByBorrowingRequest($borrowing['id']);

        // Get history
        $historyModel = new \App\Models\BorrowingHistoryModel();
        $history = $historyModel->getByBorrowingRequest($borrowing['id']);

        // Get assigned inventory details
        $inventory = null;
        if ($borrowing['assigned_inventory_id']) {
            $inventory = $this->inventoryModel->find($borrowing['assigned_inventory_id']);
        }

        return $this->successResponse('Borrowing request retrieved.', [
            'borrowing'   => $borrowing,
            'attachments' => $attachments,
            'history'     => $history,
            'inventory'   => $inventory,
        ]);
    }

    /**
     * Get all borrowing requests for LEAU admin dashboard.
     * GET /api/v1/borrowing/queue/leau?status=...
     */
    public function getQueue(): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess('LEAU')) {
            return $forbidden;
        }

        $status = sanitize_string($this->request->getGet('status') ?? '');
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(500, max(1, (int) ($this->request->getGet('per_page') ?? 100)));

        $tickets = $this->borrowingModel->getQueue($status, $perPage, ($page - 1) * $perPage);

        return $this->successResponse('Borrowing queue retrieved.', [
            'borrowing_requests' => $tickets,
            'count'              => count($tickets),
            'page'               => $page,
            'per_page'           => $perPage,
        ]);
    }

    /**
     * Get overdue borrowing requests.
     * GET /api/v1/borrowing/overdue
     */
    public function getOverdue(): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess('LEAU')) {
            return $forbidden;
        }

        $overdue = $this->borrowingModel->getOverdue();

        return $this->successResponse('Overdue borrowing requests retrieved.', [
            'overdue_requests' => $overdue,
            'count'            => count($overdue),
        ]);
    }

    /**
     * Mark overdue items (scheduled job or manual trigger).
     * POST /api/v1/borrowing/mark-overdue
     */
    public function markOverdue(): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess('LEAU')) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('Only LEAU Admin can mark overdue.');
        }

        $updated = $this->borrowingModel->markOverdue();

        return $this->successResponse('Overdue items marked.', [
            'updated_count' => $updated,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    private function sendBorrowingEmail(string $userId, string $ticketId, string $statusLabel, string $message): void
    {
        try {
            $userModel = new UserModel();
            $user = $userModel->find($userId);
            if ($user && !empty($user['email'])) {
                $reqName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                if (empty($reqName)) {
                    $reqName = 'Campus Member';
                }
                $emailService = new ResendEmailService();
                $emailService->sendTicketStatusUpdate($user['email'], $reqName, $ticketId, $statusLabel, $message);
            }
        } catch (\Throwable $e) {
            log_message('error', '[BorrowingController::sendBorrowingEmail] ' . $e->getMessage());
        }
    }
}