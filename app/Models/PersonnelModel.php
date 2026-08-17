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

        $personnel = $this->where('unit_id', $unitId)
                          ->orderBy('specialty', 'ASC')
                          ->orderBy('name', 'ASC')
                          ->findAll();

        if (empty($personnel)) {
            return [];
        }

        $personnelIds = array_column($personnel, 'id');
        
        $assignments = $db->table('ticket_assignments ta')
            ->select('ta.*, t.service_type, t.is_project, t.project_title, t.status as ticket_status')
            ->join('tickets t', 't.id = ta.ticket_id', 'left')
            ->whereIn('ta.personnel_id', $personnelIds)
            ->where('ta.completed_at IS NULL')
            ->orderBy('ta.assigned_at', 'ASC')
            ->get()->getResultArray();

        $assignmentMap = [];
        foreach ($assignments as $a) {
            $assignmentMap[$a['personnel_id']][] = $a;
        }

        foreach ($personnel as &$p) {
            $p['assignments'] = $assignmentMap[$p['id']] ?? [];
            
            // To maintain backward compatibility, extract current and next
            $p['assigned_ticket_id'] = $p['assignments'][0]['ticket_id'] ?? null;
            $p['is_project']         = (int) ($p['assignments'][0]['is_project'] ?? 0);
            $p['project_title']      = $p['assignments'][0]['project_title'] ?? null;
            $p['ticket_task']        = !empty($p['is_project']) 
                                        ? ($p['project_title'] ?: 'Office Project') 
                                        : (!empty($p['assignments'][0]['service_type']) ? $p['assignments'][0]['service_type'] : ($p['assignments'][0]['task_notes'] ?? null));
            $p['implementation_date'] = $p['assignments'][0]['implementation_date'] ?? null;
            $p['ticket_status']       = $p['assignments'][0]['ticket_status'] ?? null;
            $p['service_type']        = $p['assignments'][0]['service_type'] ?? null;
             
            $p['next_assignment_id'] = $p['assignments'][1]['ticket_id'] ?? null;
            $p['next_is_project']    = (int) ($p['assignments'][1]['is_project'] ?? 0);
            $p['next_ticket_task']   = !empty($p['next_is_project']) 
                                        ? ($p['assignments'][1]['project_title'] ?? 'Office Project') 
                                        : (!empty($p['assignments'][1]['service_type']) ? $p['assignments'][1]['service_type'] : ($p['assignments'][1]['task_notes'] ?? null));
            $p['next_implementation_date'] = $p['assignments'][1]['implementation_date'] ?? null;
        }

        return $personnel;
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
