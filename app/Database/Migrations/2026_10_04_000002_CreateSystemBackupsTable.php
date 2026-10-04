<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSystemBackupsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'file_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'file_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
            ],
            'file_size_bytes' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
                'default'    => 0,
            ],
            'backup_type' => [
                'type'       => 'ENUM',
                'constraint' => ['manual', 'scheduled'],
                'default'    => 'manual',
            ],
            'tables_included' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'google_drive_file_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'google_drive_link' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'google_drive_status' => [
                'type'       => 'ENUM',
                'constraint' => ['not_configured', 'pending', 'uploaded', 'failed'],
                'default'    => 'not_configured',
            ],
            'google_drive_error' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['completed', 'in_progress', 'failed'],
                'default'    => 'completed',
            ],
            'notes' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'created_by' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
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

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('created_at');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL');

        $this->forge->createTable('system_backups', true, [
            'ENGINE'          => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            'COLLATE'         => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('system_backups', true);
    }
}
