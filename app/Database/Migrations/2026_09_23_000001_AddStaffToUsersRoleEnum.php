<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add Staff To Users Role Enum
 *
 * Introduces the 'staff' internal account category for GSO sub-unit personnel.
 * Staff accounts are provisioned exclusively by the Superadmin (IT) and are
 * scoped to an assigned sub-unit dashboard (FGMU / LEAU / SSU), like admins.
 * Students and employees continue to self-register and are no longer
 * provisionable through the Superadmin user-management screen.
 */
class AddStaffToUsersRoleEnum extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('users', [
            'role' => [
                'type'       => 'ENUM',
                'constraint' => ['student', 'employee', 'admin', 'staff', 'director', 'superadmin'],
                'default'    => 'student',
                'null'       => false,
            ],
        ]);
    }

    public function down(): void
    {
        // Reassign any staff accounts to admin before shrinking the enum
        // so the rollback never orphans rows on strict MySQL modes.
        $this->db->query("UPDATE `users` SET `role` = 'admin' WHERE `role` = 'staff'");

        $this->forge->modifyColumn('users', [
            'role' => [
                'type'       => 'ENUM',
                'constraint' => ['student', 'employee', 'admin', 'director', 'superadmin'],
                'default'    => 'student',
                'null'       => false,
            ],
        ]);
    }
}
