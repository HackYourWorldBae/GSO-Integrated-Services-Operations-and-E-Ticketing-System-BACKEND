<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create Account Activity Logs Table (Account Security & Lifecycle Audit Trail)
 *
 * Immutable audit trail specifically for account lifecycle and authentication
 * security events, designed in strict compliance with the Philippine Data Privacy
 * Act of 2012 (RA 10173).
 *
 * Tracks:
 *   - AUTH_LOGIN_SUCCESS
 *   - AUTH_LOGIN_FAILED
 *   - AUTH_LOCKOUT
 *   - AUTH_UNLOCK
 *   - AUTH_LOGOUT
 *   - ACCOUNT_REGISTERED
 *   - ACCOUNT_CREATED
 *   - ACCOUNT_UPDATED
 *   - ACCOUNT_VERIFIED
 *   - ACCOUNT_VERIFICATION_REJECTED
 *   - ACCOUNT_STATUS_CHANGED
 *   - ACCOUNT_PASSWORD_CHANGED
 *   - ACCOUNT_DELETED
 *   - RBAC_UPDATED
 *
 * Privacy Standards:
 *   - Zero sensitive credential logging (no passwords, OTPs, or session secrets).
 *   - Foreign keys use SET NULL to preserve audit records even if an account is removed.
 *   - Append-only design.
 */
class CreateAccountActivityLogsTable extends Migration
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
            'actor_id' => [
                // User who initiated the action; NULL for unauthenticated/guest or system automated actions
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => true,
                'default'    => null,
            ],
            'target_user_id' => [
                // User account affected by the action; NULL if global or self
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => true,
                'default'    => null,
            ],
            'event_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 60,
                'null'       => false,
            ],
            'severity' => [
                'type'       => 'ENUM',
                'constraint' => ['info', 'notice', 'warning', 'critical'],
                'default'    => 'info',
                'null'       => false,
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
                'default'    => null,
            ],
            'user_agent' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'default'    => null,
            ],
            'device_summary' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => null,
            ],
            'details' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'metadata' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type'    => 'TIMESTAMP',
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
                'null'    => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('actor_id',       false, false, 'idx_act_logs_actor');
        $this->forge->addKey('target_user_id', false, false, 'idx_act_logs_target');
        $this->forge->addKey('event_type',     false, false, 'idx_act_logs_event');
        $this->forge->addKey('severity',       false, false, 'idx_act_logs_severity');
        $this->forge->addKey('created_at',     false, false, 'idx_act_logs_created');

        $this->forge->addForeignKey('actor_id',       'users', 'id', 'SET NULL', 'CASCADE', 'fk_act_logs_actor');
        $this->forge->addForeignKey('target_user_id', 'users', 'id', 'SET NULL', 'CASCADE', 'fk_act_logs_target');

        $this->forge->createTable('account_activity_logs', true, [
            'ENGINE'          => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            'COLLATE'         => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('account_activity_logs', true);
    }
}
