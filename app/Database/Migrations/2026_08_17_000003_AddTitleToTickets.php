<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTitleToTickets extends Migration
{
    public function up()
    {
        // Add the 'title' column
        $this->forge->addColumn('tickets', [
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
                'default'    => 'Untitled Ticket',
                'after'      => 'unit_id',
            ],
        ]);

        // Update existing rows where title is default
        // We backfill with service_type for existing records.
        $db = \Config\Database::connect();
        $db->query("UPDATE tickets SET title = service_type");
    }

    public function down()
    {
        $this->forge->dropColumn('tickets', 'title');
    }
}
