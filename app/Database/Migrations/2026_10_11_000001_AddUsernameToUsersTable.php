<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add Username To Users Table
 *
 * Adds a nullable `username` column to `users` table with an index,
 * allowing personnel/field workers to have portal usernames instead
 * of requiring institutional email addresses.
 */
class AddUsernameToUsersTable extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('users') && !$this->db->fieldExists('username', 'users')) {
            $this->forge->addColumn('users', [
                'username' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                    'null'       => true,
                    'default'    => null,
                    'after'      => 'last_name',
                ],
            ]);

            $this->forge->addKey('username', false, false, 'idx_users_username');
            $this->forge->processIndexes('users');

            try {
                $this->db->query("UPDATE `users` SET `username` = `email` WHERE `role` = 'worker' AND (`username` IS NULL OR `username` = '')");
            } catch (\Throwable $e) {
                // Non-fatal backfill
            }
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('users') && $this->db->fieldExists('username', 'users')) {
            $this->forge->dropColumn('users', 'username');
        }
    }
}
