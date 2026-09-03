<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create Users Table
 *
 * Unified user table covering all six roles:
 *   student, employee, admin, dispatcher, director, worker
 *
 * - `id`                : UUID (VARCHAR 36) — no auto-increment
 * - `unit_id`           : FK to units — NULL for students; set for unit staff
 * - `is_verified`       : TINYINT 0/1 — email verification gate
 * - `status`            : Controls login access (Active, Pending, Rejected, Suspended)
 *
 * Depends on: units (migration 001)
 */
class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => false,
            ],
            'first_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
            ],
            'last_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'password_hash' => [
                'type' => 'TEXT',
                'null' => false,
            ],
            'contact_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
                'default'    => null,
            ],
            'role' => [
                'type'       => 'ENUM',
                'constraint' => ['student', 'employee', 'admin', 'dispatcher', 'director', 'worker'],
                'default'    => 'student',
                'null'       => false,
            ],
            'unit_id' => [
                'type'     => 'INT',
                'constraint'=> 11,
                'unsigned'  => true,
                'null'      => true,
                'default'   => null,
            ],
            'student_id_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'default'    => null,
            ],
            'id_card_image' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['Active', 'Pending', 'Rejected', 'Suspended'],
                'default'    => 'Active',
                'null'       => false,
            ],
            'is_verified' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
                'null'       => false,
            ],
            'created_at' => [
                'type'    => 'TIMESTAMP',
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
                'null'    => false,
            ],
            'updated_at' => [
                'type'    => 'TIMESTAMP',
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
                'null'    => false,
                'extra'   => 'ON UPDATE CURRENT_TIMESTAMP',
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email');
        $this->forge->addKey('role',   false, false, 'idx_users_role');
        $this->forge->addKey('status', false, false, 'idx_users_status');

        // FK: users.unit_id → units.id (NULL = student / general employee)
        $this->forge->addForeignKey('unit_id', 'units', 'id', 'SET NULL', 'CASCADE', 'fk_users_unit');

        $this->forge->createTable('users', true, [
            'ENGINE'         => 'InnoDB',
            'DEFAULT CHARSET'=> 'utf8mb4',
            'COLLATE'        => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('users', true);
    }
}
