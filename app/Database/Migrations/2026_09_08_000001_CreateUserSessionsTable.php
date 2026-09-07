<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create User Sessions Table
 *
 * Enforces "One Session Per User" concurrent session control.
 * The UNIQUE constraint on `user_id` guarantees at the database level
 * that only one active session can exist per user at any time.
 *
 * When a user logs in from a new location/browser, the previous session
 * is superseded. Any incoming request carrying an old session ID (sid)
 * will fail validation and trigger a clean session termination notice.
 *
 * Depends on: users (002)
 */
class CreateUserSessionsTable extends Migration
{
    public function up(): void
    {
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
            'session_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
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
            'created_at' => [
                'type'    => 'TIMESTAMP',
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
                'null'    => false,
            ],
            'last_activity' => [
                'type'    => 'TIMESTAMP',
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
                'null'    => false,
                'extra'   => 'ON UPDATE CURRENT_TIMESTAMP',
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('user_id', 'uq_user_sessions_user');
        $this->forge->addKey('session_id', false, false, 'idx_user_sessions_sid');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE', 'fk_user_sessions_user');

        $this->forge->createTable('user_sessions', true, [
            'ENGINE'          => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            'COLLATE'         => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('user_sessions', true);
    }
}
