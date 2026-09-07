<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create Role Permissions Table
 *
 * Implements the Superadmin Role & Capability Access Control Matrix.
 * Allows superadmins to dynamically toggle feature permissions across roles.
 * Unit Head ('admin') is seeded with Dispatcher capabilities by default.
 */
class CreateRolePermissionsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'role' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => false,
            ],
            'feature_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
            ],
            'is_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
                'null'       => false,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
            ],
            'updated_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['role', 'feature_key'], 'unique_role_feature');
        $this->forge->createTable('role_permissions', true);

        // Seed initial default capabilities
        $now = date('Y-m-d H:i:s');
        $defaults = [
            // Superadmin has everything
            ['role' => 'superadmin', 'feature_key' => 'tickets.create',         'is_enabled' => 1],
            ['role' => 'superadmin', 'feature_key' => 'tickets.view_all',       'is_enabled' => 1],
            ['role' => 'superadmin', 'feature_key' => 'tickets.approve_decline','is_enabled' => 1],
            ['role' => 'superadmin', 'feature_key' => 'tickets.dispatch',       'is_enabled' => 1],
            ['role' => 'superadmin', 'feature_key' => 'tickets.assign_worker',  'is_enabled' => 1],
            ['role' => 'superadmin', 'feature_key' => 'tickets.complete_work',  'is_enabled' => 1],
            ['role' => 'superadmin', 'feature_key' => 'tickets.verify_close',   'is_enabled' => 1],
            ['role' => 'superadmin', 'feature_key' => 'personnel.manage',       'is_enabled' => 1],
            ['role' => 'superadmin', 'feature_key' => 'reports.view',           'is_enabled' => 1],
            ['role' => 'superadmin', 'feature_key' => 'users.provision',        'is_enabled' => 1],
            ['role' => 'superadmin', 'feature_key' => 'system.matrix_control',  'is_enabled' => 1],

            // Unit Head (admin): has unit admin + dispatcher capabilities by default
            ['role' => 'admin',      'feature_key' => 'tickets.create',         'is_enabled' => 1],
            ['role' => 'admin',      'feature_key' => 'tickets.view_all',       'is_enabled' => 1],
            ['role' => 'admin',      'feature_key' => 'tickets.approve_decline','is_enabled' => 1],
            ['role' => 'admin',      'feature_key' => 'tickets.dispatch',       'is_enabled' => 1],
            ['role' => 'admin',      'feature_key' => 'tickets.assign_worker',  'is_enabled' => 1],
            ['role' => 'admin',      'feature_key' => 'tickets.complete_work',  'is_enabled' => 1],
            ['role' => 'admin',      'feature_key' => 'tickets.verify_close',   'is_enabled' => 1],
            ['role' => 'admin',      'feature_key' => 'personnel.manage',       'is_enabled' => 1],
            ['role' => 'admin',      'feature_key' => 'reports.view',           'is_enabled' => 1],
            ['role' => 'admin',      'feature_key' => 'users.provision',        'is_enabled' => 0],
            ['role' => 'admin',      'feature_key' => 'system.matrix_control',  'is_enabled' => 0],

            // Dispatcher
            ['role' => 'dispatcher', 'feature_key' => 'tickets.create',         'is_enabled' => 0],
            ['role' => 'dispatcher', 'feature_key' => 'tickets.view_all',       'is_enabled' => 1],
            ['role' => 'dispatcher', 'feature_key' => 'tickets.approve_decline','is_enabled' => 1],
            ['role' => 'dispatcher', 'feature_key' => 'tickets.dispatch',       'is_enabled' => 1],
            ['role' => 'dispatcher', 'feature_key' => 'tickets.assign_worker',  'is_enabled' => 1],
            ['role' => 'dispatcher', 'feature_key' => 'tickets.complete_work',  'is_enabled' => 1],
            ['role' => 'dispatcher', 'feature_key' => 'tickets.verify_close',   'is_enabled' => 1],
            ['role' => 'dispatcher', 'feature_key' => 'personnel.manage',       'is_enabled' => 1],
            ['role' => 'dispatcher', 'feature_key' => 'reports.view',           'is_enabled' => 1],
            ['role' => 'dispatcher', 'feature_key' => 'users.provision',        'is_enabled' => 0],
            ['role' => 'dispatcher', 'feature_key' => 'system.matrix_control',  'is_enabled' => 0],

            // Director
            ['role' => 'director',   'feature_key' => 'tickets.create',         'is_enabled' => 1],
            ['role' => 'director',   'feature_key' => 'tickets.view_all',       'is_enabled' => 1],
            ['role' => 'director',   'feature_key' => 'tickets.approve_decline','is_enabled' => 1],
            ['role' => 'director',   'feature_key' => 'tickets.dispatch',       'is_enabled' => 0],
            ['role' => 'director',   'feature_key' => 'tickets.assign_worker',  'is_enabled' => 0],
            ['role' => 'director',   'feature_key' => 'tickets.complete_work',  'is_enabled' => 0],
            ['role' => 'director',   'feature_key' => 'tickets.verify_close',   'is_enabled' => 1],
            ['role' => 'director',   'feature_key' => 'personnel.manage',       'is_enabled' => 0],
            ['role' => 'director',   'feature_key' => 'reports.view',           'is_enabled' => 1],
            ['role' => 'director',   'feature_key' => 'users.provision',        'is_enabled' => 0],
            ['role' => 'director',   'feature_key' => 'system.matrix_control',  'is_enabled' => 0],

            // Worker
            ['role' => 'worker',     'feature_key' => 'tickets.create',         'is_enabled' => 0],
            ['role' => 'worker',     'feature_key' => 'tickets.view_all',       'is_enabled' => 0],
            ['role' => 'worker',     'feature_key' => 'tickets.approve_decline','is_enabled' => 0],
            ['role' => 'worker',     'feature_key' => 'tickets.dispatch',       'is_enabled' => 0],
            ['role' => 'worker',     'feature_key' => 'tickets.assign_worker',  'is_enabled' => 0],
            ['role' => 'worker',     'feature_key' => 'tickets.complete_work',  'is_enabled' => 1],
            ['role' => 'worker',     'feature_key' => 'tickets.verify_close',   'is_enabled' => 0],
            ['role' => 'worker',     'feature_key' => 'personnel.manage',       'is_enabled' => 0],
            ['role' => 'worker',     'feature_key' => 'reports.view',           'is_enabled' => 0],
            ['role' => 'worker',     'feature_key' => 'users.provision',        'is_enabled' => 0],
            ['role' => 'worker',     'feature_key' => 'system.matrix_control',  'is_enabled' => 0],

            // Employee
            ['role' => 'employee',   'feature_key' => 'tickets.create',         'is_enabled' => 1],
            ['role' => 'employee',   'feature_key' => 'tickets.view_all',       'is_enabled' => 0],
            ['role' => 'employee',   'feature_key' => 'tickets.approve_decline','is_enabled' => 0],
            ['role' => 'employee',   'feature_key' => 'tickets.dispatch',       'is_enabled' => 0],
            ['role' => 'employee',   'feature_key' => 'tickets.assign_worker',  'is_enabled' => 0],
            ['role' => 'employee',   'feature_key' => 'tickets.complete_work',  'is_enabled' => 0],
            ['role' => 'employee',   'feature_key' => 'tickets.verify_close',   'is_enabled' => 0],
            ['role' => 'employee',   'feature_key' => 'personnel.manage',       'is_enabled' => 0],
            ['role' => 'employee',   'feature_key' => 'reports.view',           'is_enabled' => 0],
            ['role' => 'employee',   'feature_key' => 'users.provision',        'is_enabled' => 0],
            ['role' => 'employee',   'feature_key' => 'system.matrix_control',  'is_enabled' => 0],

            // Student
            ['role' => 'student',    'feature_key' => 'tickets.create',         'is_enabled' => 1],
            ['role' => 'student',    'feature_key' => 'tickets.view_all',       'is_enabled' => 0],
            ['role' => 'student',    'feature_key' => 'tickets.approve_decline','is_enabled' => 0],
            ['role' => 'student',    'feature_key' => 'tickets.dispatch',       'is_enabled' => 0],
            ['role' => 'student',    'feature_key' => 'tickets.assign_worker',  'is_enabled' => 0],
            ['role' => 'student',    'feature_key' => 'tickets.complete_work',  'is_enabled' => 0],
            ['role' => 'student',    'feature_key' => 'tickets.verify_close',   'is_enabled' => 0],
            ['role' => 'student',    'feature_key' => 'personnel.manage',       'is_enabled' => 0],
            ['role' => 'student',    'feature_key' => 'reports.view',           'is_enabled' => 0],
            ['role' => 'student',    'feature_key' => 'users.provision',        'is_enabled' => 0],
            ['role' => 'student',    'feature_key' => 'system.matrix_control',  'is_enabled' => 0],
        ];

        foreach ($defaults as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        $this->db->table('role_permissions')->insertBatch($defaults);
    }

    public function down(): void
    {
        $this->forge->dropTable('role_permissions', true);
    }
}
