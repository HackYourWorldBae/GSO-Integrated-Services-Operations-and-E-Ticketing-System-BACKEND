<?php

namespace App\Models;

use CodeIgniter\Model;

class TicketMaterialModel extends Model
{
    protected $table         = 'ticket_materials';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'ticket_id',
        'assignment_id',
        'material_name',
        'quantity',
        'unit_measurement',
        'unit_price',
        'total_price',
        'created_at',
    ];

    /**
     * Get all materials linked to a ticket directly or through assignments.
     */
    public function getByTicket(string $ticketId): array
    {
        $db = \Config\Database::connect();
        return $db->query("
            SELECT tm.*
            FROM ticket_materials tm
            LEFT JOIN ticket_assignments ta ON ta.id = tm.assignment_id
            WHERE tm.ticket_id = ? OR ta.ticket_id = ?
            ORDER BY tm.id ASC
        ", [$ticketId, $ticketId])->getResultArray();
    }

    /**
     * Get total calculated cost of materials for a ticket.
     */
    public function getTotalCostByTicket(string $ticketId): float
    {
        $materials = $this->getByTicket($ticketId);
        $total = 0.0;
        foreach ($materials as $item) {
            $total += (float) ($item['total_price'] ?? ((float) $item['quantity'] * (float) $item['unit_price']));
        }
        return $total;
    }
}

