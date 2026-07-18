<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create Unit-Specific Ticket Detail Tables
 *
 * This single migration creates all four unit sub-type tables using the
 * table inheritance pattern (1-to-1 with tickets via ticket_id PK/FK):
 *
 *   - fgmu_ticket_details   : Building, room, source of fund, JR number
 *   - leau_ticket_details   : Building, room, source of fund
 *   - ssu_vehicle_pass_details : Vehicle pass sticker application fields
 *   - ssu_incident_details  : Campus incident report narrative fields
 *   - tasu_booking_details  : Vehicle trip booking and travel details
 *
 * 3NF note: SSU incident type/issue/role multi-values are in separate
 * bridge tables handled in migration 008 (SSU Lookup & Bridge Tables).
 *
 * Depends on: tickets (005)
 */
class CreateUnitTicketDetailTables extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------------------------
        // fgmu_ticket_details
        // -------------------------------------------------------------------
        $this->forge->addField([
            'ticket_id'        => ['type' => 'VARCHAR', 'constraint' => 60],
            'college_building' => ['type' => 'VARCHAR', 'constraint' => 255],
            'office_room'      => ['type' => 'VARCHAR', 'constraint' => 100],
            'source_of_fund'   => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true, 'default' => null],
            // Job Request Number — filled by FGMU admin after approval
            'jr_no'            => ['type' => 'VARCHAR', 'constraint' => 60,  'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('ticket_id');
        $this->forge->addForeignKey('ticket_id', 'tickets', 'id', 'CASCADE', 'CASCADE', 'fk_fgmu_ticket');
        $this->forge->createTable('fgmu_ticket_details', true, [
            'ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci',
        ]);

        // -------------------------------------------------------------------
        // leau_ticket_details
        // -------------------------------------------------------------------
        $this->forge->addField([
            'ticket_id'        => ['type' => 'VARCHAR', 'constraint' => 60],
            'college_building' => ['type' => 'VARCHAR', 'constraint' => 255],
            'office_room'      => ['type' => 'VARCHAR', 'constraint' => 100],
            'source_of_fund'   => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('ticket_id');
        $this->forge->addForeignKey('ticket_id', 'tickets', 'id', 'CASCADE', 'CASCADE', 'fk_leau_ticket');
        $this->forge->createTable('leau_ticket_details', true, [
            'ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci',
        ]);

        // -------------------------------------------------------------------
        // ssu_vehicle_pass_details
        // -------------------------------------------------------------------
        $this->forge->addField([
            'ticket_id'          => ['type' => 'VARCHAR', 'constraint' => 60],
            'account_type'       => ['type' => 'VARCHAR', 'constraint' => 60],
            'applicant_name'     => ['type' => 'VARCHAR', 'constraint' => 255],
            'college_office'     => ['type' => 'VARCHAR', 'constraint' => 255],
            'contact_no'         => ['type' => 'VARCHAR', 'constraint' => 30],
            'driver_name'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'driver_contact'     => ['type' => 'VARCHAR', 'constraint' => 30,  'null' => true, 'default' => null],
            'complete_address'   => ['type' => 'TEXT'],
            'registered_owner'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'plate_no'           => ['type' => 'VARCHAR', 'constraint' => 30],
            'make_series'        => ['type' => 'VARCHAR', 'constraint' => 150],
            'type_color'         => ['type' => 'VARCHAR', 'constraint' => 100],
            'id_type_no'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'valid_until'        => ['type' => 'DATE',    'null' => true, 'default' => null],
            'privacy_agreed'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'disclosure_agreed'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'applicant_signature'=> ['type' => 'LONGTEXT', 'null' => true],
            'charge_slip_no'     => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('ticket_id');
        $this->forge->addKey('plate_no', false, false, 'idx_ssu_pass_plate');
        $this->forge->addForeignKey('ticket_id', 'tickets', 'id', 'CASCADE', 'CASCADE', 'fk_ssu_pass_ticket');
        $this->forge->createTable('ssu_vehicle_pass_details', true, [
            'ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci',
        ]);

        // -------------------------------------------------------------------
        // ssu_incident_details
        // -------------------------------------------------------------------
        $this->forge->addField([
            'ticket_id'         => ['type' => 'VARCHAR', 'constraint' => 60],
            'other_incident'    => ['type' => 'TEXT', 'null' => true],
            'other_information' => ['type' => 'TEXT', 'null' => true],
            'follow_up'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'who_involved'      => ['type' => 'TEXT', 'null' => true],
            'where_occurred'    => ['type' => 'TEXT'],
            'when_occurred'     => ['type' => 'VARCHAR', 'constraint' => 150],
            'how_narrative'     => ['type' => 'TEXT'],
            'reporter_name'     => ['type' => 'VARCHAR', 'constraint' => 255],
            'reporter_signature'=> ['type' => 'LONGTEXT', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('ticket_id');
        $this->forge->addForeignKey('ticket_id', 'tickets', 'id', 'CASCADE', 'CASCADE', 'fk_ssu_incident_ticket');
        $this->forge->createTable('ssu_incident_details', true, [
            'ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci',
        ]);

        // -------------------------------------------------------------------
        // tasu_booking_details
        // -------------------------------------------------------------------
        $this->forge->addField([
            'ticket_id'                => ['type' => 'VARCHAR', 'constraint' => 60],
            'request_time'             => ['type' => 'VARCHAR', 'constraint' => 100],
            'return_time'              => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'requesting_personnel'     => ['type' => 'VARCHAR', 'constraint' => 255],
            'office_college_department'=> ['type' => 'VARCHAR', 'constraint' => 255],
            'agency_address'           => ['type' => 'TEXT', 'null' => true],
            'num_passengers'           => ['type' => 'INT',  'constraint' => 11, 'default' => 1],
            'date_of_travel'           => ['type' => 'DATE'],
            'destination'              => ['type' => 'TEXT'],
            'purpose_of_travel'        => ['type' => 'TEXT'],
            'travel_order_no'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('ticket_id');
        $this->forge->addForeignKey('ticket_id', 'tickets', 'id', 'CASCADE', 'CASCADE', 'fk_tasu_booking_ticket');
        $this->forge->createTable('tasu_booking_details', true, [
            'ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        // Drop in reverse dependency order
        $this->forge->dropTable('tasu_booking_details',     true);
        $this->forge->dropTable('ssu_incident_details',     true);
        $this->forge->dropTable('ssu_vehicle_pass_details', true);
        $this->forge->dropTable('leau_ticket_details',      true);
        $this->forge->dropTable('fgmu_ticket_details',      true);
    }
}
