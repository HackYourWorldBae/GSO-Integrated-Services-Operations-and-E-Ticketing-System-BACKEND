<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add College Field to users Table
 *
 * Adds:
 * - `college`: VARCHAR(150) NULL DEFAULT NULL (Benguet State University College/Academic Unit for students)
 */
class AddCollegeToUsersTable extends Migration
{
    public function up(): void
    {
        $fields = [];

        if (!$this->db->fieldExists('college', 'users')) {
            $fields['college'] = [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => true,
                'default'    => null,
                'after'      => 'organization_name',
            ];
        }

        if (!empty($fields)) {
            $this->forge->addColumn('users', $fields);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('college', 'users')) {
            $this->forge->dropColumn('users', 'college');
        }
    }
}
