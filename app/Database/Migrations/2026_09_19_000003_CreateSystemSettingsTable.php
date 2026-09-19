<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSystemSettingsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'key' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'value' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'description' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'created_at' => [
                'type'    => 'TIMESTAMP',
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
            ],
            'updated_at' => [
                'type'    => 'TIMESTAMP',
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
            ],
        ]);

        $this->forge->addPrimaryKey('key');

        $this->forge->createTable('system_settings', true, [
            'ENGINE'          => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            'COLLATE'         => 'utf8mb4_unicode_ci',
        ]);

        // Default seeds
        $this->db->table('system_settings')->ignore(true)->insertBatch([
            [
                'key'         => 'resend_api_key',
                'value'       => null,
                'description' => 'API Key from Resend.com for transactional email delivery',
            ],
            [
                'key'         => 'resend_from_email',
                'value'       => 'GSO E-Ticketing <onboarding@resend.dev>',
                'description' => 'Sender email address for outgoing system emails',
            ],
            [
                'key'         => 'resend_notifications_enabled',
                'value'       => '1',
                'description' => 'Global toggle for email notification dispatch (1 = active, 0 = paused)',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('system_settings', true);
    }
}
