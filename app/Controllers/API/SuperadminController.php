<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Models\TicketModel;
use App\Models\UnitModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * SuperadminController
 *
 * Master administrative control for university user accounts and overall system governance.
 *
 * Endpoints:
 *  GET    /api/v1/superadmin/stats              - Global KPIs, account breakdowns, unit health
 *  GET    /api/v1/superadmin/users              - Filterable, searchable, paginated user directory
 *  POST   /api/v1/superadmin/users              - Provision new user account
 *  GET    /api/v1/superadmin/users/(:segment)   - Single user account details
 *  PUT    /api/v1/superadmin/users/(:segment)   - Update account details/role/status/password
 *  DELETE /api/v1/superadmin/users/(:segment)   - Deactivate or delete user account
 *  GET    /api/v1/superadmin/audit-logs         - System-wide security and action log explorer
 */
class SuperadminController extends BaseController
{
    private UserModel $userModel;
    private TicketModel $ticketModel;

    public function __construct()
    {
        $this->userModel   = new UserModel();
        $this->ticketModel = new TicketModel();
    }

    /**
     * System-wide statistics for the Superadmin Overview dashboard.
     */
    public function stats(): ResponseInterface
    {
        $userStats = $this->userModel->getSystemUserStats();

        // System ticket metrics
        $db = Database::connect();
        $totalTickets     = $db->table('tickets')->countAllResults();
        $pendingTickets   = $db->table('tickets')->where('status', 'pending')->countAllResults();
        $approvedTickets  = $db->table('tickets')->where('status', 'approved')->countAllResults();
        $ongoingTickets   = $db->table('tickets')->whereIn('status', ['ongoing', 'processing'])->countAllResults();
        $completedTickets = $db->table('tickets')->whereIn('status', ['closed', 'resolved', 'completed'])->countAllResults();
        $cancelledTickets = $db->table('tickets')->where('status', 'cancelled')->countAllResults();
        $declinedTickets  = $db->table('tickets')->where('status', 'declined')->countAllResults();

        // Unit-level distribution
        $unitMap = ['FGMU' => 1, 'LEAU' => 2, 'SSU' => 3];
        $unitBreakdown = [];
        foreach ($unitMap as $code => $id) {
            $unitBreakdown[$code] = [
                'total'     => $db->table('tickets')->where('unit_id', $id)->countAllResults(),
                'active'    => $db->table('tickets')->where('unit_id', $id)->whereIn('status', ['pending', 'approved', 'ongoing', 'processing'])->countAllResults(),
                'completed' => $db->table('tickets')->where('unit_id', $id)->whereIn('status', ['closed', 'resolved', 'completed'])->countAllResults(),
                'declined'  => $db->table('tickets')->where('unit_id', $id)->where('status', 'declined')->countAllResults(),
            ];
        }

        return $this->successResponse('System statistics retrieved successfully.', [
            'users' => $userStats,
            'tickets' => [
                'total'     => $totalTickets,
                'pending'   => $pendingTickets,
                'approved'  => $approvedTickets,
                'ongoing'   => $ongoingTickets,
                'completed' => $completedTickets,
                'cancelled' => $cancelledTickets,
                'declined'  => $declinedTickets,
                'by_unit'   => $unitBreakdown,
            ]
        ]);
    }

    /**
     * Paginated user directory with search and faceted filters.
     */
    public function users(): ResponseInterface
    {
        $search = $this->request->getGet('search');
        $role   = $this->request->getGet('role');
        $unitId = $this->request->getGet('unit_id');
        $status = $this->request->getGet('status');

        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(5, (int) ($this->request->getGet('per_page') ?? 15)));
        $offset  = ($page - 1) * $perPage;

        $users = $this->userModel->getUsersList($search, $role, $unitId, $status, $perPage, $offset);
        $total = $this->userModel->getUsersCount($search, $role, $unitId, $status);

        return $this->successResponse('Users retrieved successfully.', [
            'users'        => $users,
            'pagination'   => [
                'total'        => $total,
                'page'         => $page,
                'per_page'     => $perPage,
                'total_pages'  => (int) ceil($total / $perPage),
            ]
        ]);
    }

    /**
     * Show single user details.
     */
    public function showUser(string $id): ResponseInterface
    {
        $user = $this->userModel->select('users.id, users.first_name, users.last_name, users.email, users.contact_number, users.role, users.unit_id, users.student_id_number, users.status, users.is_verified, users.created_at, users.updated_at, units.name as unit_name, units.code as unit_code')
                                ->join('units', 'units.id = users.unit_id', 'left')
                                ->where('users.id', $id)
                                ->first();

        if (!$user) {
            return $this->notFoundResponse('User account not found.');
        }

        return $this->successResponse('User details retrieved successfully.', $user);
    }

    /**
     * Provision a new user account.
     */
    public function createUser(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        $rules = [
            'first_name'     => 'required|max_length[100]',
            'last_name'      => 'required|max_length[100]',
            'email'          => 'required|valid_email|is_unique[users.email]',
            'password'       => 'required|min_length[6]',
            'role'           => 'required|in_list[student,employee,admin,dispatcher,director,worker,superadmin]',
            'status'         => 'permit_empty|in_list[Active,Pending,Rejected,Suspended]',
            'unit_id'        => 'permit_empty',
            'contact_number' => 'permit_empty|max_length[30]',
        ];

        if (!$this->validateData($body, $rules)) {
            return $this->errorResponse(
                'Validation failed. Please check form fields.',
                $this->validator->getErrors(),
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $userId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $unitId = !empty($body['unit_id']) ? (int) $body['unit_id'] : null;

        $insertData = [
            'id'             => $userId,
            'first_name'     => trim($body['first_name']),
            'last_name'      => trim($body['last_name']),
            'email'          => strtolower(trim($body['email'])),
            'password_hash'  => password_hash($body['password'], PASSWORD_DEFAULT),
            'role'           => $body['role'],
            'unit_id'        => $unitId,
            'contact_number' => $body['contact_number'] ?? null,
            'status'         => $body['status'] ?? 'Active',
            'is_verified'    => 1,
        ];

        if ($this->userModel->insert($insertData)) {
            $createdUser = $this->userModel->getSafeUser($userId);
            return $this->successResponse('User created successfully.', $createdUser, ResponseInterface::HTTP_CREATED);
        }

        return $this->errorResponse('Failed to create user account.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Update an existing user account.
     */
    public function updateUser(string $id): ResponseInterface
    {
        $existing = $this->userModel->find($id);
        if (!$existing) {
            return $this->notFoundResponse('User account not found.');
        }

        $body = $this->request->getJSON(true) ?? [];

        $rules = [
            'first_name'     => 'permit_empty|max_length[100]',
            'last_name'      => 'permit_empty|max_length[100]',
            'email'          => "permit_empty|valid_email|is_unique[users.email,id,{$id}]",
            'role'           => 'permit_empty|in_list[student,employee,admin,dispatcher,director,worker,superadmin]',
            'status'         => 'permit_empty|in_list[Active,Pending,Rejected,Suspended]',
            'contact_number' => 'permit_empty|max_length[30]',
            'unit_id'        => 'permit_empty',
            'password'       => 'permit_empty|min_length[6]',
        ];

        if (!$this->validateData($body, $rules)) {
            return $this->errorResponse(
                'Validation failed. Please check form fields.',
                $this->validator->getErrors(),
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Prevent self-demotion
        $currentUserId = $this->currentUserId();
        if ((string) $id === (string) $currentUserId && isset($body['role']) && $body['role'] !== 'superadmin') {
            return $this->errorResponse('You cannot demote your own Superadmin account.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        $updateData = [];
        if (isset($body['first_name']))     $updateData['first_name']     = trim($body['first_name']);
        if (isset($body['last_name']))      $updateData['last_name']      = trim($body['last_name']);
        if (isset($body['email']))          $updateData['email']          = strtolower(trim($body['email']));
        if (isset($body['role']))           $updateData['role']           = $body['role'];
        if (isset($body['status']))         $updateData['status']         = $body['status'];
        if (isset($body['contact_number'])) $updateData['contact_number'] = $body['contact_number'];
        if (array_key_exists('unit_id', $body)) {
            $updateData['unit_id'] = !empty($body['unit_id']) ? (int) $body['unit_id'] : null;
        }

        if (!empty($body['password'])) {
            $updateData['password_hash'] = password_hash($body['password'], PASSWORD_DEFAULT);
        }

        if (empty($updateData)) {
            return $this->errorResponse('No valid fields provided to update.');
        }

        if ($this->userModel->update($id, $updateData)) {
            $safeUser = $this->userModel->getSafeUser($id);
            return $this->successResponse('User account updated successfully.', $safeUser);
        }

        return $this->errorResponse('Failed to update user account.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Deactivate or delete user account safely.
     */
    public function deleteUser(string $id): ResponseInterface
    {
        $existing = $this->userModel->find($id);
        if (!$existing) {
            return $this->notFoundResponse('User account not found.');
        }

        $currentUserId = $this->currentUserId();
        if ((string) $id === (string) $currentUserId) {
            return $this->errorResponse('Cannot delete your own active Superadmin account.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        // Check if user has active tickets
        $hasActiveTickets = $this->ticketModel->where('user_id', $id)
                                             ->whereIn('status', ['pending', 'approved', 'ongoing'])
                                             ->countAllResults();

        if ($hasActiveTickets > 0) {
            // Soft-disable instead of hard delete to preserve integrity
            $this->userModel->update($id, ['status' => 'Suspended']);
            return $this->successResponse('User account has active service tickets and was suspended instead of permanently deleted to preserve audit trails.');
        }

        $this->userModel->delete($id);
        return $this->successResponse('User account deleted successfully.');
    }

    /**
     * Explore system-wide audit logs.
     */
    public function auditLogs(): ResponseInterface
    {
        $search = $this->request->getGet('search');
        $limit  = min(100, max(5, (int) ($this->request->getGet('limit') ?? 25)));
        $offset = max(0, (int) ($this->request->getGet('offset') ?? 0));

        $db = Database::connect();
        $builder = $db->table('ticket_logs')
                      ->select('ticket_logs.*, users.first_name, users.last_name, users.email, users.role as user_role, tickets.status as ticket_status, tickets.college_building, tickets.location')
                      ->join('users', 'users.id = ticket_logs.user_id', 'left')
                      ->join('tickets', 'tickets.id = ticket_logs.ticket_id', 'left');

        if (!empty($search)) {
            $builder->groupStart()
                    ->like('ticket_logs.ticket_id', $search)
                    ->orLike('ticket_logs.action', $search)
                    ->orLike('ticket_logs.details', $search)
                    ->orLike('users.first_name', $search)
                    ->orLike('users.last_name', $search)
                    ->orLike('users.email', $search)
                    ->groupEnd();
        }

        $logs = $builder->orderBy('ticket_logs.created_at', 'DESC')
                        ->limit($limit, $offset)
                        ->get()
                        ->getResultArray();

        $countBuilder = $db->table('ticket_logs');
        if (!empty($search)) {
            $countBuilder->groupStart()
                         ->like('ticket_logs.ticket_id', $search)
                         ->orLike('ticket_logs.action', $search)
                         ->orLike('ticket_logs.details', $search)
                         ->groupEnd();
        }
        $total = $countBuilder->countAllResults();

        return $this->successResponse('Audit logs retrieved successfully.', [
            'logs'  => $logs,
            'total' => $total,
        ]);
    }
}
