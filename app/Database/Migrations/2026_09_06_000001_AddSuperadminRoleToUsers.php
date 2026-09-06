<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add superadmin role to users table enum.
 */
class AddSuperadminRoleToUsers extends Migration
{
    public function up(): void
    {
        $fields = [
            'role' => [
                'name'       => 'role',
                'type'       => 'ENUM',
                'constraint' => ['student', 'employee', 'admin', 'dispatcher', 'director', 'worker', 'superadmin'],
                'default'    => 'student',
                'null'       => false,
            ],
        ];

        if ($this->db->tableExists('users')) {
            $this->forge->modifyColumn('users', $fields);
        }
    }

    public function down(): void
    {
        $fields = [
            'role' => [
                'name'       => 'role',
                'type'       => 'ENUM',
                'constraint' => ['student', 'employee', 'admin', 'dispatcher', 'director', 'worker'],
                'default'    => 'student',
                'null'       => false,
            ],
        ];

        if ($this->db->tableExists('users')) {
            $this->forge->modifyColumn('users', $fields);
        }
    }
}
