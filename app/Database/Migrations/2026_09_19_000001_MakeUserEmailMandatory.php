<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Make User Email Mandatory
 *
 * Reverts email column to NOT NULL because it is strictly required
 * for password recovery (Forgot Password) and system email notifications.
 */
class MakeUserEmailMandatory extends Migration
{
    public function up(): void
    {
        // Fallback for any legacy null records before altering to NOT NULL
        $this->db->query("UPDATE `users` SET `email` = CONCAT(COALESCE(`student_id_number`, REPLACE(`id`, '-', '')), '@bsu.edu.ph') WHERE `email` IS NULL OR `email` = ''");

        $this->forge->modifyColumn('users', [
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->modifyColumn('users', [
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'default'    => null,
            ],
        ]);
    }
}
