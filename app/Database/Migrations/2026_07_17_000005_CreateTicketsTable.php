<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create Tickets Table (Core Ticket Base Entity)
 *
 * The central entity of the system. All unit-specific detail tables
 * (fgmu_ticket_details, leau_ticket_details, ssu_*, tasu_*) reference
 * this table via ticket_id as a 1-to-1 subtype (table inheritance pattern).
 *
 * Ticket ID format: {UNIT}-TIC-{sequence}-{year}
 *   e.g., FGMU-TIC-42-2026, SSU-TIC-7-2026
 *
 * Status/Step flow:
 *   pending (step 1) → approved (step 2) → processing (step 3) → resolved/closed (step 4)
 *   pending (step 1) → declined (step 1)
 *
 * Depends on: units (001), users (002)
 */
class CreateTicketsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                // Human-readable formatted ID: FGMU-TIC-42-2026
                'type'       => 'VARCHAR',
                'constraint' => 60,
                'null'       => false,
            ],
            'user_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => false,
            ],
            'unit_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'service_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => false,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => false,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'approved', 'processing', 'resolved', 'closed', 'declined', 'cancelled'],
                'default'    => 'pending',
                'null'       => false,
            ],
            'status_label' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'default'    => 'Pending Approval',
                'null'       => false,
            ],
            'decline_reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'current_step' => [
                // 1: Pending, 2: Queued/Approved, 3: Processing/On Route, 4: Completed
                'type'       => 'INT',
                'constraint' => 1,
                'default'    => 1,
                'null'       => false,
            ],
            'location' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'default'    => null,
            ],
            'office_room' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => null,
            ],
            'is_archived' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
            ],
            'submitted_at' => [
                'type'    => 'TIMESTAMP',
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
                'null'    => false,
            ],
            'reviewed_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'reviewed_by' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => true,
                'default'    => null,
            ],
            'completed_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'updated_at' => [
                'type'    => 'TIMESTAMP',
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
                'null'    => false,
                'extra'   => 'ON UPDATE CURRENT_TIMESTAMP',
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('user_id',                  false, false, 'idx_tickets_user');
        $this->forge->addKey(['unit_id', 'status'],      false, false, 'idx_tickets_unit_status');
        $this->forge->addKey('is_archived',              false, false, 'idx_tickets_archived');
        $this->forge->addKey('submitted_at',             false, false, 'idx_tickets_submitted');

        $this->forge->addForeignKey('user_id',     'users', 'id', 'CASCADE',  'CASCADE',  'fk_tickets_user');
        $this->forge->addForeignKey('unit_id',     'units', 'id', 'CASCADE',  'CASCADE',  'fk_tickets_unit');
        $this->forge->addForeignKey('reviewed_by', 'users', 'id', 'SET NULL', 'CASCADE',  'fk_tickets_reviewer');

        $this->forge->createTable('tickets', true, [
            'ENGINE'         => 'InnoDB',
            'DEFAULT CHARSET'=> 'utf8mb4',
            'COLLATE'        => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('tickets', true);
    }
}
