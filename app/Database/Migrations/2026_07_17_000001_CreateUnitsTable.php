<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create Units Table
 *
 * The `units` table is the root reference entity. All other tables that
 * belong to a unit (users, personnel, vehicles, tickets) have a FK here.
 * Must be run FIRST so foreign keys in subsequent migrations are valid.
 *
 * Units seeded: FGMU (1), LEAU (2), SSU (3), TASU (4)
 */
class CreateUnitsTable extends Migration
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
            'code' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => false,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => false,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type'    => 'TIMESTAMP',
                'default' => 'CURRENT_TIMESTAMP',
                'null'    => false,
            ],
            'updated_at' => [
                'type'              => 'TIMESTAMP',
                'default'           => 'CURRENT_TIMESTAMP',
                'null'              => false,
                'extra'             => 'ON UPDATE CURRENT_TIMESTAMP',
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');

        $this->forge->createTable('units', true, [
            'ENGINE'         => 'InnoDB',
            'DEFAULT CHARSET'=> 'utf8mb4',
            'COLLATE'        => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('units', true);
    }
}
