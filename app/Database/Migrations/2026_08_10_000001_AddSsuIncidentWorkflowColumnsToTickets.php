<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add SSU Incident Workflow Columns to Tickets Table
 *
 * Adds two new columns required by the SSU Incident Report multi-step
 * workflow introduced in August 2026:
 *
 *  - is_under_investigation  TINYINT(1) DEFAULT 0
 *      Set to 1 when an SSU admin flags a ticket for active investigation.
 *      Moves the ticket to the "Under Investigation" section of the queue.
 *      Can be toggled off without resolving the ticket.
 *
 *  - ssu_notation  TEXT NULL
 *      Stores a free-text recommendation or notation from SSU staff.
 *      This is communicated to the reporter as an extension card on their
 *      dashboard (NOT shown as a progress timeline step).
 *      A notation MUST be present before a ticket can be resolved.
 *
 * Depends on: tickets table (migration 000005)
 */
class AddSsuIncidentWorkflowColumnsToTickets extends Migration
{
    public function up(): void
    {
        $fields = [
            'is_under_investigation' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
                'after'      => 'is_archived',
            ],
            'ssu_notation' => [
                'type'       => 'TEXT',
                'null'       => true,
                'default'    => null,
                'after'      => 'is_under_investigation',
            ],
        ];

        $this->forge->addColumn('tickets', $fields);
    }

    public function down(): void
    {
        $this->forge->dropColumn('tickets', ['is_under_investigation', 'ssu_notation']);
    }
}
