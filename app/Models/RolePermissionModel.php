<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * RolePermissionModel
 *
 * Manages the dynamic access control matrix between system roles and features.
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
            'description' => 'Access the unit dispatcher workbench and assignment queue.',
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
        'dispatcher',
        'director',
        'worker',
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

        $row = $this->where('role', $role)
                    ->where('feature_key', $featureKey)
                    ->first();

        if ($row) {
            return (bool) $row['is_enabled'];
        }

        // Fallback default: Unit Head (admin) inherits dispatcher features
        if ($role === 'admin' && in_array($featureKey, [
            'tickets.create', 'tickets.view_all', 'tickets.approve_decline',
            'tickets.dispatch', 'tickets.assign_worker', 'tickets.complete_work',
            'tickets.verify_close', 'personnel.manage', 'reports.view'
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

        $rows = $this->where('role', $role)->findAll();
        if (empty($rows)) {
            // Return defaults
            $enabled = [];
            foreach (self::SYSTEM_FEATURES as $feat) {
                if ($this->hasPermission($role, $feat['key'])) {
                    $enabled[] = $feat['key'];
                }
            }
            return $enabled;
        }

        $enabled = [];
        foreach ($rows as $r) {
            if (!empty($r['is_enabled'])) {
                $enabled[] = $r['feature_key'];
            }
        }
        return $enabled;
    }

    /**
     * Get full matrix of roles x features for Superadmin UI.
     */
    public function getFullMatrix(): array
    {
        $allRows = $this->findAll();
        $lookup = [];
        foreach ($allRows as $row) {
            $lookup[$row['role']][$row['feature_key']] = (bool) $row['is_enabled'];
        }

        $matrix = [];
        foreach (self::SYSTEM_FEATURES as $feat) {
            $key = $feat['key'];
            $rolesState = [];
            foreach (self::SYSTEM_ROLES as $role) {
                if (isset($lookup[$role][$key])) {
                    $rolesState[$role] = $lookup[$role][$key];
                } else {
                    $rolesState[$role] = $this->hasPermission($role, $key);
                }
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
     * Bulk save matrix settings.
     *
     * @param array $matrix [ ['role' => string, 'feature_key' => string, 'is_enabled' => bool], ... ]
     */
    public function saveMatrix(array $matrix): bool
    {
        helper('sanitize');
        $now = date('Y-m-d H:i:s');
        foreach ($matrix as $item) {
            $role    = sanitize_string($item['role'] ?? '');
            $feature = sanitize_string($item['feature_key'] ?? '');
            $enabled = !empty($item['is_enabled']) ? 1 : 0;

            if (empty($role) || empty($feature)) {
                continue;
            }

            // Do not allow revoking superadmin matrix control
            if ($role === 'superadmin') {
                $enabled = 1;
            }

            $existing = $this->where('role', $role)->where('feature_key', $feature)->first();
            if ($existing) {
                $this->update($existing['id'], [
                    'is_enabled' => $enabled,
                    'updated_at' => $now,
                ]);
            } else {
                $this->insert([
                    'role'        => $role,
                    'feature_key' => $feature,
                    'is_enabled'  => $enabled,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }

        return true;
    }
}
