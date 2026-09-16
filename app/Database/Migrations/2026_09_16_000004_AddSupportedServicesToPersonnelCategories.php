<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add Supported Services Column to Personnel Categories Table
 *
 * Allows administrators to designate which specific unit services
 * each profession/specialty category supports, enabling automated
 * personnel filtering during ticket dispatch.
 */
class AddSupportedServicesToPersonnelCategories extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('personnel_categories') && !$this->db->fieldExists('supported_services', 'personnel_categories')) {
            $this->forge->addColumn('personnel_categories', [
                'supported_services' => [
                    'type'    => 'TEXT',
                    'null'    => true,
                    'default' => null,
                    'after'   => 'is_system',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('personnel_categories') && $this->db->fieldExists('supported_services', 'personnel_categories')) {
            $this->forge->dropColumn('personnel_categories', 'supported_services');
        }
    }
}
