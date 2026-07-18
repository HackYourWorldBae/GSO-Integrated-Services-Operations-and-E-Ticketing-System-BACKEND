<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * UnitsSeeder
 *
 * Seeds the four operational sub-units of the BSU GSO system.
 * These are static reference data — the IDs (1-4) are used as constants
 * throughout the application (controllers, models, and the frontend).
 *
 * Run via: php spark db:seed UnitsSeeder
 */
class UnitsSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            [
                'id'          => 1,
                'code'        => 'FGMU',
                'name'        => 'Facilities and Grounds Management Unit',
                'description' => 'Manages structure, finishes, utilities, mechanical, and carpentry repairs across BSU campus.',
            ],
            [
                'id'          => 2,
                'code'        => 'LEAU',
                'name'        => 'Landscape and Environment Aesthetics Unit',
                'description' => 'Responsible for campus landscaping, janitorial services, lawn mowing, and disinfection operations.',
            ],
            [
                'id'          => 3,
                'code'        => 'SSU',
                'name'        => 'Security Service Unit',
                'description' => 'Handles university security, vehicle pass sticker applications, and campus incident reporting.',
            ],
            [
                'id'          => 4,
                'code'        => 'TASU',
                'name'        => 'Transportation and Automotive Service Unit',
                'description' => 'Manages the university fleet of vehicles, driver dispatching, and official travel bookings.',
            ],
        ];

        // INSERT IGNORE so re-running the seeder is idempotent
        $this->db->table('units')->ignore(true)->insertBatch($units);
    }
}
