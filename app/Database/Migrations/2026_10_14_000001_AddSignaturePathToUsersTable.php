<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add Signature Path To Users Table
 *
 * Adds a nullable `signature_path` column to the `users` table,
 * allowing administrators and directors to save an official signature
 * to be applied to generated job orders and archived documents.
 */
class AddSignaturePathToUsersTable extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('users') && !$this->db->fieldExists('signature_path', 'users')) {
            $this->forge->addColumn('users', [
                'signature_path' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                    'default'    => null,
                    'after'      => 'avatar_path',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('users') && $this->db->fieldExists('signature_path', 'users')) {
            $this->forge->dropColumn('users', 'signature_path');
        }
    }
}
