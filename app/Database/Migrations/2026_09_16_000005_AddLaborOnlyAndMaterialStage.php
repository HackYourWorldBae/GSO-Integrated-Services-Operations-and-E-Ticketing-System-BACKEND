<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add Labor Only and Material Stage Columns
 *
 * Implements the 3-stage material lifecycle:
 *  - tickets.is_labor_only: 1 when the service is labor-only (no materials required).
 *  - tickets.materials_stage: 'none', 'assessment', 'ongoing', 'finalized'
 *  - ticket_materials.stage: 'assessment', 'ongoing', 'finalized'
 */
class AddLaborOnlyAndMaterialStage extends Migration
{
    public function up(): void
    {
        // 1. Add is_labor_only and materials_stage to tickets table
        if ($this->db->tableExists('tickets')) {
            $ticketFields = [];

            if (!$this->db->fieldExists('is_labor_only', 'tickets')) {
                $ticketFields['is_labor_only'] = [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'null'       => false,
                    'default'    => 0,
                    'after'      => 'materials_logged',
                ];
            }

            if (!$this->db->fieldExists('materials_stage', 'tickets')) {
                $ticketFields['materials_stage'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'null'       => false,
                    'default'    => 'none',
                    'after'      => 'is_labor_only',
                ];
            }

            if (!empty($ticketFields)) {
                $this->forge->addColumn('tickets', $ticketFields);
            }
        }

        // 2. Add stage column to ticket_materials table
        if ($this->db->tableExists('ticket_materials')) {
            if (!$this->db->fieldExists('stage', 'ticket_materials')) {
                $this->forge->addColumn('ticket_materials', [
                    'stage' => [
                        'type'       => 'VARCHAR',
                        'constraint' => 20,
                        'null'       => false,
                        'default'    => 'assessment',
                        'after'      => 'total_price',
                    ],
                ]);
            }
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('tickets')) {
            $dropFields = [];
            if ($this->db->fieldExists('is_labor_only', 'tickets')) {
                $dropFields[] = 'is_labor_only';
            }
            if ($this->db->fieldExists('materials_stage', 'tickets')) {
                $dropFields[] = 'materials_stage';
            }
            if (!empty($dropFields)) {
                $this->forge->dropColumn('tickets', $dropFields);
            }
        }

        if ($this->db->tableExists('ticket_materials') && $this->db->fieldExists('stage', 'ticket_materials')) {
            $this->forge->dropColumn('ticket_materials', 'stage');
        }
    }
}
