<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * TicketModel
 *
 * Core ticket entity. All other unit-specific detail tables
 * reference this via ticket_id (FK).
 */
class TicketModel extends Model
{
    protected $table            = 'tickets';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false; // Formatted ticket IDs: FGMU-TIC-42-2026
    protected $returnType       = 'array';
    protected $useTimestamps    = false; // Managed manually for reviewed_at, completed_at
    protected $allowedFields    = [
        'id',
        'user_id',
        'unit_id',
        'service_type',
        'description',
        'status',
        'status_label',
        'decline_reason',
        'current_step',
        'location',
        'office_room',
        'is_archived',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'completed_at',
        'updated_at',
    ];

    // -------------------------------------------------------------------------
    // Query Helpers
    // -------------------------------------------------------------------------

    /**
     * Get all active (not archived) tickets for a specific user/requestor.
     */
    public function getActiveByUser(string $userId): array
    {
        return $this->where('user_id', $userId)
                    ->where('is_archived', 0)
                    ->orderBy('submitted_at', 'DESC')
                    ->findAll();
    }

    /**
     * Get all archived/completed tickets for a specific user.
     */
    public function getArchivedByUser(string $userId): array
    {
        return $this->where('user_id', $userId)
                    ->where('is_archived', 1)
                    ->orderBy('submitted_at', 'DESC')
                    ->findAll();
    }

    /**
     * Get the pending ticket queue for a specific unit (admin dashboard).
     */
    public function getPendingQueue(int $unitId): array
    {
        return $this->where('unit_id', $unitId)
                    ->where('status', 'pending')
                    ->where('is_archived', 0)
                    ->orderBy('submitted_at', 'ASC')
                    ->findAll();
    }

    /**
     * Get approved/scheduled tickets (dispatcher queue) for a unit.
     */
    public function getDispatchQueue(int $unitId): array
    {
        return $this->where('unit_id', $unitId)
                    ->whereIn('status', ['approved'])
                    ->where('is_archived', 0)
                    ->orderBy('submitted_at', 'ASC')
                    ->findAll();
    }

    /**
     * Get actively in-progress tickets for a unit.
     */
    public function getActiveTickets(int $unitId): array
    {
        return $this->where('unit_id', $unitId)
                    ->whereIn('status', ['processing'])
                    ->where('is_archived', 0)
                    ->orderBy('submitted_at', 'ASC')
                    ->findAll();
    }

    /**
     * Get completed/archived tickets for a unit (admin archive).
     */
    public function getArchivedByUnit(int $unitId, array $filters = []): array
    {
        $builder = $this->where('unit_id', $unitId)
                        ->where('is_archived', 1)
                        ->orderBy('completed_at', 'DESC');

        if (!empty($filters['search'])) {
            $builder->like('id', $filters['search']);
        }

        if (!empty($filters['status'])) {
            $builder->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $builder->where('submitted_at >=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $builder->where('submitted_at <=', $filters['date_to'] . ' 23:59:59');
        }

        return $builder->findAll();
    }

    /**
     * Generate the next sequential ticket ID for a given unit within the current year.
     * e.g., FGMU-TIC-43-2026
     */
    public function generateTicketId(string $unitCode, int $unitId): string
    {
        $year  = date('Y');
        $count = $this->where('unit_id', $unitId)
                      ->like('id', "-{$year}", 'after')
                      ->countAllResults() + 1;

        return strtoupper($unitCode) . '-TIC-' . $count . '-' . $year;
    }

    /**
     * Get a summary count of tickets by status for a unit (for dashboard stats).
     */
    public function getStatsByUnit(int $unitId): array
    {
        $db = \Config\Database::connect();

        return $db->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'pending'    THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'approved'   THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing,
                SUM(CASE WHEN status = 'resolved'   THEN 1 ELSE 0 END) AS resolved,
                SUM(CASE WHEN status = 'declined'   THEN 1 ELSE 0 END) AS declined,
                SUM(CASE WHEN is_archived = 1       THEN 1 ELSE 0 END) AS archived
            FROM tickets
            WHERE unit_id = ?
        ", [$unitId])->getRowArray() ?? [];
    }
}
