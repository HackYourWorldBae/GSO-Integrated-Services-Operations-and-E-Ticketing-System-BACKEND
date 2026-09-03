<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * PersonnelSeeder
 *
 * Previously seeded dummy personnel records.
 * Now intentionally empty — all personnel are created by unit admins
 * via the Personnel Management page.
 *
 * Personnel categories are seeded separately in PersonnelCategorySeeder.
 */
class PersonnelSeeder extends Seeder
{
    public function run(): void
    {
        // No personnel are seeded. Admins manage the roster via the UI.
    }
}
