<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * PersonnelCategorySeeder
 *
 * Seeds baseline profession / specialty categories for operational sub-units
 * (FGMU and LEAU). System categories have `is_system = 1`.
 *
 * Run via: php spark db:seed PersonnelCategorySeeder
 */
class PersonnelCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            // FGMU (Unit ID: 1)
            [
                'unit_id'   => 1,
                'name'      => 'Carpentry',
                'is_system' => 1,
            ],
            [
                'unit_id'   => 1,
                'name'      => 'Electrical',
                'is_system' => 1,
            ],
            [
                'unit_id'   => 1,
                'name'      => 'Plumbing',
                'is_system' => 1,
            ],
            [
                'unit_id'   => 1,
                'name'      => 'Welding',
                'is_system' => 1,
            ],
            [
                'unit_id'   => 1,
                'name'      => 'Painting',
                'is_system' => 1,
            ],
            [
                'unit_id'   => 1,
                'name'      => 'Air Conditioning / HVAC',
                'is_system' => 1,
            ],

            // LEAU (Unit ID: 2)
            [
                'unit_id'   => 2,
                'name'      => 'Landscaping & Gardening',
                'is_system' => 1,
            ],
            [
                'unit_id'   => 2,
                'name'      => 'Janitorial',
                'is_system' => 1,
            ],
            [
                'unit_id'   => 2,
                'name'      => 'Disinfection / Pest Control',
                'is_system' => 1,
            ],
            [
                'unit_id'   => 2,
                'name'      => 'Grass Cutting / Groundskeeping',
                'is_system' => 1,
            ],
        ];

        // INSERT IGNORE so running the seeder repeatedly remains idempotent
        $this->db->table('personnel_categories')->ignore(true)->insertBatch($categories);
    }
}
