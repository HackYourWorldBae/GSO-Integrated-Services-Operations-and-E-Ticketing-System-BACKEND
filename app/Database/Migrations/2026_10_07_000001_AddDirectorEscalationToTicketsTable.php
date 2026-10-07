<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add Director escalation tracking columns to tickets table.
 *
 * Supports the approval overhaul where routine requests are handled directly
 * by Unit Heads, and heavy or important tasks can be escalated to the Director.
 */
class AddDirectorEscalationToTicketsTable extends Migration
{
    public function up(): void
    {
        $fieldsToAdd = [];

        if (!$this->db->fieldExists('is_escalated_to_director', 'tickets')) {
            $fieldsToAdd['is_escalated_to_director'] = [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
                'after'      => 'is_approval_delayed',
            ];
        }

        if (!$this->db->fieldExists('escalation_reason', 'tickets')) {
            $fieldsToAdd['escalation_reason'] = [
                'type'       => 'TEXT',
                'null'       => true,
                'default'    => null,
                'after'      => 'is_escalated_to_director',
            ];
        }

        if (!$this->db->fieldExists('escalated_at', 'tickets')) {
            $fieldsToAdd['escalated_at'] = [
                'type'       => 'DATETIME',
                'null'       => true,
                'default'    => null,
                'after'      => 'escalation_reason',
            ];
        }

        if (!$this->db->fieldExists('escalated_by', 'tickets')) {
            $fieldsToAdd['escalated_by'] = [
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => true,
                'default'    => null,
                'after'      => 'escalated_at',
            ];
        }

        if (!empty($fieldsToAdd)) {
            $this->forge->addColumn('tickets', $fieldsToAdd);
        }
    }

    public function down(): void
    {
        $fieldsToDrop = [];

        if ($this->db->fieldExists('is_escalated_to_director', 'tickets')) {
            $fieldsToDrop[] = 'is_escalated_to_director';
        }

        if ($this->db->fieldExists('escalation_reason', 'tickets')) {
            $fieldsToDrop[] = 'escalation_reason';
        }

        if ($this->db->fieldExists('escalated_at', 'tickets')) {
            $fieldsToDrop[] = 'escalated_at';
        }

        if ($this->db->fieldExists('escalated_by', 'tickets')) {
            $fieldsToDrop[] = 'escalated_by';
        }

        if (!empty($fieldsToDrop)) {
            $this->forge->dropColumn('tickets', $fieldsToDrop);
        }
    }
}
