<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add 'Deactivated' value to users.status ENUM
 *
 * Expands status options so superadmins can deactivate an account (allowing login
 * but preventing service requests) distinctly from suspension (blocking login completely).
 */
class AddDeactivatedToUsersStatusEnum extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE `users` MODIFY COLUMN `status` ENUM('Active', 'Pending', 'Rejected', 'Suspended', 'Deactivated') NOT NULL DEFAULT 'Active'");
    }

    public function down(): void
    {
        // Revert any Deactivated records to Suspended before shrinking the ENUM
        $this->db->query("UPDATE `users` SET `status` = 'Suspended' WHERE `status` = 'Deactivated'");
        $this->db->query("ALTER TABLE `users` MODIFY COLUMN `status` ENUM('Active', 'Pending', 'Rejected', 'Suspended') NOT NULL DEFAULT 'Active'");
    }
}
