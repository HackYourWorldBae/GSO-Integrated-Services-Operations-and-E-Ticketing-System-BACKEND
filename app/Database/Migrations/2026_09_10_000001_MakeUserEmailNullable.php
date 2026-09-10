<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Make User Email Nullable
 *
 * Allows users (such as elderly or offline staff) without an email
 * address to register using their Employee/Student ID and contact number.
 */
class MakeUserEmailNullable extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('users', [
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'default'    => null,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->modifyColumn('users', [
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
        ]);
    }
}
