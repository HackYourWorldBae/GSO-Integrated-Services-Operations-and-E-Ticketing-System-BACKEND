<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddQuantityToCatalogMaterials extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('catalog_materials')) {
            $fieldsToAdd = [];

            if (!$this->db->fieldExists('quantity_available', 'catalog_materials')) {
                $fieldsToAdd['quantity_available'] = [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'default'    => 50,
                    'after'      => 'default_price',
                ];
            }

            if (!$this->db->fieldExists('quantity_total', 'catalog_materials')) {
                $fieldsToAdd['quantity_total'] = [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'default'    => 50,
                    'after'      => 'quantity_available',
                ];
            }

            if (!empty($fieldsToAdd)) {
                $this->forge->addColumn('catalog_materials', $fieldsToAdd);
            }
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('catalog_materials')) {
            if ($this->db->fieldExists('quantity_total', 'catalog_materials')) {
                $this->forge->dropColumn('catalog_materials', 'quantity_total');
            }
            if ($this->db->fieldExists('quantity_available', 'catalog_materials')) {
                $this->forge->dropColumn('catalog_materials', 'quantity_available');
            }
        }
    }
}

