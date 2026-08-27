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
 * - `unit_id`   : FK to units — mandatory; determines which sub-unit they belong to
 * - `specialty` : Free-text role descriptor (e.g., "Plumber", "Professional Driver")
 * - `status`    : availability for dispatcher assignments
 *
 * Depends on: units (001)
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
            'contact_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
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
