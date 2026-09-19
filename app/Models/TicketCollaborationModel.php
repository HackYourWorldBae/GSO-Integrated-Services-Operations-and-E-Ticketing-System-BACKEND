<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * TicketCollaborationModel
 *
 * Manages cross-unit collaborations and joint task coordination.
 * When a ticket requires the specialized skills, tools, or manpower
 * of another unit (e.g. FGMU needs LEAU electricians, or LEAU needs FGMU carpentry),
 * a collaboration record tracks the invitation, acceptance, and shared personnel.
 */
class TicketCollaborationModel extends Model
{
    protected $table            = 'ticket_collaborations';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $allowedFields    = [
        'ticket_id',
        'requesting_unit_id',
        'collaborating_unit_id',
        'requested_by',
        'reason',
        'scope_of_work',
        'status',
        'response_notes',
        'responded_by',
        'responded_at',
        'completed_at',
    ];

    /**
     * Get all collaborations for a specific ticket with unit names and requester details.
     */
    public function getByTicket(string $ticketId): array
    {
        $db = \Config\Database::connect();

        return $db->table('ticket_collaborations tc')
            ->select('tc.*, 
                      req_u.name as requesting_unit_name, req_u.code as requesting_unit_code,
                      col_u.name as collaborating_unit_name, col_u.code as collaborating_unit_code,
                      req_usr.first_name as requester_first_name, req_usr.last_name as requester_last_name,
                      resp_usr.first_name as responder_first_name, resp_usr.last_name as responder_last_name')
            ->join('units req_u', 'req_u.id = tc.requesting_unit_id', 'left')
            ->join('units col_u', 'col_u.id = tc.collaborating_unit_id', 'left')
            ->join('users req_usr', 'req_usr.id = tc.requested_by', 'left')
            ->join('users resp_usr', 'resp_usr.id = tc.responded_by', 'left')
            ->where('tc.ticket_id', $ticketId)
            ->orderBy('tc.created_at', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * Check if a unit is an active collaborating unit for a ticket.
     */
    public function isUnitCollaborating(string $ticketId, int $unitId): bool
    {
        $count = $this->where('ticket_id', $ticketId)
                      ->where('collaborating_unit_id', $unitId)
                      ->whereIn('status', ['accepted', 'pending'])
                      ->countAllResults();

        return $count > 0;
    }

    /**
     * Get list of collaborations for a unit (either requesting or collaborating).
     */
    public function getUnitCollaborations(int $unitId, ?string $direction = 'all'): array
    {
        $db = \Config\Database::connect();
        $builder = $db->table('ticket_collaborations tc')
            ->select('tc.*, 
                      t.service_type, t.description as ticket_description, t.status as ticket_status, t.location, t.office_room,
                      req_u.name as requesting_unit_name, req_u.code as requesting_unit_code,
                      col_u.name as collaborating_unit_name, col_u.code as collaborating_unit_code,
                      req_usr.first_name as requester_first_name, req_usr.last_name as requester_last_name')
            ->join('tickets t', 't.id = tc.ticket_id', 'left')
            ->join('units req_u', 'req_u.id = tc.requesting_unit_id', 'left')
            ->join('units col_u', 'col_u.id = tc.collaborating_unit_id', 'left')
            ->join('users req_usr', 'req_usr.id = tc.requested_by', 'left');

        if ($direction === 'incoming') {
            $builder->where('tc.collaborating_unit_id', $unitId);
        } elseif ($direction === 'outgoing') {
            $builder->where('tc.requesting_unit_id', $unitId);
        } else {
            $builder->groupStart()
                    ->where('tc.collaborating_unit_id', $unitId)
                    ->orWhere('tc.requesting_unit_id', $unitId)
                    ->groupEnd();
        }

        return $builder->orderBy('tc.created_at', 'DESC')->get()->getResultArray();
    }
}
