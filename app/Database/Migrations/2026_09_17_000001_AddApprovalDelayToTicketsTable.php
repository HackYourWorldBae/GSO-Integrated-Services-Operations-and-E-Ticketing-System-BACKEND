<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add approval delay tracking columns to tickets table
 *
 * Supports moving tickets from the general approval queue to an "Approval Delayed"
 * state when waiting for materials procurement, site inspections, or administrative clearances.
 */
class AddApprovalDelayToTicketsTable extends Migration
{
    public function up(): void
    {
        $fieldsToAdd = [];

        if (!$this->db->fieldExists('is_approval_delayed', 'tickets')) {
            $fieldsToAdd['is_approval_delayed'] = [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
                'after'      => 'status_label',
            ];
        }

        if (!$this->db->fieldExists('approval_delay_reason', 'tickets')) {
            $fieldsToAdd['approval_delay_reason'] = [
                'type'       => 'TEXT',
                'null'       => true,
                'default'    => null,
                'after'      => 'is_approval_delayed',
            ];
        }

        if (!$this->db->fieldExists('approval_delayed_at', 'tickets')) {
            $fieldsToAdd['approval_delayed_at'] = [
                'type'       => 'DATETIME',
                'null'       => true,
                'default'    => null,
                'after'      => 'approval_delay_reason',
            ];
        }

        if (!$this->db->fieldExists('approval_delayed_by', 'tickets')) {
            $fieldsToAdd['approval_delayed_by'] = [
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => true,
                'default'    => null,
                'after'      => 'approval_delayed_at',
            ];
        }

        if (!empty($fieldsToAdd)) {
            $this->forge->addColumn('tickets', $fieldsToAdd);
        }
    }

    public function down(): void
    {
        $columnsToDrop = [
            'approval_delayed_by',
            'approval_delayed_at',
            'approval_delay_reason',
            'is_approval_delayed',
        ];

        foreach ($columnsToDrop as $col) {
            if ($this->db->fieldExists($col, 'tickets')) {
                $this->forge->dropColumn('tickets', $col);
            }
        }
    }
}
