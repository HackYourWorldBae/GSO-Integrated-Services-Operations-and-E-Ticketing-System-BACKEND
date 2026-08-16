<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * PersonnelCategorySeeder
 *
 * Seeds the system-defined TASU "Driver" category.
 * This category is locked (is_system = 1) and cannot be deleted via the UI.
 * FGMU and LEAU categories are created dynamically by their unit admins.
 */
class PersonnelCategorySeeder extends Seeder
{
    public function run(): void
    {
        $db = \Config\Database::connect();

        // TASU unit_id = 4 — seed a locked Driver category
        $existing = $db->table('personnel_categories')
            ->where('unit_id', 4)
            ->where('name', 'Driver')
            ->countAllResults();

        if ($existing === 0) {
            $db->table('personnel_categories')->insert([
                'unit_id'    => 4,
                'name'       => 'Driver',
                'is_system'  => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
