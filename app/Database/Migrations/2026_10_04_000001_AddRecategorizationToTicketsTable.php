<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add nature of work recategorization tracking columns to tickets table
 *
 * Supports Director changing ticket service_type / nature of work to correct
 * requester misclassifications.
 */
class AddRecategorizationToTicketsTable extends Migration
{
    public function up(): void
    {
        $fieldsToAdd = [];

        if (!$this->db->fieldExists('is_recategorized', 'tickets')) {
            $fieldsToAdd['is_recategorized'] = [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
                'after'      => 'service_type',
            ];
        }

        if (!$this->db->fieldExists('original_service_type', 'tickets')) {
            $fieldsToAdd['original_service_type'] = [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => true,
                'default'    => null,
                'after'      => 'is_recategorized',
            ];
        }

        if (!$this->db->fieldExists('recategorized_at', 'tickets')) {
            $fieldsToAdd['recategorized_at'] = [
                'type'       => 'DATETIME',
                'null'       => true,
                'default'    => null,
                'after'      => 'original_service_type',
            ];
        }

        if (!$this->db->fieldExists('recategorized_by', 'tickets')) {
            $fieldsToAdd['recategorized_by'] = [
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => true,
                'default'    => null,
                'after'      => 'recategorized_at',
            ];
        }

        if (!$this->db->fieldExists('recategorization_reason', 'tickets')) {
            $fieldsToAdd['recategorization_reason'] = [
                'type'       => 'TEXT',
                'null'       => true,
                'default'    => null,
                'after'      => 'recategorized_by',
            ];
        }

        if (!empty($fieldsToAdd)) {
            $this->forge->addColumn('tickets', $fieldsToAdd);
        }
    }

    public function down(): void
    {
        $fieldsToDrop = [
            'is_recategorized',
            'original_service_type',
            'recategorized_at',
            'recategorized_by',
            'recategorization_reason',
        ];

        foreach ($fieldsToDrop as $field) {
            if ($this->db->fieldExists($field, 'tickets')) {
                $this->forge->dropColumn('tickets', $field);
            }
        }
    }
}
