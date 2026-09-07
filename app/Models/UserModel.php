<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * UserModel
 *
 * Handles all authentication and profile queries for the unified `users` table.
 * Covers students, employees, admins, dispatchers, directors, and workers.
 */
class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false; // UUID primary key
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    protected $allowedFields = [
        'id',
        'first_name',
        'last_name',
        'email',
        'password_hash',
        'contact_number',
        'role',
        'unit_id',
        'id_card_image',
        'avatar_path',
        'status',
        'is_verified',
    ];

    // -------------------------------------------------------------------------
    // Validation rules
    // -------------------------------------------------------------------------
    protected $validationRules = [
        'first_name'        => 'required|max_length[100]',
        'last_name'         => 'required|max_length[100]',
        'email'             => 'required|valid_email|max_length[255]|is_unique[users.email,id,{id}]',
        'password_hash'     => 'required|min_length[8]',
        'role'              => 'required|in_list[student,employee,admin,dispatcher,director,worker,superadmin]',
    ];

    protected $validationMessages = [
        'email' => [
            'is_unique' => 'This email address is already registered.',
        ],
    ];

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /**
     * Find a user by student ID number.
     * Used during the login flow when the identifier is 7 digits.
     */
    public function findByStudentId(string $studentId): ?array
    {
        return $this->where('student_id_number', $studentId)
                    ->where('status', 'Active')
                    ->first();
    }

    /**
     * Find a user by email address.
     * Used for employee/admin/staff login.
     */
    public function findByEmail(string $email): ?array
    {
        return $this->where('email', $email)
                    ->where('status', 'Active')
                    ->first();
    }

    /**
     * Return a safe user payload for API responses (no password).
     * Automatically enriches with dynamic RBAC permissions and avatar_url.
     */
    public function getSafeUser(string $userId): ?array
    {
        $user = $this->find($userId);

        if (!$user) {
            return null;
        }

        unset($user['password_hash']);

        // Attach avatar URL
        $user['avatar_url'] = !empty($user['avatar_path'])
            ? base_url('api/v1/auth/avatar/' . $user['id'])
            : null;

        // Attach dynamic RBAC permissions for the user's role
        $rolePermissionModel = new \App\Models\RolePermissionModel();
        $user['permissions'] = $rolePermissionModel->getPermissionsForRole($user['role']);

        return $user;
    }

    /**
     * Query users with filters, search, and pagination for Superadmin management.
     */
    public function getUsersList(?string $search = null, ?string $role = null, ?string $unitId = null, ?string $status = null, int $limit = 20, int $offset = 0): array
    {
        $builder = $this->select('users.id, users.first_name, users.last_name, users.email, users.contact_number, users.role, users.unit_id, users.student_id_number, users.avatar_path, users.status, users.is_verified, users.created_at, users.updated_at, units.name as unit_name, units.code as unit_code')
                        ->join('units', 'units.id = users.unit_id', 'left');

        if (!empty($search)) {
            $builder->groupStart()
                    ->like('users.first_name', $search)
                    ->orLike('users.last_name', $search)
                    ->orLike('users.email', $search)
                    ->orLike('users.student_id_number', $search)
                    ->groupEnd();
        }

        if (!empty($role) && $role !== 'all') {
            $builder->where('users.role', $role);
        }

        if (!empty($unitId) && $unitId !== 'all') {
            if ($unitId === 'none') {
                $builder->where('users.unit_id IS NULL', null, false);
            } else {
                $builder->where('users.unit_id', (int) $unitId);
            }
        }

        if (!empty($status) && $status !== 'all') {
            $builder->where('users.status', $status);
        }

        return $builder->orderBy('users.created_at', 'DESC')
                       ->findAll($limit, $offset);
    }

    /**
     * Count total users matching filters.
     */
    public function getUsersCount(?string $search = null, ?string $role = null, ?string $unitId = null, ?string $status = null): int
    {
        $builder = $this->select('users.id');

        if (!empty($search)) {
            $builder->groupStart()
                    ->like('users.first_name', $search)
                    ->orLike('users.last_name', $search)
                    ->orLike('users.email', $search)
                    ->orLike('users.student_id_number', $search)
                    ->groupEnd();
        }

        if (!empty($role) && $role !== 'all') {
            $builder->where('users.role', $role);
        }

        if (!empty($unitId) && $unitId !== 'all') {
            if ($unitId === 'none') {
                $builder->where('users.unit_id IS NULL', null, false);
            } else {
                $builder->where('users.unit_id', (int) $unitId);
            }
        }

        if (!empty($status) && $status !== 'all') {
            $builder->where('users.status', $status);
        }

        return $builder->countAllResults();
    }

    /**
     * Aggregate system-wide user counts for Superadmin dashboard.
     */
    public function getSystemUserStats(): array
    {
        $totalUsers = $this->countAllResults();
        $activeUsers = $this->where('status', 'Active')->countAllResults();
        $pendingUsers = $this->where('status', 'Pending')->countAllResults();
        $suspendedUsers = $this->where('status', 'Suspended')->countAllResults();

        // Role breakdown
        $roles = ['superadmin', 'admin', 'dispatcher', 'director', 'worker', 'employee', 'student'];
        $roleBreakdown = [];
        foreach ($roles as $r) {
            $roleBreakdown[$r] = $this->where('role', $r)->countAllResults();
        }

        return [
            'total_users'     => $totalUsers,
            'active_users'    => $activeUsers,
            'pending_users'   => $pendingUsers,
            'suspended_users' => $suspendedUsers,
            'by_role'         => $roleBreakdown,
        ];
    }
}
