<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add Employee Type Field to users Table
 *
 * Adds:
 * - `employee_type`: VARCHAR(100) NULL DEFAULT NULL (Faculty/Staff classification: Teaching Staff, Research and Extension Staff, Support / Administrative Staff)
 */
class AddEmployeeTypeToUsersTable extends Migration
{
    public function up(): void
    {
        $fields = [];

        if (!$this->db->fieldExists('employee_type', 'users')) {
            $fields['employee_type'] = [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => null,
                'after'      => 'student_type',
            ];
        }

        if (!empty($fields)) {
            $this->forge->addColumn('users', $fields);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('employee_type', 'users')) {
            $this->forge->dropColumn('users', 'employee_type');
        }
    }
}
