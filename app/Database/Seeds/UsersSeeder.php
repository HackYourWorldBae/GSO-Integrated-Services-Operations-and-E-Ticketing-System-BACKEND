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
            [
                'email' => 'enduser@email.com',
                'role' => 'employee',
                'employee_type' => 'Teaching Staff',
                'college' => 'College of Information Sciences (CIS)',
                'student_id_number' => 'EMP-2301',
                'first_name' => 'University',
                'last_name' => 'Requestor',
                'unit' => null
            ],
            [ 'email' => 'fgmu-admin@email.com', 'role' => 'admin', 'first_name' => 'FGMU', 'last_name' => 'Admin', 'unit' => 'FGMU' ],
            [ 'email' => 'ssu-admin@email.com', 'role' => 'admin', 'first_name' => 'SSU', 'last_name' => 'Admin', 'unit' => 'SSU' ],
            [ 'email' => 'leau-admin@email.com', 'role' => 'admin', 'first_name' => 'LEAU', 'last_name' => 'Admin', 'unit' => 'LEAU' ],
            [ 'email' => 'director@email.com', 'role' => 'director', 'first_name' => 'GSO', 'last_name' => 'Director', 'unit' => null ],
            [ 'email' => 'superadmin@email.com', 'role' => 'superadmin', 'first_name' => 'Super', 'last_name' => 'Admin', 'unit' => null ],
            // Personnel Bulletin Board shared logins (field workers pick their name after login)
            [ 'email' => 'fgmu-personnels@email.com', 'role' => 'employee', 'employee_type' => 'Field Personnel', 'first_name' => 'FGMU', 'last_name' => 'Personnel Board', 'unit' => 'FGMU' ],
            [ 'email' => 'leau-personnels@email.com', 'role' => 'employee', 'employee_type' => 'Field Personnel', 'first_name' => 'LEAU', 'last_name' => 'Personnel Board', 'unit' => 'LEAU' ],
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
                'employee_type' => $u['employee_type'] ?? null,
                'college' => $u['college'] ?? null,
                'student_id_number' => $u['student_id_number'] ?? null,
                'unit_id' => $u['unit'] ? $units[$u['unit']] : null,
                'status' => 'Active',
                'is_verified' => 1,
            ];
        }

        $this->db->table('users')->ignore(true)->insertBatch($data);
    }
}
