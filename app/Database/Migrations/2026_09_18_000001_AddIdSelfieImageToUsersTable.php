<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Add id_selfie_image to users Table
 *
 * Adds:
 * - `id_selfie_image`: VARCHAR(255) NULL DEFAULT NULL (Photo of user selfie holding institutional ID)
 */
class AddIdSelfieImageToUsersTable extends Migration
{
    public function up(): void
    {
        $fields = [];

        if (!$this->db->fieldExists('id_selfie_image', 'users')) {
            $fields['id_selfie_image'] = [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'default'    => null,
                'after'      => 'id_card_image',
            ];
        }

        if (!empty($fields)) {
            $this->forge->addColumn('users', $fields);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('id_selfie_image', 'users')) {
            $this->forge->dropColumn('users', 'id_selfie_image');
        }
    }
}
