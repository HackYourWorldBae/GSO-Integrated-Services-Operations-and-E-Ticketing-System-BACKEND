<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add 'Archived' status to users.status ENUM and add last_login_at timestamp.
 *
 * Supports superadmin automated archiving for accounts inactive for 6+ months.
 */
class AddArchivedStatusAndLastLoginToUsersTable extends Migration
{
    public function up(): void
    {
        // 1. Add last_login_at column if not existing
        if (!$this->db->fieldExists('last_login_at', 'users')) {
            $this->forge->addColumn('users', [
                'last_login_at' => [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'default' => null,
                    'after'   => 'lockout_until',
                ],
            ]);
        }

        // 2. Expand status ENUM to include 'Archived'
        $this->db->query("ALTER TABLE `users` MODIFY COLUMN `status` ENUM('Active','Pending','Rejected','Suspended','Archived') NOT NULL DEFAULT 'Active'");
    }

    public function down(): void
    {
        // Revert status ENUM
        $this->db->query("ALTER TABLE `users` MODIFY COLUMN `status` ENUM('Active','Pending','Rejected','Suspended') NOT NULL DEFAULT 'Active'");

        // Drop last_login_at if present
        if ($this->db->fieldExists('last_login_at', 'users')) {
            $this->forge->dropColumn('users', 'last_login_at');
        }
    }
}
