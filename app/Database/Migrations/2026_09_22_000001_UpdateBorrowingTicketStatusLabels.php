<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Update Borrowing Ticket Status Labels
 *
 * Ensures all borrowing tickets with legacy or default "Queued for Dispatch"
 * status labels are updated to "Approved - Awaiting Inventory Assignment".
 */
class UpdateBorrowingTicketStatusLabels extends Migration
{
    public function up(): void
    {
        $this->db->query("
            UPDATE tickets
            SET status_label = 'Approved - Awaiting Inventory Assignment'
            WHERE (status = 'approved' OR status_label = 'Queued for Dispatch')
              AND (
                LOWER(service_type) LIKE '%borrowing of plants%'
                OR LOWER(service_type) LIKE '%borrowing of tools%'
                OR LOWER(service_type) LIKE '%borrowing request%'
              )
        ");
    }

    public function down(): void
    {
        // Reversible if needed, but not required to revert to inaccurate label
    }
}
