<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CreateCiSessionsTable
 *
 * Required by CodeIgniter's DatabaseHandler session driver.
 * Replaces the file-based session handler to eliminate PHP's exclusive
 * file lock per session, which caused concurrent dashboard tabs to block
 * each other and appear "broken".
 *
 * @see https://codeigniter.com/user_guide/libraries/sessions.html#databasehandler-driver
 */
class CreateCiSessionsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => false,
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => false,
            ],
            // CI4 DatabaseHandler stores a Unix timestamp (integer), not a MySQL TIMESTAMP.
            // Using INT UNSIGNED avoids MySQL strict mode rejecting a CURRENT_TIMESTAMP default.
            'timestamp' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 0,
            ],
            'data' => [
                'type' => 'BLOB',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');

        $this->forge->createTable('ci_sessions', true, [
            'ENGINE' => 'InnoDB',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('ci_sessions', true);
    }
}
