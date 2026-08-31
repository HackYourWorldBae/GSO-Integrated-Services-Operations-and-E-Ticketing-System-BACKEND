<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add Price and Ticket ID to Ticket Materials Table & Add materials_logged to Tickets Table
 *
 * Extends ticket_materials with direct ticket_id linkage, unit_price, total_price, and created_at.
 * Adds materials_logged flag to tickets table for tracking FGMU/LEAU material liquidation.
 */
class AddPriceAndTicketIdToTicketMaterials extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();

        // 1. Ensure columns exist on ticket_materials
        if ($this->db->tableExists('ticket_materials')) {
            $fields = [];
            
            if (!$this->db->fieldExists('ticket_id', 'ticket_materials')) {
                $fields['ticket_id'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 60,
                    'null'       => true,
                    'after'      => 'id',
                ];
            }

            if (!$this->db->fieldExists('unit_price', 'ticket_materials')) {
                $fields['unit_price'] = [
                    'type'       => 'DECIMAL',
                    'constraint' => '10,2',
                    'default'    => '0.00',
                    'null'       => false,
                    'after'      => 'unit_measurement',
                ];
            }

            if (!$this->db->fieldExists('total_price', 'ticket_materials')) {
                $fields['total_price'] = [
                    'type'       => 'DECIMAL',
                    'constraint' => '10,2',
                    'default'    => '0.00',
                    'null'       => false,
                    'after'      => 'unit_price',
                ];
            }

            if (!$this->db->fieldExists('created_at', 'ticket_materials')) {
                $fields['created_at'] = [
                    'type'    => 'TIMESTAMP',
                    'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
                    'null'    => false,
                    'after'   => 'total_price',
                ];
            }

            if (!empty($fields)) {
                $this->forge->addColumn('ticket_materials', $fields);
            }

            // Modify assignment_id to be nullable if direct ticket_id is supplied
            if ($this->db->fieldExists('assignment_id', 'ticket_materials')) {
                $this->forge->modifyColumn('ticket_materials', [
                    'assignment_id' => [
                        'type'       => 'INT',
                        'constraint' => 11,
                        'unsigned'   => true,
                        'null'       => true,
                    ],
                    'quantity' => [
                        'type'       => 'DECIMAL',
                        'constraint' => '10,2',
                        'default'    => '1.00',
                        'null'       => false,
                    ],
                ]);
            }
        }

        // 2. Add materials_logged to tickets table if not exists
        if ($this->db->tableExists('tickets')) {
            if (!$this->db->fieldExists('materials_logged', 'tickets')) {
                $this->forge->addColumn('tickets', [
                    'materials_logged' => [
                        'type'       => 'TINYINT',
                        'constraint' => 1,
                        'default'    => 0,
                        'null'       => false,
                        'after'      => 'is_archived',
                    ],
                ]);
            }
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('ticket_materials')) {
            if ($this->db->fieldExists('ticket_id', 'ticket_materials')) {
                $this->forge->dropColumn('ticket_materials', 'ticket_id');
            }
            if ($this->db->fieldExists('unit_price', 'ticket_materials')) {
                $this->forge->dropColumn('ticket_materials', 'unit_price');
            }
            if ($this->db->fieldExists('total_price', 'ticket_materials')) {
                $this->forge->dropColumn('ticket_materials', 'total_price');
            }
            if ($this->db->fieldExists('created_at', 'ticket_materials')) {
                $this->forge->dropColumn('ticket_materials', 'created_at');
            }
        }

        if ($this->db->tableExists('tickets')) {
            if ($this->db->fieldExists('materials_logged', 'tickets')) {
                $this->forge->dropColumn('tickets', 'materials_logged');
            }
        }
    }
}
