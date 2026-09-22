<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Add per-account email notification opt-in for ticket/request updates.
 *
 * Internal/operational roles (admin, staff, director) can toggle whether
 * they receive request intake & ticket status emails. SSU incident alerts
 * are the critical consumer — only opted-in SSU personnel are emailed.
 * Defaults to enabled (1) so existing accounts keep current behavior.
 */
class AddEmailNotificationsEnabledToUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'email_notifications_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'unsigned'   => true,
                'null'       => false,
                'default'    => 1,
                'after'      => 'is_verified',
                'comment'    => 'Per-account opt-in for ticket/request email updates (SSU alerts, dispatch, etc.)',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', 'email_notifications_enabled');
    }
}
