<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add Optional Multifactor Authentication (MFA) and Challenges Table
 *
 * Adds `is_mfa_enabled` (default 0) to `users` table and creates `user_mfa_challenges`
 * for storing temporary session challenges and 6-digit OTP codes.
 */
class AddMfaAndChallengesTable extends Migration
{
    public function up(): void
    {
        // 1. Add is_mfa_enabled to users table
        if (!$this->db->fieldExists('is_mfa_enabled', 'users')) {
            $this->forge->addColumn('users', [
                'is_mfa_enabled' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'unsigned'   => true,
                    'null'       => false,
                    'default'    => 0,
                    'after'      => 'email_notifications_enabled',
                    'comment'    => 'Optional two-factor authentication via email OTP (0: disabled, 1: enabled)',
                ],
            ]);
        }

        // 2. Create user_mfa_challenges table
        if (!$this->db->tableExists('user_mfa_challenges')) {
            $this->forge->addField([
                'id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                    'null'       => false,
                ],
                'user_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                    'null'       => false,
                ],
                'temp_token' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => false,
                ],
                'otp_code' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 10,
                    'null'       => false,
                ],
                'expires_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                ],
                'attempts' => [
                    'type'       => 'TINYINT',
                    'constraint' => 3,
                    'unsigned'   => true,
                    'null'       => false,
                    'default'    => 0,
                ],
                'ip_address' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 45,
                    'null'       => true,
                ],
                'user_agent' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);

            $this->forge->addKey('id', true);
            $this->forge->addKey('temp_token');
            $this->forge->addKey('user_id');
            $this->forge->addKey('expires_at');
            $this->forge->createTable('user_mfa_challenges', true);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('user_mfa_challenges')) {
            $this->forge->dropTable('user_mfa_challenges', true);
        }

        if ($this->db->fieldExists('is_mfa_enabled', 'users')) {
            $this->forge->dropColumn('users', 'is_mfa_enabled');
        }
    }
}
