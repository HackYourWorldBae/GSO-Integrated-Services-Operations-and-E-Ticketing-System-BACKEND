<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * TicketRequiredMaterialModel
 *
 * Pre-approval materials checklist added after ocular assessment/inspection
 * when GSO consumables stock is short, empty, or unavailable.
 */
class TicketRequiredMaterialModel extends Model
{
    protected $table            = 'ticket_required_materials';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;
    protected $allowedFields    = [
        'ticket_id',
        'catalog_material_id',
        'material_name',
        'quantity',
        'unit_measurement',
        'inventory_status',
        'gso_available_qty',
        'remarks',
        'is_requestor_provided',
        'provided_confirmed_at',
        'created_by',
        'created_at',
        'updated_at',
    ];

    /**
     * Get all required materials listed for a ticket, ordered sequentially.
     */
    public function getByTicket(string $ticketId): array
    {
        return $this->where('ticket_id', $ticketId)
            ->orderBy('id', 'ASC')
            ->findAll();
    }
}

