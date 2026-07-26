<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * UsersSeeder
 *
 * Seeds mock accounts for testing.
 * Password for all accounts is 'access'.
 */
class UsersSeeder extends Seeder
{
    public function run(): void
    {
        $passwordHash = password_hash('access', PASSWORD_DEFAULT);

        // Map units to IDs based on UnitsSeeder
        $units = [
            'FGMU' => 1,
            'LEAU' => 2,
            'SSU' => 3,
            'TASU' => 4,
        ];

        $users = [
            [ 'email' => 'enduser@email.com', 'role' => 'student', 'first_name' => 'End User', 'last_name' => 'Test', 'unit' => null ],
            [ 'email' => 'fgmu-admin@email.com', 'role' => 'admin', 'first_name' => 'FGMU', 'last_name' => 'Admin', 'unit' => 'FGMU' ],
            [ 'email' => 'ssu-admin@email.com', 'role' => 'admin', 'first_name' => 'SSU', 'last_name' => 'Admin', 'unit' => 'SSU' ],
            [ 'email' => 'leau-admin@email.com', 'role' => 'admin', 'first_name' => 'LEAU', 'last_name' => 'Admin', 'unit' => 'LEAU' ],
            [ 'email' => 'tasu-admin@email.com', 'role' => 'admin', 'first_name' => 'TASU', 'last_name' => 'Admin', 'unit' => 'TASU' ],
            [ 'email' => 'fgmu-dispatcher@email.com', 'role' => 'dispatcher', 'first_name' => 'FGMU', 'last_name' => 'Dispatcher', 'unit' => 'FGMU' ],

            [ 'email' => 'leau-dispatcher@email.com', 'role' => 'dispatcher', 'first_name' => 'LEAU', 'last_name' => 'Dispatcher', 'unit' => 'LEAU' ],
            [ 'email' => 'tasu-dispatcher@email.com', 'role' => 'dispatcher', 'first_name' => 'TASU', 'last_name' => 'Dispatcher', 'unit' => 'TASU' ],
            [ 'email' => 'field-worker@email.com', 'role' => 'worker', 'first_name' => 'Field', 'last_name' => 'Worker', 'unit' => 'FGMU' ],
            [ 'email' => 'driver@email.com', 'role' => 'driver', 'first_name' => 'Main', 'last_name' => 'Driver', 'unit' => 'TASU' ],
            [ 'email' => 'director@email.com', 'role' => 'director', 'first_name' => 'GSO', 'last_name' => 'Director', 'unit' => null ],
        ];

        $data = [];
        foreach ($users as $index => $u) {
            $data[] = [
                'id' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
                'first_name' => $u['first_name'],
                'last_name' => $u['last_name'],
                'email' => $u['email'],
                'password_hash' => $passwordHash,
                'role' => $u['role'],
                'unit_id' => $u['unit'] ? $units[$u['unit']] : null,
                'status' => 'Active',
                'is_verified' => 1,
                ];
        }

        $this->db->table('users')->ignore(true)->insertBatch($data);
    }
}
