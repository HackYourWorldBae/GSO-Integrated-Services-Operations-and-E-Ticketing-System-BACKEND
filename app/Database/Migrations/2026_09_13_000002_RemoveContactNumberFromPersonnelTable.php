<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Remove contact_number column from personnel table
 *
 * Streamlines the personnel schema by dropping the unused contact_number column.
 */
class RemoveContactNumberFromPersonnelTable extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('contact_number', 'personnel')) {
            $this->forge->dropColumn('personnel', 'contact_number');
        }
    }

    public function down(): void
    {
        if (!$this->db->fieldExists('contact_number', 'personnel')) {
            $this->forge->addColumn('personnel', [
                'contact_number' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'null'       => true,
                    'default'    => null,
                    'after'      => 'specialty',
                ]
            ]);
        }
    }
}
