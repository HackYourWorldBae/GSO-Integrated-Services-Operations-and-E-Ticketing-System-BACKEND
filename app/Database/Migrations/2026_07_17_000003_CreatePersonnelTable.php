<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create Personnel Table
 *
 * Represents field staff dispatched by each sub-unit:
 *   FGMU: Plumbers, Electricians, Carpenters
 *   LEAU: Gardeners, Janitors, Disinfection staff
 *   TASU: Professional Drivers
 *
 * - `id`        : UUID (VARCHAR 36)
 * - `user_id`   : Optional FK to users — set if the worker has a login account
 * - `unit_id`   : FK to units — mandatory; determines which sub-unit they belong to
 * - `specialty` : Free-text role descriptor (e.g., "Plumber", "Professional Driver")
 * - `status`    : availability for dispatcher assignments
 *
 * Depends on: units (001), users (002)
 */
class CreatePersonnelTable extends Migration
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
                'null'       => true,
                'default'    => null,
            ],
            'unit_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'specialty' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['available', 'working', 'on_leave', 'on_trip'],
                'default'    => 'available',
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
        $this->forge->addKey(['unit_id', 'status'], false, false, 'idx_personnel_unit_status');

        $this->forge->addForeignKey('user_id',  'users', 'id', 'SET NULL', 'CASCADE', 'fk_personnel_user');
        $this->forge->addForeignKey('unit_id',  'units', 'id', 'CASCADE',  'CASCADE', 'fk_personnel_unit');

        $this->forge->createTable('personnel', true, [
            'ENGINE'         => 'InnoDB',
            'DEFAULT CHARSET'=> 'utf8mb4',
            'COLLATE'        => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('personnel', true);
    }
}
