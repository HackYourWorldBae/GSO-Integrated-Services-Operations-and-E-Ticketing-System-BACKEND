<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * RolePermissionModel
 *
 * Manages the dynamic access control matrix between system roles and features.
 *
 * NOTE (maintainability): the backing `role_permissions` table is retired —
 * the matrix below is intentionally hardcoded (see migration
 * 2026_09_16_000003). hasPermission()/getPermissionsForRole()/getFullMatrix()
 * perform pure in-memory checks and never query the database, so all callers
 * (RoleGuardFilter, BaseController::assertPermission, AuthController, UserModel)
 * remain safe. Kept as a model for a single RBAC source of truth.
 */
class RolePermissionModel extends Model
{
    protected $table            = 'role_permissions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $allowedFields    = [
        'role',
        'feature_key',
        'is_enabled',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    // Standard list of system capabilities
    public const SYSTEM_FEATURES = [
        [
            'key'         => 'tickets.create',
            'name'        => 'Create Service Request',
            'category'    => 'Tickets & Services',
            'description' => 'Submit new service requests and repair tickets.',
        ],
        [
            'key'         => 'tickets.view_all',
            'name'        => 'View Unit Tickets',
            'category'    => 'Tickets & Services',
            'description' => 'View all tickets submitted to their unit or university-wide.',
        ],
        [
            'key'         => 'tickets.approve_decline',
            'name'        => 'Approve / Decline Tickets',
            'category'    => 'Triage & Approval',
            'description' => 'Perform initial triage, set EODB tiers, and approve/decline tickets.',
        ],
        [
            'key'         => 'tickets.dispatch',
            'name'        => 'Unit Dispatch Access',
            'category'    => 'Dispatching',
            'description' => 'Access the unit admin workbench and assignment queue.',
        ],
        [
            'key'         => 'tickets.assign_worker',
            'name'        => 'Assign / Reassign Workers',
            'category'    => 'Dispatching',
            'description' => 'Dispatch personnel, schedule jobs, and insert emergency priority tasks.',
        ],
        [
            'key'         => 'tickets.complete_work',
            'name'        => 'Complete Field Work & Materials',
            'category'    => 'Field Operations',
            'description' => 'Mark assignments done, record material usage, and submit accomplishment proofs.',
        ],
        [
            'key'         => 'tickets.verify_close',
            'name'        => 'Verify & Close Tickets',
            'category'    => 'Verification & Closure',
            'description' => 'Verify accomplishment reports and officially close tickets.',
        ],
        [
            'key'         => 'personnel.manage',
            'name'        => 'Manage Unit Personnel',
            'category'    => 'Staff Management',
            'description' => 'Manage worker profiles, specialties, and handle leave/retirement reassignments.',
        ],
        [
            'key'         => 'reports.view',
            'name'        => 'View Analytics & Reports',
            'category'    => 'Reports & Compliance',
            'description' => 'Access EODB compliance metrics, completion stats, and performance logs.',
        ],
        [
            'key'         => 'users.provision',
            'name'        => 'Provision Accounts',
            'category'    => 'User Administration',
            'description' => 'Create, approve, and manage user accounts.',
        ],
        [
            'key'         => 'system.matrix_control',
            'name'        => 'Access Matrix Control',
            'category'    => 'Security & RBAC',
            'description' => 'Configure system-wide feature permissions per role.',
        ],
    ];

    public const SYSTEM_ROLES = [
        'superadmin',
        'admin',      // Unit Head
        'director',
        'employee',
        'student',
    ];

    /**
     * Check if a given role has a specific capability.
     */
    public function hasPermission(string $role, string $featureKey): bool
    {
        if ($role === 'superadmin') {
            return true;
        }

        // Unit Head (admin) has full operational, dispatch, triage, and reporting capabilities
        if ($role === 'admin' && in_array($featureKey, [
            'tickets.create', 'tickets.view_all', 'tickets.approve_decline',
            'tickets.dispatch', 'tickets.assign_worker', 'tickets.complete_work',
            'tickets.verify_close', 'personnel.manage', 'reports.view'
        ], true)) {
            return true;
        }

        // Director has university-wide oversight and analytics
        if ($role === 'director' && in_array($featureKey, [
            'tickets.view_all', 'reports.view'
        ], true)) {
            return true;
        }

        // Requestors (employee / student) can create tickets and rate completions
        if (in_array($role, ['employee', 'student'], true) && in_array($featureKey, [
            'tickets.create', 'tickets.rate'
        ], true)) {
            return true;
        }

        return false;
    }

    /**
     * Get list of enabled feature keys for a role.
     */
    public function getPermissionsForRole(string $role): array
    {
        if ($role === 'superadmin') {
            return array_column(self::SYSTEM_FEATURES, 'key');
        }

        $enabled = [];
        foreach (self::SYSTEM_FEATURES as $feat) {
            if ($this->hasPermission($role, $feat['key'])) {
                $enabled[] = $feat['key'];
            }
        }
        return $enabled;
    }

    /**
     * Get full matrix of roles x features for Superadmin UI or reporting.
     */
    public function getFullMatrix(): array
    {
        $matrix = [];
        foreach (self::SYSTEM_FEATURES as $feat) {
            $key = $feat['key'];
            $rolesState = [];
            foreach (self::SYSTEM_ROLES as $role) {
                $rolesState[$role] = $this->hasPermission($role, $key);
            }
            $matrix[] = [
                'key'         => $feat['key'],
                'name'        => $feat['name'],
                'category'    => $feat['category'],
                'description' => $feat['description'],
                'roles'       => $rolesState,
            ];
        }

        return [
            'features' => self::SYSTEM_FEATURES,
            'roles'    => self::SYSTEM_ROLES,
            'matrix'   => $matrix,
        ];
    }

    /**
     * Bulk save matrix settings (Stubbed since dynamic matrix is removed).
     */
    public function saveMatrix(array $matrix): bool
    {
        return true;
    }
}

