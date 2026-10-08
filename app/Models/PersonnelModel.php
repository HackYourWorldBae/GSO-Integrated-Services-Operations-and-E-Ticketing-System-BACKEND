<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * PersonnelModel
 *
 * Represents field workers (FGMU: plumbers, electricians, etc.),
 * and LEAU groundskeepers/gardeners.
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
        'updated_at',
    ];

    protected $validationRules = [];

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

        // Auto-heal unlinked personnel records if a matching worker user account exists
        foreach ($personnel as &$p) {
            if (empty($p['user_id'])) {
                $pName = trim((string)($p['name'] ?? ''));
                if (!empty($pName)) {
                    $matchedUser = $db->table('users')
                        ->groupStart()
                            ->where('role', 'worker')
                            ->orWhere('role', 'personnel')
                            ->orWhere('role', 'staff')
                        ->groupEnd()
                        ->groupStart()
                            ->where('unit_id', $unitId)
                            ->orWhere('unit_id IS NULL')
                        ->groupEnd()
                        ->groupStart()
                            ->where("TRIM(LOWER(CONCAT(first_name, ' ', last_name))) = " . $db->escape(strtolower($pName)), null, false)
                            ->orWhere("REPLACE(LOWER(CONCAT(first_name, last_name)), ' ', '') = REPLACE(LOWER(" . $db->escape($pName) . "), ' ', '')", null, false)
                        ->groupEnd()
                        ->get()->getRowArray();

                    if ($matchedUser) {
                        try {
                            $db->table('personnel')->where('id', $p['id'])->update([
                                'user_id'    => $matchedUser['id'],
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]);
                            $p['user_id'] = $matchedUser['id'];
                        } catch (\Throwable $ignored) {
                        }
                    }
                }
            }
        }
        unset($p);

        $personnelIds = array_column($personnel, 'id');
        $userIds = array_values(array_filter(array_column($personnel, 'user_id')));
        
        $userMap = [];
        if (!empty($userIds)) {
            $users = $db->table('users')
                ->select('id, email, contact_number, status as user_status')
                ->whereIn('id', $userIds)
                ->get()->getResultArray();
            foreach ($users as $u) {
                $userMap[$u['id']] = $u;
            }
        }

        $assignments = $db->table('ticket_assignments ta')
            ->select('ta.*, t.service_type, t.is_project, t.project_title, t.status as ticket_status, t.eodb_tier, t.target_completion_date')
            ->join('tickets t', 't.id = ta.ticket_id', 'left')
            ->whereIn('ta.personnel_id', $personnelIds)
            ->where('ta.completed_at IS NULL')
            ->orderBy('ta.is_emergency', 'DESC')
            ->orderBy('ta.queue_order', 'ASC')
            ->orderBy('ta.assigned_at', 'ASC')
            ->get()->getResultArray();

        $assignmentMap = [];
        foreach ($assignments as $a) {
            $assignmentMap[$a['personnel_id']][] = $a;
        }

        foreach ($personnel as &$p) {
            $rawAssignments = $assignmentMap[$p['id']] ?? [];
            $formattedAssignments = [];
            foreach ($rawAssignments as $idx => $a) {
                $taskName = !empty($a['is_project'])
                    ? ($a['project_title'] ?: 'Office Project')
                    : (!empty($a['service_type']) ? $a['service_type'] : ($a['task_notes'] ?? 'Assigned Work'));

                $formattedAssignments[] = [
                    'id'                  => $a['id'],
                    'ticket_id'           => $a['ticket_id'],
                    'service_type'        => $a['service_type'] ?? null,
                    'is_project'          => (int) ($a['is_project'] ?? 0),
                    'project_title'       => $a['project_title'] ?? null,
                    'task'                => $taskName,
                    'implementation_date' => $a['implementation_date'] ?? null,
                    'ticket_status'       => $a['ticket_status'] ?? null,
                    'is_emergency'        => (int) ($a['is_emergency'] ?? 0),
                    'queue_order'         => (int) ($a['queue_order'] ?? ($idx + 1)),
                    'status'              => $a['status'] ?? 'active',
                    'eodb_tier'           => $a['eodb_tier'] ?? null,
                    'target_completion_date' => $a['target_completion_date'] ?? null,
                ];
            }

            $p['assignments']      = $formattedAssignments;
            $p['assignment_count'] = count($formattedAssignments);

            // Portal device account linkage metadata
            $p['has_account']  = !empty($p['user_id']);
            $p['user_email']   = $userMap[$p['user_id']]['email'] ?? null;
            $p['user_contact'] = $userMap[$p['user_id']]['contact_number'] ?? null;

            // Backward compatibility fields for current/next slots
            $p['assigned_ticket_id'] = $formattedAssignments[0]['ticket_id'] ?? null;
            $p['is_project']         = $formattedAssignments[0]['is_project'] ?? 0;
            $p['project_title']      = $formattedAssignments[0]['project_title'] ?? null;
            $p['ticket_task']        = $formattedAssignments[0]['task'] ?? null;
            $p['implementation_date'] = $formattedAssignments[0]['implementation_date'] ?? null;
            $p['ticket_status']       = $formattedAssignments[0]['ticket_status'] ?? null;
            $p['service_type']        = $formattedAssignments[0]['service_type'] ?? null;
            $p['is_emergency']        = $formattedAssignments[0]['is_emergency'] ?? 0;

            $p['next_assignment_id'] = $formattedAssignments[1]['ticket_id'] ?? null;
            $p['next_is_project']    = $formattedAssignments[1]['is_project'] ?? 0;
            $p['next_ticket_task']   = $formattedAssignments[1]['task'] ?? null;
            $p['next_implementation_date'] = $formattedAssignments[1]['implementation_date'] ?? null;

            // Full backlog beyond first active job
            $p['backlog'] = array_slice($formattedAssignments, 1);
        }
        unset($p);

        return $personnel;
    }

    /**
     * Find personnel record linked to a given user ID.
     */
    public function findByUserId(string $userId): ?array
    {
        return $this->where('user_id', $userId)->first();
    }

    /**
     * Get all available workers in a unit (for admin assignment dropdowns).
     * Excludes only staff who are currently on leave or inactive/retired.
     */
    public function getAvailableByUnit(int $unitId): array
    {
        return $this->where('unit_id', $unitId)
                    ->whereNotIn('status', ['on_leave', 'inactive', 'retired'])
                    ->orderBy('specialty', 'ASC')
                    ->findAll();
    }
}
