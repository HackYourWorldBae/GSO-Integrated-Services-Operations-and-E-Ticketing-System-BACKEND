<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create Feedback & Delay Reason Tables (3NF Normalized)
 *
 * ticket_feedbacks: Unified evaluation table covering all four sub-units.
 *   Five 1–5 star dimensions: courtesy, quality, efficiency, timeliness, cleanliness.
 *   One ENUM for completion status: on-time, beyond-time, not-completed.
 *
 *   3NF note: A single table (not four separate unit-specific ones) is used
 *   because the unit is already determinable via ticket_id → tickets.unit_id.
 *   Duplication is avoided and transitive dependencies are eliminated.
 *
 * feedback_delay_reasons: Lookup table of reason codes for delayed/incomplete jobs.
 *   (e.g., personnelAbsent, lackMaterials)
 *
 * ticket_feedback_delay_items: Bridge table — replaces any JSON array of reasons
 *   (1NF compliance — each reason stored as a separate row).
 *
 * Depends on: tickets (005), users (002)
 */
class CreateFeedbackAndDelayReasonTables extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------------------------
        // ticket_feedbacks
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
                'unique'     => true, // One feedback record per ticket
            ],
            'user_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => false,
            ],
            'completion_status' => [
                'type'       => 'ENUM',
                'constraint' => ['on-time', 'beyond-time', 'not-completed'],
                'null'       => false,
            ],
            'courtesy_rating' => [
                'type'       => 'TINYINT',
                'unsigned'   => true,
                'default'    => 5,
                'null'       => false,
            ],
            'quality_rating' => [
                'type'       => 'TINYINT',
                'unsigned'   => true,
                'default'    => 5,
                'null'       => false,
            ],
            'efficiency_rating' => [
                'type'       => 'TINYINT',
                'unsigned'   => true,
                'default'    => 5,
                'null'       => false,
            ],
            'timeliness_rating' => [
                'type'       => 'TINYINT',
                'unsigned'   => true,
                'default'    => 5,
                'null'       => false,
            ],
            'cleanliness_rating' => [
                'type'       => 'TINYINT',
                'unsigned'   => true,
                'default'    => 5,
                'null'       => false,
            ],
            'remarks' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type'    => 'TIMESTAMP',
                'default' => 'CURRENT_TIMESTAMP',
                'null'    => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('ticket_id');
        $this->forge->addKey('ticket_id', false, false, 'idx_feedbacks_ticket');
        $this->forge->addKey('user_id',   false, false, 'idx_feedbacks_user');

        $this->forge->addForeignKey('ticket_id', 'tickets', 'id', 'CASCADE', 'CASCADE', 'fk_feedback_ticket');
        $this->forge->addForeignKey('user_id',   'users',   'id', 'CASCADE', 'CASCADE', 'fk_feedback_user');

        $this->forge->createTable('ticket_feedbacks', true, [
            'ENGINE'         => 'InnoDB',
            'DEFAULT CHARSET'=> 'utf8mb4',
            'COLLATE'        => 'utf8mb4_unicode_ci',
        ]);

        // -------------------------------------------------------------------
        // feedback_delay_reasons (lookup)
        // -------------------------------------------------------------------
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'reason_code' => [
                // CamelCase code used by the frontend: 'personnelAbsent', 'lackMaterials'
                'type'       => 'VARCHAR',
                'constraint' => 60,
                'null'       => false,
            ],
            'reason_label' => [
                'type'       => 'VARCHAR',
                'constraint' => 200,
                'null'       => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('reason_code');

        $this->forge->createTable('feedback_delay_reasons', true, [
            'ENGINE'         => 'InnoDB',
            'DEFAULT CHARSET'=> 'utf8mb4',
            'COLLATE'        => 'utf8mb4_unicode_ci',
        ]);

        // -------------------------------------------------------------------
        // ticket_feedback_delay_items (bridge)
        // -------------------------------------------------------------------
        $this->forge->addField([
            'feedback_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'delay_reason_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
        ]);

        $this->forge->addPrimaryKey(['feedback_id', 'delay_reason_id']);
        $this->forge->addForeignKey('feedback_id',     'ticket_feedbacks',      'id', 'CASCADE', 'CASCADE', 'fk_delay_item_feedback');
        $this->forge->addForeignKey('delay_reason_id', 'feedback_delay_reasons','id', 'CASCADE', 'CASCADE', 'fk_delay_item_reason');

        $this->forge->createTable('ticket_feedback_delay_items', true, [
            'ENGINE'         => 'InnoDB',
            'DEFAULT CHARSET'=> 'utf8mb4',
            'COLLATE'        => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('ticket_feedback_delay_items', true);
        $this->forge->dropTable('feedback_delay_reasons',      true);
        $this->forge->dropTable('ticket_feedbacks',            true);
    }
}
