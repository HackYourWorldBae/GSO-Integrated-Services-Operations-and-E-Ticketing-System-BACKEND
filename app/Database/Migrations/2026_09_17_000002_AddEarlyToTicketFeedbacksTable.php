<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add 'early' to completion_status enum in ticket_feedbacks table
 */
class AddEarlyToTicketFeedbacksTable extends Migration
{
    public function up(): void
    {
        // For MySQL / MariaDB, modify the enum column definition
        if ($this->db->tableExists('ticket_feedbacks')) {
            $this->db->query("
                ALTER TABLE `ticket_feedbacks` 
                MODIFY COLUMN `completion_status` ENUM('early', 'on-time', 'beyond-time', 'not-completed') NOT NULL DEFAULT 'on-time'
            ");
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('ticket_feedbacks')) {
            $this->db->query("
                ALTER TABLE `ticket_feedbacks` 
                MODIFY COLUMN `completion_status` ENUM('on-time', 'beyond-time', 'not-completed') NOT NULL DEFAULT 'on-time'
            ");
        }
    }
}
