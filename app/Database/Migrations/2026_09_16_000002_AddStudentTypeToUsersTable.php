<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add Student Categorization Fields to users Table
 *
 * Adds:
 * - `student_type`: VARCHAR(50) NULL DEFAULT NULL ('rso', 'ssg')
 * - `organization_name`: VARCHAR(150) NULL DEFAULT NULL (RSO club name or SSG position)
 */
class AddStudentTypeToUsersTable extends Migration
{
    public function up(): void
    {
        $fields = [];

        if (!$this->db->fieldExists('student_type', 'users')) {
            $fields['student_type'] = [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'default'    => null,
                'after'      => 'student_id_number',
            ];
        }

        if (!$this->db->fieldExists('organization_name', 'users')) {
            $fields['organization_name'] = [
                'type'       => 'VARCHAR',
                'constraint' => 150,
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
        if ($this->db->fieldExists('organization_name', 'users')) {
            $this->forge->dropColumn('users', 'organization_name');
        }

        if ($this->db->fieldExists('student_type', 'users')) {
            $this->forge->dropColumn('users', 'student_type');
        }
    }
}
