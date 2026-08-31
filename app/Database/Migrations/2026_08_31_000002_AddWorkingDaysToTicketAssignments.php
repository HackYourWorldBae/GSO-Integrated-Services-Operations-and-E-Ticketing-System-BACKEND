<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add working_days to ticket_assignments table.
 */
class AddWorkingDaysToTicketAssignments extends Migration
{
    public function up(): void
    {
        $fields = [
            'working_days' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'default'    => null,
                'after'      => 'implementation_date',
            ],
        ];

        if (!$this->db->fieldExists('working_days', 'ticket_assignments')) {
            $this->forge->addColumn('ticket_assignments', $fields);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('working_days', 'ticket_assignments')) {
            $this->forge->dropColumn('ticket_assignments', 'working_days');
        }
    }
}
