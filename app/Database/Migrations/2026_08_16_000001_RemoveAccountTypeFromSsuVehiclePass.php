<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RemoveAccountTypeFromSsuVehiclePass extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('account_type', 'ssu_vehicle_pass_details')) {
            $this->forge->dropColumn('ssu_vehicle_pass_details', 'account_type');
        }
    }

    public function down(): void
    {
        if (!$this->db->fieldExists('account_type', 'ssu_vehicle_pass_details')) {
            $this->forge->addColumn('ssu_vehicle_pass_details', [
                'account_type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 60,
                    'null'       => true,
                    'default'    => null,
                    'after'      => 'ticket_id'
                ]
            ]);
        }
    }
}
