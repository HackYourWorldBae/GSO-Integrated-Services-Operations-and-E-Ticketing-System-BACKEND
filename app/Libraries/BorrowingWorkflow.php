<?php

namespace App\Libraries;

/**
 * BorrowingWorkflow - Pure state-machine helpers for the LEAU borrowing
 * workflow (Borrowing of Plants / Tools & Equipment).
 *
 * Mirrors the status flow enforced by BorrowingController so the rules are
 * unit-testable without a database or HTTP layer:
 *
 * pending_director -> approved_director -> inventory_assigned
 *   -> ready_for_pickup -> picked_up -> returned (terminal)
 * picked_up -> overdue -> returned (terminal)
 * any non-terminal -> cancelled (terminal)
 *
 * Returned requests auto-archive with no rating form; overdue is a simple
 * visual flag (no fines, no escalation).
 */
class BorrowingWorkflow
{
    public const STATUS_PENDING_DIRECTOR  = 'pending_director';
    public const STATUS_APPROVED_DIRECTOR = 'approved_director';
    public const STATUS_INVENTORY_ASSIGNED = 'inventory_assigned';
    public const STATUS_READY_FOR_PICKUP  = 'ready_for_pickup';
    public const STATUS_PICKED_UP         = 'picked_up';
    public const STATUS_OVERDUE           = 'overdue';
    public const STATUS_RETURNED          = 'returned';
    public const STATUS_CANCELLED         = 'cancelled';

    /** @var list<string> */
    public const TERMINAL_STATUSES = [
        self::STATUS_RETURNED,
        self::STATUS_CANCELLED,
    ];

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        self::STATUS_PENDING_DIRECTOR   => [self::STATUS_APPROVED_DIRECTOR, self::STATUS_CANCELLED],
        self::STATUS_APPROVED_DIRECTOR  => [self::STATUS_INVENTORY_ASSIGNED, self::STATUS_CANCELLED],
        self::STATUS_INVENTORY_ASSIGNED => [self::STATUS_READY_FOR_PICKUP, self::STATUS_APPROVED_DIRECTOR, self::STATUS_CANCELLED],
        self::STATUS_READY_FOR_PICKUP   => [self::STATUS_PICKED_UP, self::STATUS_CANCELLED],
        self::STATUS_PICKED_UP          => [self::STATUS_OVERDUE, self::STATUS_RETURNED, self::STATUS_CANCELLED],
        self::STATUS_OVERDUE            => [self::STATUS_RETURNED, self::STATUS_CANCELLED],
        self::STATUS_RETURNED           => [],
        self::STATUS_CANCELLED          => [],
    ];

    /** @var list<string> */
    public const RETURN_CONDITIONS = ['excellent', 'good', 'fair', 'damaged', 'lost'];

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        $allowed = self::TRANSITIONS[$from] ?? null;
        if (!is_array($allowed)) {
            return false;
        }

        return in_array($to, $allowed, true);
    }

    /**
     * A picked-up request is overdue when today passes its expected return date.
     */
    public static function isOverdue(string $status, ?string $expectedReturnDate, ?string $today = null): bool
    {
        if ($status === self::STATUS_OVERDUE) {
            return true;
        }

        if ($status !== self::STATUS_PICKED_UP || empty($expectedReturnDate)) {
            return false;
        }

        $today ??= date('Y-m-d');

        return $expectedReturnDate < $today;
    }

    /**
     * Clamp an assignment quantity to sane bounds.
     */
    public static function clampAssignQuantity(int $quantity, int $available, int $requested): int
    {
        $avail = max(0, $available);
        $req   = max(1, $requested);
        $qty   = max(1, $quantity);

        return min($qty, max(1, min($avail, $req)));
    }

    /**
     * Inventory may only be assigned to director-approved requests.
     */
    public static function canAssignInventory(string $status): bool
    {
        return $status === self::STATUS_APPROVED_DIRECTOR;
    }

    /**
     * Inventory may only be unassigned before being marked ready for pickup.
     */
    public static function canUnassignInventory(string $status): bool
    {
        return $status === self::STATUS_INVENTORY_ASSIGNED;
    }

    /**
     * Returned requests auto-archive as closed with no rating form.
     *
     * @param array<string, mixed> $ticket
     * @return array<string, mixed>
     */
    public static function returnTicketPatch(array $ticket): array
    {
        return [
            'status'       => 'closed',
            'status_label' => 'Returned & Completed',
            'is_archived'  => 1,
            'completed_at' => date('Y-m-d H:i:s'),
            'current_step' => 7,
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
    }

    public static function isValidReturnCondition(string $condition): bool
    {
        return in_array($condition, self::RETURN_CONDITIONS, true);
    }

    /**
     * Translate borrowing status into user-facing ticket status label.
     */
    public static function getTicketStatusLabel(string $borrowingStatus, string $ticketStatus = 'approved'): string
    {
        return match ($borrowingStatus) {
            self::STATUS_PENDING_DIRECTOR   => 'Pending Approval',
            self::STATUS_APPROVED_DIRECTOR  => 'Approved - Awaiting Inventory Assignment',
            self::STATUS_INVENTORY_ASSIGNED => 'Inventory Assigned - Awaiting Pickup Prep',
            self::STATUS_READY_FOR_PICKUP   => 'Ready for Pickup',
            self::STATUS_PICKED_UP          => 'Item Picked Up',
            self::STATUS_OVERDUE            => 'Overdue for Return',
            self::STATUS_RETURNED           => 'Returned & Completed',
            self::STATUS_CANCELLED          => ($ticketStatus === 'declined' ? 'Declined' : 'Cancelled'),
            default                         => ($ticketStatus === 'approved' ? 'Approved - Awaiting Inventory Assignment' : 'Pending Approval'),
        };
    }

    /**
     * Checks if a service type string represents a borrowing request.
     */
    public static function isBorrowingService(?string $serviceType): bool
    {
        if (empty($serviceType)) {
            return false;
        }

        $lower = strtolower(trim($serviceType));

        return str_starts_with($lower, 'borrow')
            || str_contains($lower, 'borrowing')
            || str_contains($lower, 'borrowed');
    }

    /**
     * Checks if a ticket represents a borrowing request.
     * Evaluates explicit borrowing relation, service type string, or borrowing_requests table entry.
     *
     * @param array<string, mixed> $ticket
     */
    public static function isBorrowingTicket(array $ticket): bool
    {
        if (!empty($ticket['borrowing'])) {
            return true;
        }

        if (self::isBorrowingService($ticket['service_type'] ?? null)) {
            return true;
        }

        $ticketId = (string) ($ticket['id'] ?? $ticket['ticket_id'] ?? '');
        if ($ticketId !== '') {
            return (new \App\Models\BorrowingRequestModel())->hasBorrowingRequest($ticketId);
        }

        return false;
    }

    /**
     * Self-heals any borrowing tickets that were erroneously dispatched to workers.
     * Reverts tickets back to approved, purges rogue ticket_assignments, and frees the worker.
     */
    public static function healMisdispatchedBorrowingTickets(?int $unitId = null): void
    {
        try {
            $db = \Config\Database::connect();
            
            $sql = "
                SELECT t.id, t.status, b.status as borrowing_status, a.id as assignment_id, a.personnel_id
                FROM tickets t
                JOIN borrowing_requests b ON b.ticket_id = t.id
                JOIN ticket_assignments a ON a.ticket_id = t.id
                WHERE (b.status IN ('approved_director', 'pending', 'pending_director') OR b.status IS NULL)
            ";
            if ($unitId !== null) {
                $sql .= " AND t.unit_id = " . (int)$unitId;
            }

            $misdispatched = $db->query($sql)->getResultArray();
            if (empty($misdispatched)) {
                return;
            }

            $ticketModel = new \App\Models\TicketModel();
            $personnelModel = new \App\Models\PersonnelModel();

            $processedTicketIds = [];
            $affectedPersonnelIds = [];

            foreach ($misdispatched as $row) {
                $tId = $row['id'];
                $aId = $row['assignment_id'];
                $pId = $row['personnel_id'];

                // Delete rogue assignment
                $db->table('ticket_assignments')->where('id', $aId)->delete();
                $affectedPersonnelIds[] = $pId;

                if (!in_array($tId, $processedTicketIds, true)) {
                    $processedTicketIds[] = $tId;
                    $bStatus = $row['borrowing_status'] ?: 'approved_director';
                    $statusLabel = self::getTicketStatusLabel($bStatus, 'approved');

                    $ticketModel->update($tId, [
                        'status'                 => 'approved',
                        'status_label'           => $statusLabel,
                        'current_step'           => 3,
                        'target_completion_date' => null,
                        'project_working_days'   => null,
                        'updated_at'             => date('Y-m-d H:i:s'),
                    ]);

                    // Clean up any ticket_materials marked as assessment
                    $db->table('ticket_materials')->where('ticket_id', $tId)->delete();
                }
            }

            // Recheck affected personnel availability
            foreach (array_unique($affectedPersonnelIds) as $pId) {
                if (!$pId) {
                    continue;
                }
                $hasOtherActive = $db->table('ticket_assignments')
                    ->where('personnel_id', $pId)
                    ->where('completed_at IS NULL')
                    ->countAllResults() > 0;

                if (!$hasOtherActive) {
                    $personnelModel->update($pId, [
                        'status'     => 'available',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'BorrowingWorkflow healMisdispatchedBorrowingTickets error: ' . $e->getMessage());
        }
    }
}

