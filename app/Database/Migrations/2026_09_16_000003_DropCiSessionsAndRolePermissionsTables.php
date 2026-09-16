<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Drop Obsolete ci_sessions and role_permissions Tables
 *
 * Cleans up legacy tables:
 * - ci_sessions: Replaced by stateless JWT auth and user_sessions table.
 * - role_permissions: Dynamic RBAC matrix retired in favor of hardcoded role-based policies.
 */
class DropCiSessionsAndRolePermissionsTables extends Migration
{
    public function up(): void
    {
        $this->forge->dropTable('ci_sessions', true);
        $this->forge->dropTable('role_permissions', true);
    }

    public function down(): void
    {
        // Tables are permanently deprecated
    }
}
