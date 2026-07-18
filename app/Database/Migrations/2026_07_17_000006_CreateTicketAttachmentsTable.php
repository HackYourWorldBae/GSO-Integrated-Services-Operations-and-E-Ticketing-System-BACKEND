<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create Ticket Attachments Table
 *
 * Stores file attachments for tickets in an atomic, relational structure
 * (1NF compliance — replacing any prior JSON array approach).
 *
 * Files are stored on disk under /writable/uploads/tickets/{year}/{ticket_id}/
 * and this table holds the metadata records pointing to those paths.
 *
 * Depends on: tickets (005)
 */
class CreateTicketAttachmentsTable extends Migration
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
            'file_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'file_path' => [
                'type' => 'TEXT',
                'null' => false,
            ],
            'file_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => null,
            ],
            'file_size_bytes' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
                'default'  => null,
            ],
            'uploaded_at' => [
                'type'    => 'TIMESTAMP',
                'default' => 'CURRENT_TIMESTAMP',
                'null'    => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('ticket_id', false, false, 'idx_attachments_ticket');

        $this->forge->addForeignKey('ticket_id', 'tickets', 'id', 'CASCADE', 'CASCADE', 'fk_attachments_ticket');

        $this->forge->createTable('ticket_attachments', true, [
            'ENGINE'         => 'InnoDB',
            'DEFAULT CHARSET'=> 'utf8mb4',
            'COLLATE'        => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('ticket_attachments', true);
    }
}
