<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create Ticket Collaborations Table
 *
 * Facilitates inter-unit collaboration when a service request/ticket
 * requires multi-unit participation, shared personnel/manpower,
 * joint task execution, and specialized equipment from other units.
 */
class CreateTicketCollaborationsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'ticket_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'requesting_unit_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'collaborating_unit_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'requested_by' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'reason' => [
                'type' => 'TEXT',
            ],
            'scope_of_work' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'accepted', 'declined', 'completed', 'cancelled'],
                'default'    => 'pending',
            ],
            'response_notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'responded_by' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'responded_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'completed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('ticket_id');
        $this->forge->addKey(['collaborating_unit_id', 'status']);
        $this->forge->addKey(['requesting_unit_id', 'status']);

        $this->forge->addForeignKey('ticket_id', 'tickets', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('requesting_unit_id', 'units', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('collaborating_unit_id', 'units', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('ticket_collaborations', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('ticket_collaborations', true);
    }
}
