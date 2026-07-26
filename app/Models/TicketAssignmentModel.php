<?php

namespace App\Models;

use CodeIgniter\Model;

class TicketAssignmentModel extends Model
{
    protected $table         = 'ticket_assignments';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'ticket_id',
        'personnel_id',
        'vehicle_id',
        'implementation_date',
        'dispatcher_notes',
        'task_notes',
        'assigned_at',
        'dispatched_at',
        'completed_at',
    ];

    /**
     * Get all active assignments for a specific ticket.
     */
    public function getByTicket(string $ticketId): array
    {
        return $this->where('ticket_id', $ticketId)
                    ->where('completed_at IS NULL')
                    ->findAll();
    }

    /**
     * Get all active assignments for a specific worker/driver.
     */
    public function getByPersonnel(string $personnelId): array
    {
        return $this->where('personnel_id', $personnelId)
                    ->where('completed_at IS NULL')
                    ->findAll();
    }

    /**
     * Get the active assignment for a worker including full ticket info.
     */
    public function getWorkerDashboard(string $personnelId): ?array
    {
        $db = \Config\Database::connect();

        return $db->query("
            SELECT
                ta.*,
                t.id            AS ticket_id,
                t.service_type,
                t.description,
                t.status        AS ticket_status,
                t.location,
                t.office_room,
                t.submitted_at
            FROM ticket_assignments ta
            JOIN tickets t ON t.id = ta.ticket_id
            WHERE ta.personnel_id = ?
              AND ta.completed_at IS NULL
            LIMIT 1
        ", [$personnelId])->getRowArray();
    }

    /**
     * Get ALL active assignments across all workers (for generic testing dashboard).
     */
    public function getAllActiveAssignments(): array
    {
        $db = \Config\Database::connect();

        return $db->query("
            SELECT
                ta.*,
                t.id            AS ticket_id,
                t.service_type,
                t.description,
                t.status        AS ticket_status,
                t.location,
                t.office_room,
                t.submitted_at,
                p.name          AS worker_name,
                p.status        AS worker_status
            FROM ticket_assignments ta
            JOIN tickets t ON t.id = ta.ticket_id
            JOIN personnel p ON p.id = ta.personnel_id
            WHERE ta.completed_at IS NULL
            ORDER BY ta.assigned_at DESC
        ")->getResultArray();
    }

    /**
     * Get completed (historical) assignments for a worker.
     */
    public function getWorkerHistory(string $personnelId): array
    {
        $db = \Config\Database::connect();

        return $db->query("
            SELECT
                ta.*,
                t.id            AS ticket_id,
                t.service_type,
                t.description,
                t.status        AS ticket_status,
                t.location,
                t.office_room,
                t.submitted_at,
                ta.completed_at
            FROM ticket_assignments ta
            JOIN tickets t ON t.id = ta.ticket_id
            WHERE ta.personnel_id = ?
              AND ta.completed_at IS NOT NULL
            ORDER BY ta.completed_at DESC
        ", [$personnelId])->getResultArray();
    }
}
