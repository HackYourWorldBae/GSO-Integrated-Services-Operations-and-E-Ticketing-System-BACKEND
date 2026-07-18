<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create Ticket Assignments & Materials Tables
 *
 * ticket_assignments: Links an approved ticket to a personnel member
 *   (and optionally a vehicle for TASU trips). Created by the dispatcher.
 *
 * ticket_materials: Lists the replacement parts or materials consumed
 *   during the job, stored atomically per assignment (1NF compliance —
 *   replaces a prior JSON array of materials).
 *
 * Depends on: tickets (005), personnel (003), vehicles (004)
 */
class CreateTicketAssignmentsAndMaterialsTables extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------------------------
        // ticket_assignments
        // -------------------------------------------------------------------
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
            'personnel_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => false,
            ],
            'vehicle_id' => [
                // Only populated for TASU vehicle trip assignments
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'default'    => null,
            ],
            'implementation_date' => [
                // Scheduled date of work / trip departure date
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => null,
            ],
            'dispatcher_notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'task_notes' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'default'    => null,
            ],
            'assigned_at' => [
                'type'    => 'TIMESTAMP',
                'default' => 'CURRENT_TIMESTAMP',
                'null'    => false,
            ],
            'dispatched_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'completed_at' => [
                // Set when worker marks job done or ticket is resolved
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('ticket_id',    false, false, 'idx_assignments_ticket');
        $this->forge->addKey('personnel_id', false, false, 'idx_assignments_personnel');
        $this->forge->addKey('vehicle_id',   false, false, 'idx_assignments_vehicle');

        $this->forge->addForeignKey('ticket_id',    'tickets',   'id', 'CASCADE',  'CASCADE',  'fk_assignment_ticket');
        $this->forge->addForeignKey('personnel_id', 'personnel', 'id', 'CASCADE',  'CASCADE',  'fk_assignment_personnel');
        $this->forge->addForeignKey('vehicle_id',   'vehicles',  'id', 'SET NULL', 'CASCADE',  'fk_assignment_vehicle');

        $this->forge->createTable('ticket_assignments', true, [
            'ENGINE'         => 'InnoDB',
            'DEFAULT CHARSET'=> 'utf8mb4',
            'COLLATE'        => 'utf8mb4_unicode_ci',
        ]);

        // -------------------------------------------------------------------
        // ticket_materials
        // -------------------------------------------------------------------
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'assignment_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'material_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 200,
                'null'       => false,
            ],
            'quantity' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 1,
                'null'       => false,
            ],
            'unit_measurement' => [
                // e.g., pcs, meters, liters, kg
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'default'    => null,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('assignment_id', false, false, 'idx_materials_assignment');

        $this->forge->addForeignKey('assignment_id', 'ticket_assignments', 'id', 'CASCADE', 'CASCADE', 'fk_materials_assignment');

        $this->forge->createTable('ticket_materials', true, [
            'ENGINE'         => 'InnoDB',
            'DEFAULT CHARSET'=> 'utf8mb4',
            'COLLATE'        => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('ticket_materials',    true);
        $this->forge->dropTable('ticket_assignments',  true);
    }
}
