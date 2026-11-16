<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add is_under_inspection column to tickets table
 *
 * Tracks whether a newly submitted job request for FGMU / LEAU has been
 * set for on-site ocular inspection, allowing clear procedural separation
 * between "New Requests" (pending triage) and "Under Inspection".
 */
class AddIsUnderInspectionToTicketsTable extends Migration
{
    public function up(): void
    {
        if (!$this->db->fieldExists('is_under_inspection', 'tickets')) {
            $this->forge->addColumn('tickets', [
                'is_under_inspection' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                    'null'       => false,
                    'after'      => 'is_under_investigation',
                ],
            ]);

            // Add index for fast queue filtering
            $this->db->query('ALTER TABLE `tickets` ADD INDEX `idx_tickets_inspection` (`unit_id`, `is_under_inspection`, `is_archived`)');
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('is_under_inspection', 'tickets')) {
            $this->forge->dropColumn('tickets', 'is_under_inspection');
        }
    }
}
