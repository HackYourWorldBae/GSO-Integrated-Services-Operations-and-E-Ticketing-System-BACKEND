<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add is_emergency flag to tickets table
 *
 * Allows the Director to classify a service request as an Emergency ticket
 * upon approval, granting preemption and urgent priority in the dispatch queue.
 */
class AddIsEmergencyToTicketsTable extends Migration
{
    public function up(): void
    {
        if (!$this->db->fieldExists('is_emergency', 'tickets')) {
            $this->forge->addColumn('tickets', [
                'is_emergency' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                    'null'       => false,
                    'after'      => 'status_label',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('is_emergency', 'tickets')) {
            $this->forge->dropColumn('tickets', 'is_emergency');
        }
    }
}
