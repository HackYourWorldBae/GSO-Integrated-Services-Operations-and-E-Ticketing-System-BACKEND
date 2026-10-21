<?php

namespace App\Models;

use CodeIgniter\Model;

class TicketLogModel extends Model
{
    protected $table         = 'ticket_logs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['ticket_id', 'user_id', 'action', 'details', 'notes', 'created_at'];

    /**
     * Log a ticket action/event.
     * Never fatal: audit logging must not break the primary operation
     * (e.g. non-ticket callers like inventory logging), so failures are
     * recorded to the error log and reported via the return value.
     */
    public function logAction(string $ticketId, ?string $userId, string $action, ?string $details = null): bool
    {
        try {
            // Auto-tag audit trail with acting unit head coverage if active in JWT payload
            $jwtPayload = \App\Libraries\RequestContext::getJwtPayload();
            if (!empty($jwtPayload['is_acting_head']) && !empty($jwtPayload['acting_unit_code'])) {
                $actingTag = "[Acting " . $jwtPayload['acting_unit_code'] . " Head] ";
                if (!empty($details)) {
                    if (!str_contains($details, '[Acting')) {
                        $details = $actingTag . $details;
                    }
                } else {
                    $details = $actingTag . "Action performed by Director while covering as Acting Unit Head.";
                }
            }

            $logData = [
                'ticket_id'  => $ticketId,
                'user_id'    => $userId,
                'action'     => $action,
                'created_at' => date('Y-m-d H:i:s'),
            ];

            // Gracefully adapt to both 3NF standard 'details' column and legacy 'notes' column
            if ($this->db->fieldExists('details', $this->table)) {
                $logData['details'] = $details;
            } elseif ($this->db->fieldExists('notes', $this->table)) {
                $logData['notes'] = $details;
            } else {
                $logData['details'] = $details;
            }

            return (bool) $this->insert($logData);
        } catch (\Throwable $e) {
            log_message('error', '[TicketLogModel::logAction] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all logs for a ticket, ordered chronologically.
     */
    public function getByTicket(string $ticketId): array
    {
        $db = \Config\Database::connect();
        $detailCol = $db->fieldExists('details', 'ticket_logs') ? 'tl.details' : ($db->fieldExists('notes', 'ticket_logs') ? 'tl.notes' : "''");

        return $db->query("
            SELECT tl.id, tl.ticket_id, tl.user_id, tl.action,
                   {$detailCol} AS details,
                   tl.created_at,
                   CONCAT(u.first_name, ' ', u.last_name) AS actor_name, u.role AS actor_role
            FROM ticket_logs tl
            LEFT JOIN users u ON u.id = tl.user_id
            WHERE tl.ticket_id = ?
            ORDER BY tl.created_at ASC
        ", [$ticketId])->getResultArray();
    }
}

