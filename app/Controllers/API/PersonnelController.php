<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\PersonnelModel;
use App\Models\PersonnelCategoryModel;
use App\Models\TicketLogModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PersonnelController
 *
 * Manages unit field staff (workers, personnel, etc.).
 *
 * Endpoints:
 *  GET   /api/v1/personnel/:unitCode              - Full roster for a unit (admin view)
 *  GET   /api/v1/personnel/:unitCode/available    - Available workers only (for admin assignment dropdowns)
 *  PATCH /api/v1/personnel/:id/status             - Toggle worker availability / leave status
 *  POST  /api/v1/personnel                        - Create a new personnel record
 *  PUT   /api/v1/personnel/:id                    - Update personnel info
 *  DELETE /api/v1/personnel/:id                   - Remove a personnel record
 */
class PersonnelController extends BaseController
{
    private PersonnelModel $personnelModel;
    private PersonnelCategoryModel $categoryModel;
    private TicketLogModel $logModel;

    // Unit code to ID map (mirrors DB seeds)
    private const UNIT_MAP = ['FGMU' => 1, 'LEAU' => 2, 'SSU' => 3];

    public function __construct()
    {
        $this->personnelModel = new PersonnelModel();
        $this->categoryModel  = new PersonnelCategoryModel();
        $this->logModel       = new TicketLogModel();
    }

    /**
     * Get the full roster for a unit, grouped by specialty.
     * Includes current active assignment info.
     */
    public function byUnit(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;
        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $personnel = $this->personnelModel->getByUnit($unitId);

        // Group by specialty for display
        $grouped = [];
        foreach ($personnel as $person) {
            $specialty = !empty(trim($person['specialty'] ?? '')) ? trim($person['specialty']) : 'General';
            $grouped[$specialty][] = $person;
        }

        return $this->successResponse('Personnel roster retrieved.', [
            'unit'      => strtoupper($unitCode),
            'personnel' => $personnel,
            'grouped'   => $grouped,
        ]);
    }

    /**
     * Get only available workers for a unit (used in admin assignment dropdowns).
     */
    public function available(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;
        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $workers = $this->personnelModel->getAvailableByUnit($unitId);

        return $this->successResponse('Available personnel retrieved.', ['personnel' => $workers]);
    }

    /**
     * Update a worker's availability status.
     *
     * Body: { status: 'available' | 'on_leave' }
     * Note: 'working' / 'on_trip' status is set automatically by DispatchController.
     */
    public function updateStatus(string $personnelId): ResponseInterface
    {
        $worker = $this->personnelModel->find($personnelId);
        if (!$worker) {
            return $this->notFoundResponse('Personnel');
        }

        if ($forbidden = $this->assertUnitAccess((int) $worker['unit_id'])) {
            return $forbidden;
        }

        $body   = $this->request->getJSON(true) ?? [];
        $status = sanitize_string($body['status'] ?? '');

        $allowedStatuses = ['available', 'on_leave', 'inactive', 'retired'];
        if (!in_array($status, $allowedStatuses, true)) {
            return $this->errorResponse("Status must be one of: " . implode(', ', $allowedStatuses));
        }

        $assignmentModel   = new \App\Models\TicketAssignmentModel();
        $ticketModel       = new \App\Models\TicketModel();
        $notificationModel = new \App\Models\NotificationModel();

        // Check for active assignments
        $activeAssignments = $assignmentModel->getByPersonnel($personnelId);

        if (in_array($status, ['inactive', 'retired'], true) && !empty($activeAssignments)) {
            // Automatically unassign all active and queued tasks and return to the unit queue
            foreach ($activeAssignments as $assignment) {
                $assignmentModel->update($assignment['id'], [
                    'completed_at'       => date('Y-m-d H:i:s'),
                    'reassignment_reason'=> "Staff member marked as {$status}",
                ]);

                $ticketModel->update($assignment['ticket_id'], [
                    'status'       => 'approved',
                    'status_label' => 'Approved (Pending Re-dispatch)',
                    'current_step' => 2,
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);

                $this->logModel->logAction(
                    $assignment['ticket_id'],
                    $this->currentUserId(),
                    'Worker Unassigned (Staff Retired/Inactive)',
                    "Staff {$worker['name']} was marked as {$status}. Task returned to unit dispatch queue for reassignment."
                );

                $ticket = $ticketModel->find($assignment['ticket_id']);
                if ($ticket) {
                    $notificationModel->createNotification(
                        $ticket['user_id'],
                        'info',
                        "Ticket #{$assignment['ticket_id']} Schedule Update",
                        "Staff assignment updated. Ticket queued for dispatch."
                    );
                }
            }
        } elseif ($status === 'on_leave' && !empty($activeAssignments)) {
            $leaveAction = sanitize_string($body['leave_action'] ?? '');
            $leaveReason = sanitize_string($body['leave_reason'] ?? 'Sick / Medical Leave');

            if (!in_array($leaveAction, ['reassign', 'extend'], true)) {
                return $this->errorResponse(
                    "Worker has active assignment(s). Please specify 'leave_action' ('reassign' or 'extend').",
                    ['active_assignments' => $activeAssignments],
                    ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            if ($leaveAction === 'reassign') {
                $targetWorkerId = sanitize_string($body['reassign_to_personnel_id'] ?? '');
                if (empty($targetWorkerId)) {
                    return $this->errorResponse('Target personnel ID is required for reassignment.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
                }

                $targetWorker = $this->personnelModel->find($targetWorkerId);
                if (!$targetWorker) {
                    return $this->notFoundResponse('Replacement Personnel');
                }

                if ((int) $targetWorker['unit_id'] !== (int) $worker['unit_id']) {
                    return $this->errorResponse("Replacement worker must belong to the same unit.");
                }

                if ($targetWorker['status'] === 'on_leave') {
                    return $this->errorResponse("Selected replacement worker is currently on leave.");
                }

                // Reassign all active assignments to target worker
                $hasRunningTicket = false;
                foreach ($activeAssignments as $assignment) {
                    $assignmentModel->update($assignment['id'], [
                        'personnel_id'       => $targetWorkerId,
                        'is_reassigned'      => 1,
                        'reassigned_from_id' => $personnelId,
                        'reassignment_reason'=> $leaveReason,
                    ]);

                    $ticket = $ticketModel->find($assignment['ticket_id']);
                    if ($ticket && (int) $ticket['current_step'] === 5) {
                        $hasRunningTicket = true;
                    }

                    $this->logModel->logAction(
                        $assignment['ticket_id'],
                        $this->currentUserId(),
                        'Worker Reassigned',
                        "Reassigned from {$worker['name']} (on leave: {$leaveReason}) to {$targetWorker['name']}."
                    );

                    if ($ticket) {
                        $notificationModel->createNotification(
                            $ticket['user_id'],
                            'info',
                            "Ticket #{$assignment['ticket_id']} Personnel Updated",
                            "Staff assignment updated to {$targetWorker['name']}."
                        );
                    }
                }

                // Set new worker status to working if any of the tickets was actively running
                if ($hasRunningTicket) {
                    $this->personnelModel->update($targetWorkerId, [
                        'status'     => 'working',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }

            } elseif ($leaveAction === 'extend') {
                $extensionDays = (int) ($body['extension_days'] ?? 1);
                $extendedDate  = sanitize_string($body['extended_completion_date'] ?? '');

                foreach ($activeAssignments as $assignment) {
                    $ticket = $ticketModel->find($assignment['ticket_id']);
                    if (!$ticket) {
                        continue;
                    }

                    $baseDate = !empty($ticket['extended_completion_date'])
                        ? new \DateTime($ticket['extended_completion_date'])
                        : (!empty($assignment['implementation_date'])
                            ? new \DateTime($assignment['implementation_date'])
                            : new \DateTime());

                    $newTargetDate = !empty($extendedDate)
                        ? $extendedDate
                        : \App\Libraries\WorkCalendar::addWorkingDays($baseDate, max(1, $extensionDays))->format('Y-m-d');

                    $totalDays = (int) ($ticket['extension_days'] ?? 0) + max(1, $extensionDays);

                    $ticketModel->update($ticket['id'], [
                        'extension_days'           => $totalDays,
                        'extended_completion_date' => $newTargetDate,
                        'extension_reason'         => "Assigned worker {$worker['name']} on leave ({$leaveReason})",
                        'updated_at'               => date('Y-m-d H:i:s'),
                    ]);

                    $assignmentModel->update($assignment['id'], [
                        'implementation_date' => $newTargetDate,
                    ]);

                    $this->logModel->logAction(
                        $ticket['id'],
                        $this->currentUserId(),
                        'Timeline Extended (Staff Leave)',
                        "Target completion adjusted to {$newTargetDate} (+{$extensionDays} working days) because {$worker['name']} is on leave: {$leaveReason}."
                    );

                    $notificationModel->createNotification(
                        $ticket['user_id'],
                        'warning',
                        "Ticket #{$ticket['id']} Extended",
                        "Schedule adjusted to {$newTargetDate} due to staff leave."
                    );
                }
            }
        }

        $this->personnelModel->update($personnelId, [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->successResponse("Personnel status updated to '{$status}'.", [
            'personnel_id' => $personnelId,
            'status'       => $status,
        ]);
    }

    /**
     * Create a new personnel record.
     *
     * Body: { name, specialty, unit_id, status?, user_id? }
     */
    public function create(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        $name      = sanitize_string($body['name'] ?? '');
        $specialty = sanitize_string($body['specialty'] ?? '');
        $unitId    = (int) ($body['unit_id'] ?? 0);

        // If 'name' is not provided directly, compose it from name components
        if (empty($name) && !empty($body['first_name']) && !empty($body['last_name'])) {
            $parts = [sanitize_string($body['first_name'])];
            if (!empty($body['middle_initial'])) {
                $parts[] = rtrim(sanitize_string($body['middle_initial']), '.') . '.';
            }
            $parts[] = sanitize_string($body['last_name']);
            if (!empty($body['name_extension'])) {
                $parts[] = sanitize_string($body['name_extension']);
            }
            $name = implode(' ', $parts);
        }

        if (empty($name) || empty($specialty) || !$unitId) {
            return $this->errorResponse('name, specialty, and unit_id are required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($forbidden = $this->assertUnitAccess($unitId)) {
            return $forbidden;
        }

        $createAccount = !empty($body['create_account']) || !empty($body['has_device']);
        $userId        = null;

        if ($createAccount) {
            $email         = trim(sanitize_string($body['email'] ?? ''));
            $password      = (string) ($body['password'] ?? '');
            $contactNumber = trim(sanitize_string($body['contact_number'] ?? ''));

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->errorResponse('A valid email address is required to create a personnel portal account.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (mb_strlen($password) < 8) {
                return $this->errorResponse('Password must be at least 8 characters long.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }

            $userModel = new \App\Models\UserModel();
            if ($userModel->where('email', $email)->first()) {
                return $this->errorResponse('This email address is already registered to another account.', [], ResponseInterface::HTTP_CONFLICT);
            }

            $firstName = !empty($body['first_name']) ? sanitize_string($body['first_name']) : (explode(' ', $name)[0] ?? 'Staff');
            $lastNameParts = !empty($body['last_name']) ? [sanitize_string($body['last_name'])] : array_slice(explode(' ', $name), 1);
            if (!empty($body['name_extension'])) {
                $lastNameParts[] = sanitize_string($body['name_extension']);
            }
            $lastName = !empty($lastNameParts) ? implode(' ', $lastNameParts) : 'Member';

            $userId = generate_uuid();
            $userData = [
                'id'                          => $userId,
                'first_name'                  => $firstName,
                'last_name'                   => $lastName,
                'email'                       => $email,
                'password_hash'               => password_hash($password, PASSWORD_DEFAULT),
                'contact_number'              => !empty($contactNumber) ? $contactNumber : null,
                'role'                        => 'worker',
                'unit_id'                     => $unitId,
                'status'                      => 'Active',
                'is_verified'                 => 1, // Directly provisioned by Unit Head
                'email_notifications_enabled' => 1,
            ];

            if (!$userModel->skipValidation(true)->insert($userData)) {
                return $this->errorResponse('Failed to provision personnel login account.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        $personnelId = generate_uuid();
        $insertPersonnel = [
            'id'        => $personnelId,
            'unit_id'   => $unitId,
            'name'      => $name,
            'specialty' => $specialty,
            'status'    => 'available',
        ];

        if ($userId) {
            $insertPersonnel['user_id'] = $userId;
        }

        $this->personnelModel->insert($insertPersonnel);

        return $this->successResponse('Personnel created successfully.', [
            'personnel_id' => $personnelId,
            'user_id'      => $userId,
            'has_account'  => (bool) $userId,
        ], ResponseInterface::HTTP_CREATED);
    }

    /**
     * Provision a user login account for an existing personnel member who now has an accessible device.
     * POST /api/v1/personnel/:id/create-account
     */
    public function createAccount(string $personnelId): ResponseInterface
    {
        $worker = $this->personnelModel->find($personnelId);
        if (!$worker) {
            return $this->notFoundResponse('Personnel');
        }

        if ($forbidden = $this->assertUnitAccess((int) $worker['unit_id'])) {
            return $forbidden;
        }

        if (!empty($worker['user_id'])) {
            return $this->errorResponse('This personnel already has an active portal account linked.', [], ResponseInterface::HTTP_CONFLICT);
        }

        $body          = $this->request->getJSON(true) ?? [];
        $email         = trim(sanitize_string($body['email'] ?? ''));
        $password      = (string) ($body['password'] ?? '');
        $contactNumber = trim(sanitize_string($body['contact_number'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->errorResponse('A valid email address is required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (mb_strlen($password) < 8) {
            return $this->errorResponse('Password must be at least 8 characters long.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userModel = new \App\Models\UserModel();
        if ($userModel->where('email', $email)->first()) {
            return $this->errorResponse('This email address is already registered to another account.', [], ResponseInterface::HTTP_CONFLICT);
        }

        $nameParts = explode(' ', trim($worker['name']));
        $firstName = $nameParts[0] ?? 'Staff';
        $lastName  = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : 'Personnel';

        $userId = generate_uuid();
        $userData = [
            'id'                          => $userId,
            'first_name'                  => $firstName,
            'last_name'                   => $lastName,
            'email'                       => $email,
            'password_hash'               => password_hash($password, PASSWORD_DEFAULT),
            'contact_number'              => !empty($contactNumber) ? $contactNumber : null,
            'role'                        => 'worker',
            'unit_id'                     => (int) $worker['unit_id'],
            'status'                      => 'Active',
            'is_verified'                 => 1,
            'email_notifications_enabled' => 1,
        ];

        if (!$userModel->skipValidation(true)->insert($userData)) {
            return $this->errorResponse('Failed to create account in database.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->personnelModel->update($personnelId, [
            'user_id'    => $userId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->successResponse('Personnel portal account created successfully.', [
            'personnel_id' => $personnelId,
            'user_id'      => $userId,
            'email'        => $email,
        ], ResponseInterface::HTTP_CREATED);
    }

    /**
     * Personnel Dashboard: Returns only tickets assigned to this personnel,
     * along with complete ticket info and the full work crew / team for that job.
     * GET /api/v1/personnel/my-dashboard
     * GET /api/v1/worker/dashboard
     */
    public function myDashboard(): ResponseInterface
    {
        $currentUserId = $this->currentUserId();
        $currentRole   = $this->currentUserRole();
        $db            = \Config\Database::connect();

        $personnel = null;

        // If worker role, strictly load their own assigned personnel profile
        if ($currentRole === 'worker') {
            $personnel = $this->personnelModel->findByUserId($currentUserId);
            if (!$personnel) {
                // Fallback attempt: find by matching user name
                $user = (new \App\Models\UserModel())->find($currentUserId);
                if ($user) {
                    $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    $personnel = $this->personnelModel->where('name', $fullName)->first();
                }
            }
        } else {
            // Admin, Director, or Superadmin previewing a personnel's dashboard
            $targetPersonnelId = sanitize_string($this->request->getGet('personnel_id') ?? '');
            if (!empty($targetPersonnelId)) {
                $personnel = $this->personnelModel->find($targetPersonnelId);
            } else {
                // If no specific worker requested, find worker linked to user or first available
                $personnel = $this->personnelModel->findByUserId($currentUserId);
                if (!$personnel) {
                    $unitId = $this->currentUserUnitId();
                    if ($unitId) {
                        $personnel = $this->personnelModel->where('unit_id', $unitId)->first();
                    } else {
                        $personnel = $this->personnelModel->first();
                    }
                }
            }
        }

        if (!$personnel) {
            return $this->successResponse('No personnel profile linked to this account.', [
                'personnel'       => null,
                'assignments'     => [],
                'active_count'    => 0,
                'completed_count' => 0,
                'total_count'     => 0,
            ]);
        }

        // Fetch all assignments for this personnel
        $rawAssignments = $db->table('ticket_assignments ta')
            ->select('
                ta.id as assignment_id,
                ta.ticket_id,
                ta.implementation_date,
                ta.working_days,
                ta.task_notes,
                ta.dispatcher_notes,
                ta.is_emergency,
                ta.status as assignment_status,
                ta.assigned_at,
                ta.dispatched_at,
                ta.completed_at,
                t.service_type,
                t.description,
                t.status as ticket_status,
                t.status_label,
                t.location,
                t.office_room,
                t.submitted_at,
                t.target_completion_date,
                t.extended_completion_date,
                t.is_project,
                t.project_title,
                t.eodb_tier,
                u.name as unit_name,
                u.code as unit_code,
                req.first_name as requestor_first_name,
                req.last_name as requestor_last_name,
                req.contact_number as requestor_contact
            ')
            ->join('tickets t', 't.id = ta.ticket_id', 'inner')
            ->join('units u', 'u.id = t.unit_id', 'left')
            ->join('users req', 'req.id = t.user_id', 'left')
            ->where('ta.personnel_id', $personnel['id'])
            ->orderBy('ta.completed_at IS NOT NULL', 'ASC')
            ->orderBy('ta.is_emergency', 'DESC')
            ->orderBy('ta.assigned_at', 'DESC')
            ->get()->getResultArray();

        $ticketIds = array_values(array_unique(array_filter(array_column($rawAssignments, 'ticket_id'))));
        $teamMembersByTicket = [];

        if (!empty($ticketIds)) {
            $allTeamRows = $db->table('ticket_assignments ta')
                ->select('
                    ta.ticket_id,
                    ta.id as assignment_id,
                    ta.task_notes,
                    ta.assigned_at,
                    ta.completed_at,
                    p.id as personnel_id,
                    p.name as personnel_name,
                    p.specialty,
                    p.status as personnel_status,
                    u.code as unit_code
                ')
                ->join('personnel p', 'p.id = ta.personnel_id', 'inner')
                ->join('units u', 'u.id = p.unit_id', 'left')
                ->whereIn('ta.ticket_id', $ticketIds)
                ->orderBy('p.id = ' . $db->escape($personnel['id']), 'DESC') // current worker first
                ->orderBy('p.name', 'ASC')
                ->get()->getResultArray();

            foreach ($allTeamRows as $row) {
                $row['is_you'] = ($row['personnel_id'] === $personnel['id']);
                $teamMembersByTicket[$row['ticket_id']][] = $row;
            }
        }

        $formattedAssignments = [];
        $activeCount    = 0;
        $completedCount = 0;

        foreach ($rawAssignments as $a) {
            $isCompleted = !empty($a['completed_at']);
            if ($isCompleted) {
                $completedCount++;
            } else {
                $activeCount++;
            }

            $reqParts = array_filter([$a['requestor_first_name'] ?? '', $a['requestor_last_name'] ?? '']);
            $requestorName = !empty($reqParts) ? implode(' ', $reqParts) : 'BSU Client';

            $team = $teamMembersByTicket[$a['ticket_id']] ?? [];

            $formattedAssignments[] = [
                'assignment_id'            => (int) $a['assignment_id'],
                'ticket_id'                => $a['ticket_id'],
                'service_type'             => $a['service_type'] ?: 'Facilities Maintenance',
                'description'              => $a['description'] ?: 'No additional description provided.',
                'ticket_status'            => $a['ticket_status'],
                'status_label'             => $a['status_label'] ?: ucfirst((string)$a['ticket_status']),
                'assignment_status'        => $a['assignment_status'],
                'location'                 => $a['location'] ?: 'BSU Campus',
                'office_room'              => $a['office_room'] ?: 'General Area',
                'implementation_date'      => $a['implementation_date'],
                'target_completion_date'   => $a['target_completion_date'],
                'extended_completion_date' => $a['extended_completion_date'],
                'working_days'             => (int) ($a['working_days'] ?? 1),
                'is_emergency'             => (int) ($a['is_emergency'] ?? 0),
                'is_project'               => (int) ($a['is_project'] ?? 0),
                'project_title'            => $a['project_title'],
                'eodb_tier'                => $a['eodb_tier'],
                'dispatcher_notes'         => $a['dispatcher_notes'],
                'task_notes'               => $a['task_notes'],
                'assigned_at'              => $a['assigned_at'],
                'dispatched_at'            => $a['dispatched_at'],
                'completed_at'             => $a['completed_at'],
                'unit_name'                => $a['unit_name'] ?: 'General Services Office',
                'unit_code'                => $a['unit_code'] ?: 'GSO',
                'requestor_name'           => $requestorName,
                'requestor_contact'        => $a['requestor_contact'],
                'team'                     => $team,
                'team_count'               => count($team),
            ];
        }

        return $this->successResponse('Personnel dashboard data retrieved.', [
            'personnel'       => $personnel,
            'assignments'     => $formattedAssignments,
            'active_count'    => $activeCount,
            'completed_count' => $completedCount,
            'total_count'     => count($formattedAssignments),
        ]);
    }

    /**
     * Update an existing personnel record's name, specialty, or status.
     */
    public function update(string $personnelId): ResponseInterface
    {
        $worker = $this->personnelModel->find($personnelId);
        if (!$worker) {
            return $this->notFoundResponse('Personnel');
        }

        if ($forbidden = $this->assertUnitAccess((int) $worker['unit_id'])) {
            return $forbidden;
        }

        $body       = $this->request->getJSON(true) ?? [];
        $updateData = [];

        if (isset($body['name'])) {
            $updateData['name'] = sanitize_string($body['name']);
        }
        if (isset($body['specialty'])) {
            $updateData['specialty'] = sanitize_string($body['specialty']);
        }

        if (!empty($updateData)) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            $this->personnelModel->update($personnelId, $updateData);
        }

        return $this->successResponse('Personnel updated.', ['personnel_id' => $personnelId]);
    }

    /**
     * Delete a personnel record.
     * Only allowed if the worker has no active assignments.
     */
    public function delete(string $personnelId): ResponseInterface
    {
        $worker = $this->personnelModel->find($personnelId);
        if (!$worker) {
            return $this->notFoundResponse('Personnel');
        }

        if ($forbidden = $this->assertUnitAccess((int) $worker['unit_id'])) {
            return $forbidden;
        }

        if (in_array($worker['status'], ['working', 'on_trip'], true)) {
            return $this->errorResponse('Cannot delete a worker who is currently active on a job.');
        }

        $this->personnelModel->delete($personnelId);

        return $this->successResponse('Personnel record deleted.', ['personnel_id' => $personnelId]);
    }

    // =========================================================================
    // Category Management
    // =========================================================================

    /**
     * List all personnel categories for a unit.
     * GET /api/v1/personnel/categories/:unitCode
     */
    public function categories(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;
        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $rawCategories = $this->categoryModel->getByUnit($unitId);
        $categories = array_map(function ($cat) {
            $servList = [];
            if (!empty($cat['supported_services'])) {
                $decoded = is_array($cat['supported_services']) ? $cat['supported_services'] : json_decode($cat['supported_services'], true);
                if (is_array($decoded)) {
                    $servList = array_values(array_filter(array_map('trim', $decoded)));
                }
            }
            $cat['services'] = $servList;
            $cat['supported_services'] = $servList;
            return $cat;
        }, $rawCategories);

        return $this->successResponse('Categories retrieved.', [
            'unit'       => strtoupper($unitCode),
            'categories' => $categories,
        ]);
    }

    /**
     * Create a new personnel category for a unit.
     * POST /api/v1/personnel/categories
     * Body: { unit_code: 'FGMU', name: 'Welder', services: ['Welding & Tinsmith Works'] }
     */
    public function createCategory(): ResponseInterface
    {
        $body     = $this->request->getJSON(true) ?? [];
        $unitCode = strtoupper(sanitize_string($body['unit_code'] ?? ''));
        $name     = sanitize_string($body['name'] ?? '');

        $rawServices = $body['services'] ?? $body['supported_services'] ?? [];
        $services = [];
        if (is_array($rawServices)) {
            $services = array_values(array_unique(array_filter(array_map('sanitize_string', $rawServices))));
        }

        $unitId = self::UNIT_MAP[$unitCode] ?? null;
        if (!$unitId) {
            return $this->errorResponse('Valid unit_code is required (FGMU, LEAU, SSU).');
        }

        if ($forbidden = $this->assertUnitAccess($unitId)) {
            return $forbidden;
        }

        if (empty($name)) {
            return $this->errorResponse('Category name is required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Check for duplicates within this unit
        $existing = $this->categoryModel
            ->where('unit_id', $unitId)
            ->where('name', $name)
            ->first();

        if ($existing) {
            return $this->errorResponse('A category with this name already exists for this unit.', [], ResponseInterface::HTTP_CONFLICT);
        }

        $id = $this->categoryModel->insert([
            'unit_id'            => $unitId,
            'name'               => $name,
            'is_system'          => 0,
            'supported_services' => !empty($services) ? json_encode($services) : null,
        ], true);

        return $this->successResponse('Category created.', [
            'category' => [
                'id'                 => $id,
                'unit_id'            => $unitId,
                'name'               => $name,
                'is_system'          => 0,
                'services'           => $services,
                'supported_services' => $services,
            ],
        ], ResponseInterface::HTTP_CREATED);
    }
    /**
     * Update a personnel category name and/or supported services and cascade to personnel.
     * PATCH /api/v1/personnel/categories/:id
     * Body: { name: 'New Name', services: ['Service 1', 'Service 2'] }
     */
    public function updateCategory(string $categoryId): ResponseInterface
    {
        $category = $this->categoryModel->find((int) $categoryId);
        if (!$category) {
            return $this->notFoundResponse('Category');
        }

        if ($forbidden = $this->assertUnitAccess((int) $category['unit_id'])) {
            return $forbidden;
        }

        $body        = $this->request->getJSON(true) ?? [];
        $newName     = isset($body['name']) ? sanitize_string($body['name']) : null;
        $hasServices = array_key_exists('services', $body) || array_key_exists('supported_services', $body);
        $rawServices = $body['services'] ?? $body['supported_services'] ?? [];

        $updateData = [];

        if ($newName !== null) {
            if (empty($newName)) {
                return $this->errorResponse('Category name cannot be empty.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Check for duplicates
            $existing = $this->categoryModel
                ->where('unit_id', $category['unit_id'])
                ->where('name', $newName)
                ->where('id !=', $categoryId)
                ->first();

            if ($existing) {
                return $this->errorResponse('A category with this name already exists for this unit.', [], ResponseInterface::HTTP_CONFLICT);
            }

            $updateData['name'] = $newName;
        }

        if ($hasServices && is_array($rawServices)) {
            $services = array_values(array_unique(array_filter(array_map('sanitize_string', $rawServices))));
            $updateData['supported_services'] = !empty($services) ? json_encode($services) : null;
        }

        if (empty($updateData)) {
            return $this->errorResponse('No valid fields provided to update.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->categoryModel->update((int) $categoryId, $updateData);

        // Cascade update to personnel if name was changed
        if (isset($updateData['name']) && $updateData['name'] !== $category['name']) {
            $db = \Config\Database::connect();
            $db->table('personnel')
                ->where('unit_id', $category['unit_id'])
                ->where('specialty', $category['name'])
                ->update(['specialty' => $updateData['name']]);
        }

        $fresh = $this->categoryModel->find((int) $categoryId);
        $decodedServices = [];
        if (!empty($fresh['supported_services'])) {
            $d = is_array($fresh['supported_services']) ? $fresh['supported_services'] : json_decode($fresh['supported_services'], true);
            if (is_array($d)) {
                $decodedServices = array_values(array_filter(array_map('trim', $d)));
            }
        }
        $fresh['services'] = $decodedServices;
        $fresh['supported_services'] = $decodedServices;

        return $this->successResponse('Category updated.', [
            'category_id' => (int) $categoryId,
            'category'    => $fresh,
        ]);
    }
    /**
     * Delete a personnel category.
     * DELETE /api/v1/personnel/categories/:id
     * Blocked if: (a) category is a system/seeded category, (b) category is in use by personnel.
     */
    public function deleteCategory(string $categoryId): ResponseInterface
    {
        $category = $this->categoryModel->find((int) $categoryId);
        if (!$category) {
            return $this->notFoundResponse('Category');
        }

        if ($forbidden = $this->assertUnitAccess((int) $category['unit_id'])) {
            return $forbidden;
        }

        if ($this->categoryModel->isInUse((int) $categoryId)) {
            return $this->errorResponse(
                'Cannot delete a category that is currently assigned to personnel. Reassign or remove those personnel first.',
                [],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->categoryModel->delete((int) $categoryId);

        return $this->successResponse('Category deleted.', ['category_id' => (int) $categoryId]);
    }
}
