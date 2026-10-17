<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add staff_notation column to tickets table
 *
 * Allows staff, unit admins, and director to communicate notes, news,
 * and recommendations to the end-user requestor (similar to SSU incident report notations)
 * across all sub-units (FGMU, LEAU, SSU) for scheduled and active tickets.
 */
class AddStaffNotationToTicketsTable extends Migration
{
    public function up(): void
    {
        if (!$this->db->fieldExists('staff_notation', 'tickets')) {
            $this->forge->addColumn('tickets', [
                'staff_notation' => [
                    'type'       => 'TEXT',
                    'null'       => true,
                    'default'    => null,
                    'after'      => 'ssu_notation',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('staff_notation', 'tickets')) {
            $this->forge->dropColumn('tickets', 'staff_notation');
        }
    }
}
