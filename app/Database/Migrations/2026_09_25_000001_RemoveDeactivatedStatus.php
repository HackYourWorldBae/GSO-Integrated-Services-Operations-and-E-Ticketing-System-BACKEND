<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Remove 'Deactivated' from users.status ENUM — fully retired.
 * Existing 'Deactivated' accounts are migrated to 'Suspended' (nearest
 * semantic equivalent: login blocked, tickets retained). This mirrors the
 * accounts-management UI which no longer exposes Deactivated.
 */
class RemoveDeactivatedStatus extends Migration
{
    public function up(): void
    {
        // Migrate existing data before shrinking the ENUM
        $this->db->query("UPDATE `users` SET `status` = 'Suspended' WHERE `status` = 'Deactivated'");
        $this->db->query("ALTER TABLE `users` MODIFY COLUMN `status` ENUM('Active','Pending','Rejected','Suspended') NOT NULL DEFAULT 'Active'");
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE `users` MODIFY COLUMN `status` ENUM('Active','Pending','Rejected','Suspended','Deactivated') NOT NULL DEFAULT 'Active'");
    }
}
