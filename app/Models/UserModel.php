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
        'employee_type',
        'organization_name',
        'college',
        'role',
        'unit_id',
        'id_card_image',
        'id_selfie_image',
        'avatar_path',
        'status',
        'is_verified',
        'email_notifications_enabled',
        'failed_login_attempts',
        'lockout_until',
        'last_login_at',
    ];

    // -------------------------------------------------------------------------
    // Validation rules (explicitly validated in controllers)
    // -------------------------------------------------------------------------
    protected $validationRules = [
        'first_name'        => 'required|max_length[100]',
        'last_name'         => 'required|max_length[100]',
        'email'             => 'required|valid_email|max_length[255]|is_unique[users.email,id,{id}]',
        'password_hash'     => 'required|min_length[8]',
        'role'              => 'required|in_list[student,employee,admin,staff,director,superadmin,worker]',
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
        $user['email_notifications_enabled'] = isset($user['email_notifications_enabled'])
            ? (int) $user['email_notifications_enabled']
            : 1;

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
     * Cache for dynamic database schema capability checks.
     */
    protected static ?array $schemaCapabilities = null;

    /**
     * Inspect database schema dynamically to avoid fatal errors when tables/columns
     * vary across development, staging, or production hosting environments.
     */
    protected function getSchemaCapabilities(): array
    {
        if (self::$schemaCapabilities !== null) {
            return self::$schemaCapabilities;
        }

        try {
            $hasLastLoginAt = $this->db->fieldExists('last_login_at', 'users');
        } catch (\Throwable $e) {
            $hasLastLoginAt = false;
        }

        try {
            $hasUserSessions = $this->db->tableExists('user_sessions');
            $userSessionActivityCol = null;
            if ($hasUserSessions) {
                if ($this->db->fieldExists('last_activity', 'user_sessions')) {
                    $userSessionActivityCol = 'last_activity';
                } elseif ($this->db->fieldExists('last_active', 'user_sessions')) {
                    $userSessionActivityCol = 'last_active';
                }
            }
        } catch (\Throwable $e) {
            $hasUserSessions = false;
            $userSessionActivityCol = null;
        }

        try {
            $hasActivityLogs = $this->db->tableExists('account_activity_logs');
        } catch (\Throwable $e) {
            $hasActivityLogs = false;
        }

        try {
            $hasTickets = $this->db->tableExists('tickets');
        } catch (\Throwable $e) {
            $hasTickets = false;
        }

        try {
            $hasUnits = $this->db->tableExists('units');
        } catch (\Throwable $e) {
            $hasUnits = false;
        }

        try {
            $hasPersonnel = $this->db->tableExists('personnel');
            $hasPersonnelUserId = $hasPersonnel && $this->db->fieldExists('user_id', 'personnel');
            $hasPersonnelSpecialty = $hasPersonnel && $this->db->fieldExists('specialty', 'personnel');
        } catch (\Throwable $e) {
            $hasPersonnel = false;
            $hasPersonnelUserId = false;
            $hasPersonnelSpecialty = false;
        }

        self::$schemaCapabilities = [
            'has_last_login_at'      => $hasLastLoginAt,
            'has_user_sessions'      => $hasUserSessions,
            'session_activity_col'   => $userSessionActivityCol,
            'has_activity_logs'      => $hasActivityLogs,
            'has_tickets'            => $hasTickets,
            'has_units'              => $hasUnits,
            'has_personnel'          => $hasPersonnel,
            'has_personnel_user_id'  => $hasPersonnelUserId,
            'has_personnel_specialty'=> $hasPersonnelSpecialty,
        ];

        return self::$schemaCapabilities;
    }

    /**
     * Query users with filters, search, and pagination for Superadmin management.
     * Enriched with real-time session tracking and 6-month inactivity indicators.
     */
    public function getUsersList(?string $search = null, ?string $role = null, ?string $unitId = null, ?string $status = null, int $limit = 20, int $offset = 0): array
    {
        try {
            $caps = $this->getSchemaCapabilities();

            $selects = [
                'users.id',
                'users.first_name',
                'users.last_name',
                'users.email',
                'users.contact_number',
                'users.role',
                'users.unit_id',
                'users.student_id_number',
                'users.student_type',
                'users.employee_type',
                'users.organization_name',
                'users.college',
                'users.id_card_image',
                'users.id_selfie_image',
                'users.avatar_path',
                'users.status',
                'users.is_verified',
                'users.failed_login_attempts',
                'users.lockout_until',
                'users.created_at',
                'users.updated_at',
            ];

            if ($caps['has_last_login_at']) {
                $selects[] = 'users.last_login_at';
            } else {
                $selects[] = 'NULL AS last_login_at';
            }

            if ($caps['has_units']) {
                $selects[] = 'MAX(units.name) AS unit_name';
                $selects[] = 'MAX(units.code) AS unit_code';
            } else {
                $selects[] = 'NULL AS unit_name';
                $selects[] = 'NULL AS unit_code';
            }

            if ($caps['has_personnel'] && $caps['has_personnel_specialty']) {
                $selects[] = 'MAX(personnel.specialty) AS specialty';
            } else {
                $selects[] = 'NULL AS specialty';
            }

            if ($caps['has_tickets']) {
                $selects[] = '(SELECT COUNT(*) FROM tickets WHERE tickets.user_id = users.id) AS request_count';
                $selects[] = '(SELECT MAX(created_at) FROM tickets WHERE tickets.user_id = users.id) AS last_request_at';
            } else {
                $selects[] = '0 AS request_count';
                $selects[] = 'NULL AS last_request_at';
            }

            if ($caps['has_activity_logs']) {
                $selects[] = "(SELECT MAX(created_at) FROM account_activity_logs WHERE (actor_id = users.id OR target_user_id = users.id) AND event_type = 'AUTH_LOGIN_SUCCESS') AS log_last_login_at";
            } else {
                $selects[] = 'NULL AS log_last_login_at';
            }

            if ($caps['has_user_sessions'] && $caps['session_activity_col']) {
                $actCol = $caps['session_activity_col'];
                $selects[] = "MAX(user_sessions.{$actCol}) AS session_last_activity";
                $selects[] = 'MAX(user_sessions.created_at) AS session_created_at';
                $selects[] = 'MAX(user_sessions.ip_address) AS session_ip_address';
                $selects[] = 'MAX(user_sessions.user_agent) AS session_user_agent';
            } else {
                $selects[] = 'NULL AS session_last_activity';
                $selects[] = 'NULL AS session_created_at';
                $selects[] = 'NULL AS session_ip_address';
                $selects[] = 'NULL AS session_user_agent';
            }

            $builder = $this->select(implode(', ', $selects));

            if ($caps['has_units']) {
                $builder->join('units', 'units.id = users.unit_id', 'left');
            }

            if ($caps['has_personnel']) {
                $personnelJoin = $caps['has_personnel_user_id']
                    ? "personnel.user_id = users.id OR (users.role IN ('worker', 'personnel') AND (personnel.name = CONCAT(TRIM(users.first_name), ' ', TRIM(users.last_name)) OR personnel.name LIKE CONCAT('%', TRIM(users.last_name), '%')))"
                    : "(users.role IN ('worker', 'personnel') AND (personnel.name = CONCAT(TRIM(users.first_name), ' ', TRIM(users.last_name)) OR personnel.name LIKE CONCAT('%', TRIM(users.last_name), '%')))";
                $builder->join('personnel', $personnelJoin, 'left');
            }

            if ($caps['has_user_sessions']) {
                $builder->join('user_sessions', 'user_sessions.user_id = users.id', 'left');
            }

            $builder->groupBy('users.id');

            if (!empty($search)) {
                $builder->groupStart()
                        ->like('users.first_name', $search)
                        ->orLike('users.last_name', $search)
                        ->orLike('users.email', $search)
                        ->orLike('users.student_id_number', $search);
                if ($caps['has_personnel'] && $caps['has_personnel_specialty']) {
                    $builder->orLike('personnel.specialty', $search);
                }
                $builder->groupEnd();
            }

            if (!empty($role) && $role !== 'all') {
                if ($role === 'personnel' || $role === 'worker') {
                    $builder->groupStart()
                            ->where('users.role', 'worker')
                            ->orWhere('users.role', 'personnel')
                            ->groupEnd();
                } else {
                    $builder->where('users.role', $role);
                }
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
        } catch (\Throwable $e) {
            log_message('error', '[UserModel::getUsersList] Primary query failed: ' . $e->getMessage() . '. Running safe fallback query.');
            $users = $this->getUsersListSafeFallback($search, $role, $unitId, $status, $limit, $offset);
        }

        return $this->enrichUsersWithPresenceAndInactivity($users);
    }

    /**
     * Resilient fallback query that operates purely on the users table,
     * ensuring that superadmin user listings can NEVER crash with 500
     * even under non-standard MySQL SQL modes or partial database schema states.
     */
    protected function getUsersListSafeFallback(?string $search = null, ?string $role = null, ?string $unitId = null, ?string $status = null, int $limit = 20, int $offset = 0): array
    {
        try {
            $builder = $this->builder();
            $builder->select('users.*');

            if (!empty($search)) {
                $builder->groupStart()
                        ->like('users.first_name', $search)
                        ->orLike('users.last_name', $search)
                        ->orLike('users.email', $search)
                        ->orLike('users.student_id_number', $search)
                        ->groupEnd();
            }

            if (!empty($role) && $role !== 'all') {
                if ($role === 'personnel' || $role === 'worker') {
                    $builder->whereIn('users.role', ['worker', 'personnel']);
                } else {
                    $builder->where('users.role', $role);
                }
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
                             ->get($limit, $offset)
                             ->getResultArray();

            // Safely hydrate unit info if units table exists
            try {
                if ($this->db->tableExists('units') && !empty($users)) {
                    $unitIds = array_filter(array_column($users, 'unit_id'));
                    if (!empty($unitIds)) {
                        $units = $this->db->table('units')
                                          ->whereIn('id', array_unique($unitIds))
                                          ->get()
                                          ->getResultArray();
                        $unitMap = [];
                        foreach ($units as $u) {
                            $unitMap[$u['id']] = $u;
                        }
                        foreach ($users as &$usr) {
                            if (!empty($usr['unit_id']) && isset($unitMap[$usr['unit_id']])) {
                                $usr['unit_name'] = $unitMap[$usr['unit_id']]['name'] ?? null;
                                $usr['unit_code'] = $unitMap[$usr['unit_id']]['code'] ?? null;
                            } else {
                                $usr['unit_name'] = null;
                                $usr['unit_code'] = null;
                            }
                        }
                        unset($usr);
                    }
                }
            } catch (\Throwable $ignored) {
            }

            return $users;
        } catch (\Throwable $e) {
            log_message('critical', '[UserModel::getUsersListSafeFallback] Critical query failure: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Enrich raw user rows with lockout, session presence, and 6-month inactivity indicators.
     */
    protected function enrichUsersWithPresenceAndInactivity(array $users): array
    {
        $now = time();
        $sixMonthsAgoTime = strtotime('-6 months');

        foreach ($users as &$u) {
            $u['is_verified'] = (int) ($u['is_verified'] ?? 0);
            $u['failed_login_attempts'] = (int) ($u['failed_login_attempts'] ?? 0);
            $u['request_count'] = (int) ($u['request_count'] ?? 0);
            $lockoutTimestamp = !empty($u['lockout_until']) ? strtotime($u['lockout_until']) : 0;
            $u['is_locked'] = ($lockoutTimestamp > $now);
            $u['lockout_remaining_seconds'] = $u['is_locked'] ? max(0, $lockoutTimestamp - $now) : 0;

            // Resolved last login timestamp (from users table, activity logs, or session)
            $lastLoginAt    = $u['last_login_at'] ?? null;
            $logLastLogin   = $u['log_last_login_at'] ?? null;
            $sessionLastAct = $u['session_last_activity'] ?? null;
            $resolvedLogin  = $lastLoginAt ?: ($logLastLogin ?: $sessionLastAct);
            $u['resolved_last_login_at'] = $resolvedLogin;

            // Session tracking: check if user is currently logged in and actively using system
            $hasActiveSession = !empty($sessionLastAct);
            $lastActivityTime = $hasActiveSession ? strtotime($sessionLastAct) : 0;
            $secondsSinceActivity = $hasActiveSession ? ($now - $lastActivityTime) : null;

            if ($hasActiveSession && $secondsSinceActivity !== null && $secondsSinceActivity <= 300) {
                // Active within 5 minutes -> online / using the system
                $u['session_status'] = 'online';
                $u['is_online'] = true;
                $u['session_label'] = 'Active Now';
            } elseif ($hasActiveSession && $secondsSinceActivity !== null && $secondsSinceActivity <= 1800) {
                // Active within 30 minutes -> idle
                $u['session_status'] = 'idle';
                $u['is_online'] = true;
                $u['session_label'] = 'Idle (' . max(1, round($secondsSinceActivity / 60)) . 'm ago)';
            } elseif ($hasActiveSession && $secondsSinceActivity !== null && $secondsSinceActivity <= 7200) {
                // Active session within 2 hours
                $u['session_status'] = 'recent';
                $u['is_online'] = false;
                $u['session_label'] = 'Recent (' . max(1, round($secondsSinceActivity / 3600)) . 'h ago)';
            } else {
                $u['session_status'] = 'offline';
                $u['is_online'] = false;
                $u['session_label'] = 'Offline';
            }
            $u['seconds_since_activity'] = $secondsSinceActivity;

            // Inactive calculation: "those who have not requested or logged in for 6 months"
            $createdTime   = !empty($u['created_at']) ? strtotime($u['created_at']) : $now;
            $loginTime     = !empty($resolvedLogin) ? strtotime($resolvedLogin) : 0;
            $lastRequestAt = $u['last_request_at'] ?? null;
            $requestTime   = !empty($lastRequestAt) ? strtotime($lastRequestAt) : 0;

            $hasRecentLogin   = ($loginTime >= $sixMonthsAgoTime);
            $hasRecentRequest = ($requestTime >= $sixMonthsAgoTime);
            $accountOldEnough = ($createdTime <= $sixMonthsAgoTime);

            $isInactive = (
                ($u['role'] ?? '') !== 'superadmin' &&
                ($u['status'] ?? '') !== 'Archived' &&
                $accountOldEnough &&
                !$hasRecentLogin &&
                !$hasRecentRequest
            );

            $u['is_inactive_candidate'] = $isInactive;
            $latestActivity = max($loginTime, $requestTime, $createdTime);
            $u['latest_activity_at'] = $latestActivity ? date('Y-m-d H:i:s', $latestActivity) : null;
            $u['inactive_months'] = $isInactive ? round(($now - $latestActivity) / (30 * 86400), 1) : 0;
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
        try {
            $caps = $this->getSchemaCapabilities();
            $builder = $this->builder();
            $builder->select('COUNT(DISTINCT users.id) as total');

            if ($caps['has_personnel']) {
                $joinCond = $caps['has_personnel_user_id']
                    ? "personnel.user_id = users.id OR (users.role IN ('worker', 'personnel') AND (personnel.name = CONCAT(TRIM(users.first_name), ' ', TRIM(users.last_name)) OR personnel.name LIKE CONCAT('%', TRIM(users.last_name), '%')))"
                    : "(users.role IN ('worker', 'personnel') AND (personnel.name = CONCAT(TRIM(users.first_name), ' ', TRIM(users.last_name)) OR personnel.name LIKE CONCAT('%', TRIM(users.last_name), '%')))";
                $builder->join('personnel', $joinCond, 'left');
            }

            if (!empty($search)) {
                $builder->groupStart()
                        ->like('users.first_name', $search)
                        ->orLike('users.last_name', $search)
                        ->orLike('users.email', $search)
                        ->orLike('users.student_id_number', $search);
                if ($caps['has_personnel'] && $caps['has_personnel_specialty']) {
                    $builder->orLike('personnel.specialty', $search);
                }
                $builder->groupEnd();
            }

            if (!empty($role) && $role !== 'all') {
                if ($role === 'personnel' || $role === 'worker') {
                    $builder->groupStart()
                            ->where('users.role', 'worker')
                            ->orWhere('users.role', 'personnel')
                            ->groupEnd();
                } else {
                    $builder->where('users.role', $role);
                }
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

            $res = $builder->get()->getRowArray();
            return (int) ($res['total'] ?? 0);
        } catch (\Throwable $e) {
            log_message('error', '[UserModel::getUsersCount] Error counting users: ' . $e->getMessage());
            try {
                $simple = $this->builder();
                if (!empty($search)) {
                    $simple->groupStart()
                           ->like('users.first_name', $search)
                           ->orLike('users.last_name', $search)
                           ->orLike('users.email', $search)
                           ->groupEnd();
                }
                if (!empty($role) && $role !== 'all') {
                    $simple->where('users.role', $role);
                }
                if (!empty($status) && $status !== 'all') {
                    $simple->where('users.status', $status);
                }
                return (int) $simple->countAllResults();
            } catch (\Throwable $e2) {
                return 0;
            }
        }
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
        $archivedUsers = $this->where('status', 'Archived')->countAllResults();

        // Role breakdown
        $roles = ['superadmin', 'admin', 'staff', 'director', 'worker', 'employee', 'student'];
        $roleBreakdown = [];
        foreach ($roles as $r) {
            if ($r === 'worker') {
                $roleBreakdown[$r] = $this->whereIn('role', ['worker', 'personnel'])->countAllResults();
            } else {
                $roleBreakdown[$r] = $this->where('role', $r)->countAllResults();
            }
        }

        return [
            'total_users'     => $totalUsers,
            'active_users'    => $activeUsers,
            'pending_users'   => $pendingUsers,
            'suspended_users' => $suspendedUsers,
            'archived_users'  => $archivedUsers,
            'by_role'         => $roleBreakdown,
        ];
    }

    /**
     * Find user accounts that are candidates for inactivity archiving.
     * Rule: Non-superadmin accounts created at least 6 months ago,
     * with no recorded login in the last 6 months, and no service ticket request
     * submitted in the last 6 months.
     *
     * @param bool $onlyCount If true, returns integer count of candidates
     * @return array|int
     */
    public function getInactiveCandidateUsers(bool $onlyCount = false): array|int
    {
        try {
            $caps = $this->getSchemaCapabilities();
            $sixMonthsAgo = date('Y-m-d H:i:s', strtotime('-6 months'));
            $builder = $this->builder();

            if ($onlyCount) {
                $builder->select('COUNT(users.id) as total');
            } else {
                $selects = [
                    'users.id',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    'users.role',
                    'users.status',
                    'users.created_at',
                ];

                if ($caps['has_last_login_at']) {
                    $selects[] = 'users.last_login_at';
                } else {
                    $selects[] = 'NULL AS last_login_at';
                }

                if ($caps['has_tickets']) {
                    $selects[] = '(SELECT MAX(created_at) FROM tickets WHERE tickets.user_id = users.id) AS last_request_at';
                } else {
                    $selects[] = 'NULL AS last_request_at';
                }

                if ($caps['has_activity_logs']) {
                    $selects[] = "(SELECT MAX(created_at) FROM account_activity_logs WHERE (actor_id = users.id OR target_user_id = users.id) AND event_type = 'AUTH_LOGIN_SUCCESS') AS log_last_login_at";
                } else {
                    $selects[] = 'NULL AS log_last_login_at';
                }

                if ($caps['has_user_sessions'] && $caps['session_activity_col']) {
                    $actCol = $caps['session_activity_col'];
                    $selects[] = "(SELECT MAX({$actCol}) FROM user_sessions WHERE user_sessions.user_id = users.id) AS session_last_activity";
                } else {
                    $selects[] = 'NULL AS session_last_activity';
                }

                $builder->select(implode(', ', $selects));
            }

            $builder->where('users.role !=', 'superadmin')
                    ->where('users.status !=', 'Archived')
                    ->where('users.created_at <=', $sixMonthsAgo);

            if ($caps['has_last_login_at']) {
                $builder->groupStart()
                        ->where('users.last_login_at IS NULL', null, false)
                        ->orWhere('users.last_login_at <', $sixMonthsAgo)
                        ->groupEnd();
            }

            if ($caps['has_activity_logs']) {
                $builder->where("NOT EXISTS (SELECT 1 FROM account_activity_logs WHERE (actor_id = users.id OR target_user_id = users.id) AND event_type = 'AUTH_LOGIN_SUCCESS' AND created_at >= '{$sixMonthsAgo}')", null, false);
            }

            if ($caps['has_user_sessions'] && $caps['session_activity_col']) {
                $actCol = $caps['session_activity_col'];
                $builder->where("NOT EXISTS (SELECT 1 FROM user_sessions WHERE user_sessions.user_id = users.id AND user_sessions.{$actCol} >= '{$sixMonthsAgo}')", null, false);
            }

            if ($caps['has_tickets']) {
                $builder->where("NOT EXISTS (SELECT 1 FROM tickets WHERE tickets.user_id = users.id AND tickets.created_at >= '{$sixMonthsAgo}')", null, false);
            }

            if ($onlyCount) {
                $row = $builder->get()->getRowArray();
                return (int) ($row['total'] ?? 0);
            }

            $results = $builder->orderBy('users.created_at', 'ASC')->get()->getResultArray();

            $now = time();
            foreach ($results as &$r) {
                $lastLoginAt    = $r['last_login_at'] ?? null;
                $logLastLogin   = $r['log_last_login_at'] ?? null;
                $sessionLastAct = $r['session_last_activity'] ?? null;
                $r['resolved_last_login_at'] = $lastLoginAt ?: ($logLastLogin ?: $sessionLastAct);
                $latestActivity = max(
                    !empty($r['resolved_last_login_at']) ? strtotime($r['resolved_last_login_at']) : 0,
                    !empty($r['last_request_at']) ? strtotime($r['last_request_at']) : 0,
                    strtotime($r['created_at'])
                );
                $r['latest_activity_at'] = date('Y-m-d H:i:s', $latestActivity);
                $r['inactive_months'] = round(($now - $latestActivity) / (30 * 86400), 1);
            }
            unset($r);

            return $results;
        } catch (\Throwable $e) {
            log_message('error', '[UserModel::getInactiveCandidateUsers] Error: ' . $e->getMessage());
            return $onlyCount ? 0 : [];
        }
    }
}
