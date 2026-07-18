<?php

namespace App\Models;

use CodeIgniter\Model;

class TicketLogModel extends Model
{
    protected $table         = 'ticket_logs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['ticket_id', 'user_id', 'action', 'details', 'created_at'];

    /**
     * Log a ticket action/event.
     */
    public function logAction(string $ticketId, ?string $userId, string $action, ?string $details = null): bool
    {
        return (bool) $this->insert([
            'ticket_id'  => $ticketId,
            'user_id'    => $userId,
            'action'     => $action,
            'details'    => $details,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get all logs for a ticket, ordered chronologically.
     */
    public function getByTicket(string $ticketId): array
    {
        $db = \Config\Database::connect();

        return $db->query("
            SELECT tl.*, CONCAT(u.first_name, ' ', u.last_name) AS actor_name, u.role AS actor_role
            FROM ticket_logs tl
            LEFT JOIN users u ON u.id = tl.user_id
            WHERE tl.ticket_id = ?
            ORDER BY tl.created_at ASC
        ", [$ticketId])->getResultArray();
    }
}
