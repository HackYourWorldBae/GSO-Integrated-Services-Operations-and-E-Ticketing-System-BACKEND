<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create ticket_required_materials table and tracking columns on tickets.
 *
 * Supports listing materials needed for a job after ocular assessment/inspection
 * when GSO consumables inventory is short or empty, and allowing the requestor
 * to mark materials as already on-hand so the request can resume approval.
 */
class CreateTicketRequiredMaterials extends Migration
{
    public function up(): void
    {
        if (!$this->db->tableExists('ticket_required_materials')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'ticket_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 60,
                    'null'       => false,
                ],
                'catalog_material_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'null'       => true,
                    'default'    => null,
                ],
                'material_name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => false,
                ],
                'quantity' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '10,2',
                    'default'    => 1.00,
                    'null'       => false,
                ],
                'unit_measurement' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 50,
                    'default'    => 'pcs',
                    'null'       => false,
                ],
                'inventory_status' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 30,
                    'default'    => 'empty',
                    'null'       => false,
                ],
                'gso_available_qty' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                    'default'    => null,
                ],
                'remarks' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 500,
                    'null'       => true,
                    'default'    => null,
                ],
                'is_requestor_provided' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                    'null'       => false,
                ],
                'provided_confirmed_at' => [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'default' => null,
                ],
                'created_by' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                    'null'       => true,
                    'default'    => null,
                ],
                'created_at' => [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'default' => null,
                ],
                'updated_at' => [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'default' => null,
                ],
            ]);

            $this->forge->addPrimaryKey('id');
            $this->forge->addKey('ticket_id', false, false, 'idx_trm_ticket');
            $this->forge->addKey('catalog_material_id', false, false, 'idx_trm_catalog');
            $this->forge->addForeignKey('ticket_id', 'tickets', 'id', 'CASCADE', 'CASCADE', 'fk_trm_ticket');

            $this->forge->createTable('ticket_required_materials', true, [
                'ENGINE'          => 'InnoDB',
                'DEFAULT CHARSET' => 'utf8mb4',
                'COLLATE'         => 'utf8mb4_unicode_ci',
            ]);
        }

        if ($this->db->tableExists('tickets')) {
            $fieldsToAdd = [];

            if (!$this->db->fieldExists('required_materials_status', 'tickets')) {
                $fieldsToAdd['required_materials_status'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 30,
                    'default'    => 'none',
                    'null'       => false,
                    'after'      => 'approval_delayed_by',
                ];
            }

            if (!$this->db->fieldExists('required_materials_notes', 'tickets')) {
                $fieldsToAdd['required_materials_notes'] = [
                    'type'    => 'TEXT',
                    'null'    => true,
                    'default' => null,
                    'after'   => 'required_materials_status',
                ];
            }

            if (!$this->db->fieldExists('required_materials_requested_at', 'tickets')) {
                $fieldsToAdd['required_materials_requested_at'] = [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'default' => null,
                    'after'   => 'required_materials_notes',
                ];
            }

            if (!$this->db->fieldExists('required_materials_requested_by', 'tickets')) {
                $fieldsToAdd['required_materials_requested_by'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                    'null'       => true,
                    'default'    => null,
                    'after'      => 'required_materials_requested_at',
                ];
            }

            if (!$this->db->fieldExists('required_materials_confirmed_at', 'tickets')) {
                $fieldsToAdd['required_materials_confirmed_at'] = [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'default' => null,
                    'after'   => 'required_materials_requested_by',
                ];
            }

            if (!$this->db->fieldExists('required_materials_confirmed_by', 'tickets')) {
                $fieldsToAdd['required_materials_confirmed_by'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                    'null'       => true,
                    'default'    => null,
                    'after'      => 'required_materials_confirmed_at',
                ];
            }

            if (!$this->db->fieldExists('requestor_materials_note', 'tickets')) {
                $fieldsToAdd['requestor_materials_note'] = [
                    'type'    => 'TEXT',
                    'null'    => true,
                    'default' => null,
                    'after'   => 'required_materials_confirmed_by',
                ];
            }

            if (!empty($fieldsToAdd)) {
                $this->forge->addColumn('tickets', $fieldsToAdd);
            }
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('ticket_required_materials')) {
            $this->forge->dropTable('ticket_required_materials', true);
        }

        if ($this->db->tableExists('tickets')) {
            $columnsToDrop = [
                'requestor_materials_note',
                'required_materials_confirmed_by',
                'required_materials_confirmed_at',
                'required_materials_requested_by',
                'required_materials_requested_at',
                'required_materials_notes',
                'required_materials_status',
            ];

            foreach ($columnsToDrop as $col) {
                if ($this->db->fieldExists($col, 'tickets')) {
                    $this->forge->dropColumn('tickets', $col);
                }
            }
        }
    }
}

