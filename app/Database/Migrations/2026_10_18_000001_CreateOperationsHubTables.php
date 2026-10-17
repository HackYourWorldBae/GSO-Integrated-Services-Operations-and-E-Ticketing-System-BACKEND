<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Migration: Create Operations Hub Tables
 *
 * Establishes centralized operational configuration tables for:
 * 1. `catalog_materials`: Dynamic library of commonly used materials, strictly separated by sub-unit (FGMU & LEAU).
 * 2. `special_holidays`: Special non-working holidays and executive declarations factored into ticket duration / SLA.
 * 3. `system_settings` records for institutional working hours (start time, end time, lunch break, daily hours).
 */
class CreateOperationsHubTables extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------------
        // 1. Table: catalog_materials
        // ---------------------------------------------------------------------
        if (!$this->db->tableExists('catalog_materials')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'unit_code' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 10,
                    'null'       => false,
                    'comment'    => 'Sub-unit identifier: FGMU or LEAU',
                ],
                'category' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 60,
                    'null'       => false,
                ],
                'name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => false,
                ],
                'unit' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 50,
                    'default'    => 'pcs',
                ],
                'default_price' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '10,2',
                    'default'    => '0.00',
                ],
                'is_active' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
                ],
                'created_at' => [
                    'type'    => 'TIMESTAMP',
                    'default' => new RawSql('CURRENT_TIMESTAMP'),
                ],
                'updated_at' => [
                    'type'    => 'TIMESTAMP',
                    'default' => new RawSql('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
                ],
            ]);

            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['unit_code', 'is_active']);
            $this->forge->addKey('category');
            $this->forge->addKey('name');

            $this->forge->createTable('catalog_materials', true, [
                'ENGINE'          => 'InnoDB',
                'DEFAULT CHARSET' => 'utf8mb4',
                'COLLATE'         => 'utf8mb4_unicode_ci',
            ]);
        }

        // ---------------------------------------------------------------------
        // 2. Table: special_holidays
        // ---------------------------------------------------------------------
        if (!$this->db->tableExists('special_holidays')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'holiday_date' => [
                    'type' => 'DATE',
                    'null' => false,
                ],
                'name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => false,
                ],
                'type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 50,
                    'default'    => 'special_non_working',
                    'comment'    => 'special_non_working, regular, local_suspension, institutional',
                ],
                'is_recurring' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                    'comment'    => '1 if recurring on the same month and day each year',
                ],
                'is_active' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
                ],
                'notes' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'created_at' => [
                    'type'    => 'TIMESTAMP',
                    'default' => new RawSql('CURRENT_TIMESTAMP'),
                ],
                'updated_at' => [
                    'type'    => 'TIMESTAMP',
                    'default' => new RawSql('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
                ],
            ]);

            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['holiday_date', 'is_active']);
            $this->forge->addKey('is_recurring');

            $this->forge->createTable('special_holidays', true, [
                'ENGINE'          => 'InnoDB',
                'DEFAULT CHARSET' => 'utf8mb4',
                'COLLATE'         => 'utf8mb4_unicode_ci',
            ]);
        }

        // ---------------------------------------------------------------------
        // 3. System Settings: Working Hours & Operations Configuration
        // ---------------------------------------------------------------------
        if ($this->db->tableExists('system_settings')) {
            $settings = [
                [
                    'key'         => 'work_start_time',
                    'value'       => '08:00',
                    'description' => 'Daily operational starting time (HH:mm in 24-hr format)',
                ],
                [
                    'key'         => 'work_end_time',
                    'value'       => '17:00',
                    'description' => 'Daily operational ending time (HH:mm in 24-hr format)',
                ],
                [
                    'key'         => 'lunch_start_time',
                    'value'       => '12:00',
                    'description' => 'Daily lunch break window start time (HH:mm in 24-hr format)',
                ],
                [
                    'key'         => 'lunch_end_time',
                    'value'       => '13:00',
                    'description' => 'Daily lunch break window end time (HH:mm in 24-hr format)',
                ],
                [
                    'key'         => 'standard_daily_hours',
                    'value'       => '8.0',
                    'description' => 'Standard net operational working hours per business day',
                ],
            ];

            foreach ($settings as $setting) {
                $exists = $this->db->table('system_settings')->where('key', $setting['key'])->countAllResults();
                if ($exists === 0) {
                    $this->db->table('system_settings')->insert($setting);
                }
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('catalog_materials', true);
        $this->forge->dropTable('special_holidays', true);

        if ($this->db->tableExists('system_settings')) {
            $this->db->table('system_settings')->whereIn('key', [
                'work_start_time',
                'work_end_time',
                'lunch_start_time',
                'lunch_end_time',
                'standard_daily_hours',
            ])->delete();
        }
    }
}
