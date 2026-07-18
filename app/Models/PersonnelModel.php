<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * PersonnelModel
 *
 * Represents field workers (FGMU: plumbers, electricians, etc.),
 * LEAU groundskeepers/gardeners, and TASU professional drivers.
 */
class PersonnelModel extends Model
{
    protected $table         = 'personnel';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = false; // UUID primary key
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $allowedFields = [
        'id',
        'user_id',
        'unit_id',
        'name',
        'specialty',
        'status',
    ];

    /**
     * Get all personnel for a given unit, with their current active assignment.
     */
    public function getByUnit(int $unitId): array
    {
        $db = \Config\Database::connect();

        // Left join assignment to show what each worker is currently assigned to.
        return $db->query("
            SELECT
                p.*,
                ta.ticket_id       AS assigned_ticket_id,
                ta.task_notes      AS ticket_task,
                ta.implementation_date,
                t.service_type,
                t.status           AS ticket_status
            FROM personnel p
            LEFT JOIN ticket_assignments ta
                ON ta.personnel_id = p.id
                AND ta.completed_at IS NULL
            LEFT JOIN tickets t
                ON t.id = ta.ticket_id
            WHERE p.unit_id = ?
            ORDER BY p.specialty ASC, p.name ASC
        ", [$unitId])->getResultArray();
    }

    /**
     * Get all available workers in a unit (for dispatcher assignment dropdowns).
     */
    public function getAvailableByUnit(int $unitId): array
    {
        return $this->where('unit_id', $unitId)
                    ->whereIn('status', ['available'])
                    ->orderBy('specialty', 'ASC')
                    ->findAll();
    }
}
