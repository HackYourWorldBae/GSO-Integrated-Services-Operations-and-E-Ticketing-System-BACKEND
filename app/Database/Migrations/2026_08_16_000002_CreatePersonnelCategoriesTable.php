<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create Personnel Categories Table
 *
 * Stores admin-managed specialty/profession categories per unit.
 * FGMU and LEAU admins can create/delete their own categories.
 * TASU has a seeded "Driver" category that cannot be removed via the UI.
 *
 * Depends on: units (001)
 */
class CreatePersonnelCategoriesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'unit_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
            ],
            'is_system' => [
                // 1 = seeded/locked category (TASU Driver); 0 = admin-created
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
            ],
            'created_at' => [
                'type'    => 'TIMESTAMP',
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
                'null'    => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['unit_id', 'name'], 'uq_category_unit');
        $this->forge->addKey('unit_id', false, false, 'idx_categories_unit');
        $this->forge->addForeignKey('unit_id', 'units', 'id', 'CASCADE', 'CASCADE', 'fk_categories_unit');

        $this->forge->createTable('personnel_categories', true, [
            'ENGINE'          => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            'COLLATE'         => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('personnel_categories', true);
    }
}
