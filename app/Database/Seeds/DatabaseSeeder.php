<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * DatabaseSeeder
 *
 * Master seeder that executes all reference/lookup seeders in the correct
 * dependency order.
 *
 * Run via: php spark db:seed
 *       or php spark db:seed DatabaseSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Core sub-units (FGMU, LEAU, SSU, TASU) — required first
        $this->call('UnitsSeeder');

        // 2. SSU incident lookup options (types, issues, roles)
        $this->call('SsuLookupSeeder');

        // 3. Feedback delay reason codes
        $this->call('FeedbackDelayReasonsSeeder');
        
        // 4. Mock Users for testing
        $this->call('UsersSeeder');

        // 5. Vehicles
        $this->call('VehiclesSeeder');

        // 6. Personnel
        $this->call('PersonnelSeeder');
    }
}
