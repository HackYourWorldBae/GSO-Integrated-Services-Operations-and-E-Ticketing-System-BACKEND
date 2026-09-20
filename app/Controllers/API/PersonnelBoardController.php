<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\PersonnelModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PersonnelBoardController
 *
 * Bulletin board for field personnel (FGMU / LEAU).
 * Shared unit logins (fgmu-personnels@email.com, leau-personnels@email.com)
 * let any worker pick their name from the roster and view assigned works,
 * including teammates dispatched to the same tickets.
 *
 * Endpoints:
 *  GET /api/v1/personnel-board/roster?unit_id=   - Roster + active work counts for a unit
 *  GET /api/v1/personnel-board/works?personnel_id= - Active + recent works for one worker, with teammates
 */
class PersonnelBoardController extends BaseController
{
    private PersonnelModel $personnelModel;

    private const UNIT_MAP = [
        'FGMU' => 1,
        'LEAU' => 2,
        'SSU'  => 3,
    ];

    public function __construct()
    {
        $this->personnelModel = new PersonnelModel();
    }

    /**
     * Resolve the board unit for the current user.
     * Admins/employees are scoped to their own unit; director/superadmin
     * may pass ?unit_id= (defaults to their unit, else FGMU).
     */
    private function resolveBoardUnit(): ?int
    {
        $role = $this->currentUserRole();
        $own  = $this->currentUserUnitId();

        if (in_array($role, ['director', 'superadmin'], true)) {
            $requested = (int) ($this->request->getGet('unit_id') ?: 0);
            if ($requested > 0) {
                return $requested;
            }
            return $own ?: 1;
        }

        return $own;
    }

    /**
     * Get the unit roster with active work counts (bulletin name picker).
     * GET /api/v1/personnel-board/roster
     */
    public function roster(): ResponseInterface
    {
        $unitId = $this->resolveBoardUnit();
        if (!$unitId) {
            return $this->forbiddenResponse('Your account is not scoped to a field unit.');
        }

        $unitCode = array_search($unitId, self::UNIT_MAP) ?: 'FGMU';
        $roster   = $this->personnelModel->getByUnit($unitId);

        $personnel = array_map(function (array $p) {
            return [
                'id'               => $p['id'],
                'name'             => $p['name'],
                'specialty'        => $p['specialty'] ?? 'General',
                'status'           => $p['status'] ?? 'available',
                'assignment_count' => (int) ($p['assignment_count'] ?? 0),
                'assignments'      => array_map(function (array $a) {
                    return [
                        'ticket_id'           => $a['ticket_id'],
                        'task'                => $a['task'] ?? null,
                        'implementation_date' => $a['implementation_date'] ?? null,
                        'is_emergency'        => (int) ($a['is_emergency'] ?? 0),
                    ];
                }, $p['assignments'] ?? []),
            ];
        }, $roster);

        return $this->successResponse('Personnel board roster retrieved.', [
            'unit_id'   => $unitId,
            'unit_code' => $unitCode,
            'personnel' => $personnel,
            'count'     => count($personnel),
        ]);
    }

    /**
     * Get all works for one worker: active assignments (with ticket details
     * and teammates) plus recent completed history.
     * GET /api/v1/personnel-board/works?personnel_id=
     */
    public function works(): ResponseInterface
    {
        $unitId = $this->resolveBoardUnit();
        if (!$unitId) {
            return $this->forbiddenResponse('Your account is not scoped to a field unit.');
        }

        $personnelId = sanitize_string($this->request->getGet('personnel_id') ?? '');
        if ($personnelId === '') {
            return $this->errorResponse('personnel_id is required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $worker = $this->personnelModel->find($personnelId);
        if (!$worker) {
            return $this->notFoundResponse('Personnel member');
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['director', 'superadmin'], true) && (int) $worker['unit_id'] !== $unitId) {
            return $this->forbiddenResponse('This worker belongs to another unit.');
        }

        $db = \Config\Database::connect();

        // Active works with ticket + requester details
        $active = $db->query("
            SELECT ta.*, t.title, t.service_type, t.description,
                   t.status AS ticket_status, t.status_label, t.location, t.office_room,
                   t.is_emergency AS ticket_is_emergency, t.submitted_at,
                   TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS requester,
                   u.email AS requester_email, u.contact_number AS requester_contact
            FROM ticket_assignments ta
            JOIN tickets t ON t.id = ta.ticket_id
            LEFT JOIN users u ON u.id = t.user_id
            WHERE ta.personnel_id = ?
              AND ta.completed_at IS NULL
            ORDER BY ta.is_emergency DESC, ta.implementation_date ASC, ta.assigned_at ASC
        ", [$personnelId])->getResultArray();

        $ticketIds = array_values(array_unique(array_column($active, 'ticket_id')));

        // Teammates: every worker actively dispatched to the same tickets
        $matesByTicket = [];
        if (!empty($ticketIds)) {
            $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
            $mates = $db->query("
                SELECT ta.ticket_id, ta.personnel_id, ta.task_notes,
                       ta.implementation_date AS mate_impl_date, ta.is_emergency AS mate_is_emergency,
                       p.name AS worker_name, p.specialty AS worker_specialty, p.status AS worker_status
                FROM ticket_assignments ta
                JOIN personnel p ON p.id = ta.personnel_id
                WHERE ta.ticket_id IN ({$placeholders})
                  AND ta.completed_at IS NULL
                ORDER BY ta.assigned_at ASC
            ", $ticketIds)->getResultArray();

            foreach ($mates as $m) {
                $matesByTicket[$m['ticket_id']][] = [
                    'personnel_id'        => $m['personnel_id'],
                    'name'                => $m['worker_name'],
                    'specialty'           => $m['worker_specialty'] ?? 'General',
                    'status'              => $m['worker_status'] ?? 'available',
                    'task_notes'          => $m['task_notes'] ?? null,
                    'implementation_date' => $m['mate_impl_date'] ?? null,
                    'is_emergency'        => (int) ($m['mate_is_emergency'] ?? 0),
                    'is_self'             => (string) $m['personnel_id'] === (string) $personnelId,
                ];
            }
        }

        $works = [];
        foreach ($active as $a) {
            $requester = trim((string) ($a['requester'] ?? ''));
            $works[] = [
                'assignment_id'       => $a['id'],
                'ticket_id'           => $a['ticket_id'],
                'title'               => $a['title'] ?? 'Service Request',
                'service_type'        => $a['service_type'] ?? null,
                'description'         => $a['description'] ?? '',
                'ticket_status'       => $a['ticket_status'] ?? null,
                'status_label'        => $a['status_label'] ?? null,
                'location'            => $a['location'] ?? null,
                'office_room'         => $a['office_room'] ?? null,
                'is_emergency'        => !empty($a['is_emergency']) || !empty($a['ticket_is_emergency']),
                'task_notes'          => $a['task_notes'] ?? null,
                'dispatcher_notes'    => $a['dispatcher_notes'] ?? null,
                'implementation_date' => $a['implementation_date'] ?? null,
                'working_days'        => isset($a['working_days']) ? (int) $a['working_days'] : null,
                'assigned_at'         => $a['assigned_at'] ?? null,
                'dispatched_at'       => $a['dispatched_at'] ?? null,
                'assignment_status'   => $a['status'] ?? 'active',
                'requester'           => $requester !== '' ? $requester : 'End User',
                'requester_email'     => $a['requester_email'] ?? null,
                'requester_contact'   => $a['requester_contact'] ?? null,
                'submitted_at'        => $a['submitted_at'] ?? null,
                'teammates'           => $matesByTicket[$a['ticket_id']] ?? [],
            ];
        }

        // Recent completed history
        $history = $db->query("
            SELECT ta.id AS assignment_id, ta.ticket_id, ta.implementation_date,
                   ta.working_days, ta.completed_at, t.title, t.service_type,
                   t.status AS ticket_status, t.location
            FROM ticket_assignments ta
            JOIN tickets t ON t.id = ta.ticket_id
            WHERE ta.personnel_id = ?
              AND ta.completed_at IS NOT NULL
            ORDER BY ta.completed_at DESC
            LIMIT 50
        ", [$personnelId])->getResultArray();

        return $this->successResponse('Personnel works retrieved.', [
            'worker' => [
                'id'        => $worker['id'],
                'name'      => $worker['name'],
                'specialty' => $worker['specialty'] ?? 'General',
                'status'    => $worker['status'] ?? 'available',
                'unit_id'   => (int) $worker['unit_id'],
            ],
            'active'  => $works,
            'history' => $history,
            'count'   => count($works),
        ]);
    }
}
