<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * UserModel
 *
 * Handles all authentication and profile queries for the unified `users` table.
 * Covers students, employees, admins, directors, and superadmins.
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
    protected $skipValidation   = true;

    protected $allowedFields = [
        'id',
        'first_name',
        'last_name',
        'email',
        'password_hash',
        'contact_number',
        'student_id_number',
        'student_type',
        'organization_name',
        'college',
        'role',
        'unit_id',
        'id_card_image',
        'id_selfie_image',
        'avatar_path',
        'status',
        'is_verified',
        'failed_login_attempts',
        'lockout_until',
    ];

    // -------------------------------------------------------------------------
    // Validation rules (explicitly validated in controllers)
    // -------------------------------------------------------------------------
    protected $validationRules = [
        'first_name'        => 'required|max_length[100]',
        'last_name'         => 'required|max_length[100]',
        'email'             => 'required|valid_email|max_length[255]|is_unique[users.email,id,{id}]',
        'password_hash'     => 'required|min_length[8]',
        'role'              => 'required|in_list[student,employee,admin,director,superadmin]',
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
     * Find a user by email, employee/student ID number, or contact number.
     * Supports multi-identifier login for all roles and offline/elderly users.
     */
    public function findUserByIdentifier(string $identifier): ?array
    {
        $clean = trim($identifier);
        return $this->groupStart()
                        ->where('email', $clean)
                        ->orWhere('student_id_number', $clean)
                        ->orWhere('contact_number', $clean)
                    ->groupEnd()
                    ->first();
    }

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

        // Explicitly cast is_verified to int (0 or 1) so JSON serialization is boolean-friendly
        $user['is_verified'] = (int) ($user['is_verified'] ?? 0);

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
        $builder = $this->select('users.id, users.first_name, users.last_name, users.email, users.contact_number, users.role, users.unit_id, users.student_id_number, users.student_type, users.organization_name, users.college, users.id_card_image, users.id_selfie_image, users.avatar_path, users.status, users.is_verified, users.failed_login_attempts, users.lockout_until, users.created_at, users.updated_at, units.name as unit_name, units.code as unit_code, (SELECT COUNT(*) FROM tickets WHERE tickets.user_id = users.id) AS request_count')
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

        $users = $builder->orderBy('users.created_at', 'DESC')
                         ->findAll($limit, $offset);

        $now = time();
        foreach ($users as &$u) {
            $u['is_verified'] = (int) ($u['is_verified'] ?? 0);
            $u['failed_login_attempts'] = (int) ($u['failed_login_attempts'] ?? 0);
            $u['request_count'] = (int) ($u['request_count'] ?? 0);
            $lockoutTimestamp = !empty($u['lockout_until']) ? strtotime($u['lockout_until']) : 0;
            $u['is_locked'] = ($lockoutTimestamp > $now);
            $u['lockout_remaining_seconds'] = $u['is_locked'] ? max(0, $lockoutTimestamp - $now) : 0;
        }
        unset($u);

        return $users;
    }

    /**
     * Check if a user is currently locked out from logging in.
     */
    public function isLockedOut(array $user): bool
    {
        if (empty($user['lockout_until'])) {
            return false;
        }

        $lockoutTime = strtotime($user['lockout_until']);
        return (time() < $lockoutTime);
    }

    /**
     * Get remaining lockout duration in seconds. Returns 0 if not locked out.
     */
    public function getRemainingLockoutSeconds(array $user): int
    {
        if (empty($user['lockout_until'])) {
            return 0;
        }

        $lockoutTime = strtotime($user['lockout_until']);
        $remaining = $lockoutTime - time();
        return max(0, $remaining);
    }

    /**
     * Record a failed login attempt for a user.
     * When attempts reach $maxAttempts (default 5), locks the account for $lockoutMinutes (default 15).
     *
     * @return array{ attempts: int, is_locked: bool, lockout_until: string|null, remaining_seconds: int, remaining_attempts: int }
     */
    public function recordFailedAttempt(string $userId, int $currentAttempts, int $maxAttempts = 5, int $lockoutMinutes = 15): array
    {
        $newAttempts = $currentAttempts + 1;

        if ($newAttempts >= $maxAttempts) {
            $lockoutUntil = date('Y-m-d H:i:s', strtotime("+{$lockoutMinutes} minutes"));
            $this->update($userId, [
                'failed_login_attempts' => $newAttempts,
                'lockout_until'         => $lockoutUntil,
            ]);

            return [
                'attempts'           => $newAttempts,
                'is_locked'          => true,
                'lockout_until'      => $lockoutUntil,
                'remaining_seconds'  => $lockoutMinutes * 60,
                'remaining_attempts' => 0,
            ];
        }

        $this->update($userId, [
            'failed_login_attempts' => $newAttempts,
            'lockout_until'         => null,
        ]);

        return [
            'attempts'           => $newAttempts,
            'is_locked'          => false,
            'lockout_until'      => null,
            'remaining_seconds'  => 0,
            'remaining_attempts' => max(0, $maxAttempts - $newAttempts),
        ];
    }

    /**
     * Reset lockout and failed login attempts for a user.
     */
    public function resetLockout(string $userId): bool
    {
        return $this->update($userId, [
            'failed_login_attempts' => 0,
            'lockout_until'         => null,
        ]);
    }

    /**
     * Superadmin manual unlock for an account.
     */
    public function unlockUser(string $userId): bool
    {
        return $this->resetLockout($userId);
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
        $roles = ['superadmin', 'admin', 'director', 'employee', 'student'];
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
