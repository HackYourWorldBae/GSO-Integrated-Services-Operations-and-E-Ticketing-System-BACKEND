<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create Ticket Logs Table (Audit Trail)
 *
 * Immutable audit trail recording every significant state change or
 * action performed on a ticket:
 *   - Ticket Submitted
 *   - Status Changed (pending → approved, etc.)
 *   - Worker Assigned
 *   - Assignment Updated
 *   - Materials Added
 *   - Feedback Submitted
 *
 * `user_id` is nullable (SET NULL on FK delete) to preserve historical
 * log records even if the acting user account is later removed.
 *
 * This table is append-only — no updates, no deletes except via ticket cascade.
 *
 * Depends on: tickets (005), users (002)
 */
class CreateTicketLogsTable extends Migration
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
                'constraint' => 60,
                'null'       => false,
            ],
            'user_id' => [
                // Actor who performed the action; NULL if system-generated
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => true,
                'default'    => null,
            ],
            'action' => [
                // Human-readable action label, e.g., 'Status Changed', 'Worker Assigned'
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => false,
            ],
            'details' => [
                // Optional narrative detail, e.g., 'Declined. Reason: Out of scope.'
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type'    => 'TIMESTAMP',
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
                'null'    => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('ticket_id',  false, false, 'idx_logs_ticket');
        $this->forge->addKey('created_at', false, false, 'idx_logs_created');

        $this->forge->addForeignKey('ticket_id', 'tickets', 'id', 'CASCADE',  'CASCADE',  'fk_logs_ticket');
        $this->forge->addForeignKey('user_id',   'users',   'id', 'SET NULL', 'CASCADE',  'fk_logs_user');

        $this->forge->createTable('ticket_logs', true, [
            'ENGINE'         => 'InnoDB',
            'DEFAULT CHARSET'=> 'utf8mb4',
            'COLLATE'        => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('ticket_logs', true);
    }
}
