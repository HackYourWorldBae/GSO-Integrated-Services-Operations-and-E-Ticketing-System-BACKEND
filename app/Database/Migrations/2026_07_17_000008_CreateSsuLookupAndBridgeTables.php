<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create SSU Lookup & Bridge Tables (3NF Normalization)
 *
 * SSU incident reports have three multi-valued attributes:
 *   1. Incident types  (e.g., Theft, Vandalism, Assault)
 *   2. Issues/information (e.g., Lost Belongings, Policy Violation)
 *   3. Reporter roles  (e.g., Victim, Eyewitness, Officer on Duty)
 *
 * Without normalization, these would be stored as comma-separated TEXT
 * columns, violating 1NF. Instead, each is a separate lookup table with
 * a bridge table linking ssu_incident_details to the allowed values.
 *
 * Lookup tables:   ssu_incident_types, ssu_incident_issues, ssu_incident_roles
 * Bridge tables:   ssu_incident_type_items, ssu_incident_issue_items, ssu_incident_role_items
 *
 * Depends on: ssu_incident_details (007)
 */
class CreateSsuLookupAndBridgeTables extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------------------------
        // Lookup: ssu_incident_types
        // -------------------------------------------------------------------
        $this->forge->addField([
            'id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'type_name' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('type_name');
        $this->forge->createTable('ssu_incident_types', true, [
            'ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci',
        ]);

        // -------------------------------------------------------------------
        // Bridge: ssu_incident_type_items  (ticket ↔ incident types)
        // -------------------------------------------------------------------
        $this->forge->addField([
            'ticket_id'        => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => false],
            'incident_type_id' => ['type' => 'INT',     'constraint' => 11, 'unsigned' => true, 'null' => false],
        ]);
        $this->forge->addPrimaryKey(['ticket_id', 'incident_type_id']);
        $this->forge->addForeignKey('ticket_id',        'ssu_incident_details', 'ticket_id', 'CASCADE', 'CASCADE', 'fk_incident_item_ticket');
        $this->forge->addForeignKey('incident_type_id', 'ssu_incident_types',   'id',        'CASCADE', 'CASCADE', 'fk_incident_item_type');
        $this->forge->createTable('ssu_incident_type_items', true, [
            'ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci',
        ]);

        // -------------------------------------------------------------------
        // Lookup: ssu_incident_issues
        // -------------------------------------------------------------------
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'issue_name' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('issue_name');
        $this->forge->createTable('ssu_incident_issues', true, [
            'ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci',
        ]);

        // -------------------------------------------------------------------
        // Bridge: ssu_incident_issue_items  (ticket ↔ incident issues)
        // -------------------------------------------------------------------
        $this->forge->addField([
            'ticket_id' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => false],
            'issue_id'  => ['type' => 'INT',     'constraint' => 11, 'unsigned' => true, 'null' => false],
        ]);
        $this->forge->addPrimaryKey(['ticket_id', 'issue_id']);
        $this->forge->addForeignKey('ticket_id', 'ssu_incident_details', 'ticket_id', 'CASCADE', 'CASCADE', 'fk_issue_item_ticket');
        $this->forge->addForeignKey('issue_id',  'ssu_incident_issues',  'id',        'CASCADE', 'CASCADE', 'fk_issue_item_issue');
        $this->forge->createTable('ssu_incident_issue_items', true, [
            'ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci',
        ]);

        // -------------------------------------------------------------------
        // Lookup: ssu_incident_roles
        // -------------------------------------------------------------------
        $this->forge->addField([
            'id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'role_name' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('role_name');
        $this->forge->createTable('ssu_incident_roles', true, [
            'ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci',
        ]);

        // -------------------------------------------------------------------
        // Bridge: ssu_incident_role_items  (ticket ↔ reporter roles)
        // -------------------------------------------------------------------
        $this->forge->addField([
            'ticket_id' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => false],
            'role_id'   => ['type' => 'INT',     'constraint' => 11, 'unsigned' => true, 'null' => false],
        ]);
        $this->forge->addPrimaryKey(['ticket_id', 'role_id']);
        $this->forge->addForeignKey('ticket_id', 'ssu_incident_details', 'ticket_id', 'CASCADE', 'CASCADE', 'fk_role_item_ticket');
        $this->forge->addForeignKey('role_id',   'ssu_incident_roles',   'id',        'CASCADE', 'CASCADE', 'fk_role_item_role');
        $this->forge->createTable('ssu_incident_role_items', true, [
            'ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('ssu_incident_role_items',  true);
        $this->forge->dropTable('ssu_incident_roles',       true);
        $this->forge->dropTable('ssu_incident_issue_items', true);
        $this->forge->dropTable('ssu_incident_issues',      true);
        $this->forge->dropTable('ssu_incident_type_items',  true);
        $this->forge->dropTable('ssu_incident_types',       true);
    }
}
