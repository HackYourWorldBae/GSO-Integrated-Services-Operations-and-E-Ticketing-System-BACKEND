<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Create Vehicles Table
 *
 * Stores the full TASU fleet of university-owned vehicles.
 * Vehicle metadata (plate, model, year, fuel, category) is normalized here
 * rather than repeated inside ticket/booking records (3NF compliance).
 *
 * - `id`       : Auto-increment integer (vehicles are institutional, not UUID)
 * - `unit_id`  : FK to units — always TASU (id: 4) in normal usage
 * - `category` : ENUM controlling the vehicle type icon / filter in the frontend
 * - `status`   : Tracks real-time availability; 'in_use' set by dispatch flow
 *
 * Depends on: units (001)
 */
class CreateVehiclesTable extends Migration
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
            'unit_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'plate_no' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => false,
            ],
            'model_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => false,
            ],
            'model_year' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
                'default'    => null,
            ],
            'fuel_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'default'    => null,
            ],
            'engine_specs' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => null,
            ],
            'category' => [
                'type'       => 'ENUM',
                'constraint' => ['Van', 'Pickup', 'Bus', 'SUV', 'Logistics', 'Sedan', 'Other'],
                'default'    => 'Van',
                'null'       => false,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['available', 'in_use', 'maintenance', 'reserved'],
                'default'    => 'available',
                'null'       => false,
            ],
            'image_url' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'registered_owner' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => 'Benguet State University',
                'null'       => false,
            ],
            'created_at' => [
                'type'    => 'TIMESTAMP',
                'default' => 'CURRENT_TIMESTAMP',
                'null'    => false,
            ],
            'updated_at' => [
                'type'    => 'TIMESTAMP',
                'default' => 'CURRENT_TIMESTAMP',
                'null'    => false,
                'extra'   => 'ON UPDATE CURRENT_TIMESTAMP',
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('plate_no');
        $this->forge->addKey('status',   false, false, 'idx_vehicles_status');
        $this->forge->addKey('category', false, false, 'idx_vehicles_category');

        $this->forge->addForeignKey('unit_id', 'units', 'id', 'CASCADE', 'CASCADE', 'fk_vehicles_unit');

        $this->forge->createTable('vehicles', true, [
            'ENGINE'         => 'InnoDB',
            'DEFAULT CHARSET'=> 'utf8mb4',
            'COLLATE'        => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('vehicles', true);
    }
}
