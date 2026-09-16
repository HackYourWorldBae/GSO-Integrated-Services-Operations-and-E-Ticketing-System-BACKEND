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
        helper('sanitize');
        $passwordHash = password_hash('access', PASSWORD_DEFAULT);

        // Map units to IDs based on UnitsSeeder
        $units = [
            'FGMU' => 1,
            'LEAU' => 2,
            'SSU' => 3,
        ];

        $users = [
            [ 'email' => 'enduser@email.com', 'role' => 'student', 'first_name' => 'End User', 'last_name' => 'Test', 'unit' => null ],
            [ 'email' => 'fgmu-admin@email.com', 'role' => 'admin', 'first_name' => 'FGMU', 'last_name' => 'Admin', 'unit' => 'FGMU' ],
            [ 'email' => 'ssu-admin@email.com', 'role' => 'admin', 'first_name' => 'SSU', 'last_name' => 'Admin', 'unit' => 'SSU' ],
            [ 'email' => 'leau-admin@email.com', 'role' => 'admin', 'first_name' => 'LEAU', 'last_name' => 'Admin', 'unit' => 'LEAU' ],
            [ 'email' => 'director@email.com', 'role' => 'director', 'first_name' => 'GSO', 'last_name' => 'Director', 'unit' => null ],
            [ 'email' => 'superadmin@email.com', 'role' => 'superadmin', 'first_name' => 'Super', 'last_name' => 'Admin', 'unit' => null ],
        ];

        $data = [];
        foreach ($users as $index => $u) {
            $data[] = [
                'id' => generate_uuid(),
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
