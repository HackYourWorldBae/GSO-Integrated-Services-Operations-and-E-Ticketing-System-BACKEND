<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add Account Lockout Fields to users Table
 *
 * Implements security measure: Locks user account for 15 minutes
 * after 5 consecutive failed login attempts with incorrect password.
 *
 * - `failed_login_attempts`: INT UNSIGNED NOT NULL DEFAULT 0
 * - `lockout_until`: DATETIME NULL DEFAULT NULL
 */
class AddAccountLockoutToUsersTable extends Migration
{
    public function up(): void
    {
        $fields = [];

        if (!$this->db->fieldExists('failed_login_attempts', 'users')) {
            $fields['failed_login_attempts'] = [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'default'    => 0,
                'null'       => false,
                'after'      => 'is_verified',
            ];
        }

        if (!$this->db->fieldExists('lockout_until', 'users')) {
            $fields['lockout_until'] = [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
                'after'   => 'failed_login_attempts',
            ];
        }

        if (!empty($fields)) {
            $this->forge->addColumn('users', $fields);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('lockout_until', 'users')) {
            $this->forge->dropColumn('users', 'lockout_until');
        }

        if ($this->db->fieldExists('failed_login_attempts', 'users')) {
            $this->forge->dropColumn('users', 'failed_login_attempts');
        }
    }
}
