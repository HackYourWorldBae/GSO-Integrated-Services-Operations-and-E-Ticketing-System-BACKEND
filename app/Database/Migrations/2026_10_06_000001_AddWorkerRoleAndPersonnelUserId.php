<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add Worker Role And Personnel UserId
 *
 * 1. Extends the `users.role` ENUM to include 'worker' for field personnel who have
 *    a mobile/smart device to view their assigned tickets and work team.
 * 2. Adds nullable `user_id` column to the `personnel` table to link the personnel
 *    roster record to their authentication user account.
 */
class AddWorkerRoleAndPersonnelUserId extends Migration
{
    public function up(): void
    {
        // 1. Extend users.role ENUM
        if ($this->db->tableExists('users')) {
            $this->forge->modifyColumn('users', [
                'role' => [
                    'type'       => 'ENUM',
                    'constraint' => ['student', 'employee', 'admin', 'staff', 'director', 'superadmin', 'worker'],
                    'default'    => 'student',
                    'null'       => false,
                ],
            ]);
        }

        // 2. Add user_id column to personnel table
        if ($this->db->tableExists('personnel') && !$this->db->fieldExists('user_id', 'personnel')) {
            $this->forge->addColumn('personnel', [
                'user_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                    'null'       => true,
                    'default'    => null,
                    'after'      => 'unit_id',
                ],
            ]);

            $this->forge->addKey('user_id', false, false, 'idx_personnel_user');
            $this->forge->processIndexes('personnel');
        }
    }

    public function down(): void
    {
        // Revert personnel.user_id
        if ($this->db->tableExists('personnel') && $this->db->fieldExists('user_id', 'personnel')) {
            $this->forge->dropColumn('personnel', 'user_id');
        }

        // Revert users.role ENUM
        if ($this->db->tableExists('users')) {
            $this->db->query("UPDATE `users` SET `role` = 'employee' WHERE `role` = 'worker'");
            $this->forge->modifyColumn('users', [
                'role' => [
                    'type'       => 'ENUM',
                    'constraint' => ['student', 'employee', 'admin', 'staff', 'director', 'superadmin'],
                    'default'    => 'student',
                    'null'       => false,
                ],
            ]);
        }
    }
}
