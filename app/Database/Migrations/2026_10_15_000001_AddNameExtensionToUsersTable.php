<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add Name Extension To Users Table
 *
 * Adds a nullable `name_extension` column to the `users` table,
 * allowing users to record name extensions / suffixes (e.g., Jr., Sr., II, III, IV, etc.)
 * in conformity with Philippine civil service and university personal data sheet standards.
 */
class AddNameExtensionToUsersTable extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('users') && !$this->db->fieldExists('name_extension', 'users')) {
            $this->forge->addColumn('users', [
                'name_extension' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'null'       => true,
                    'default'    => null,
                    'after'      => 'last_name',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('users') && $this->db->fieldExists('name_extension', 'users')) {
            $this->forge->dropColumn('users', 'name_extension');
        }
    }
}
