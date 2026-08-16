<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * PersonnelSeeder
 *
 * Previously seeded dummy FGMU/LEAU/TASU personnel records.
 * Now intentionally empty — all personnel are created by unit admins
 * via the Personnel Management page.
 *
 * Personnel categories are seeded separately in PersonnelCategorySeeder.
 */
class PersonnelSeeder extends Seeder
{
    public function run(): void
    {
        // No personnel are seeded. Admins manage the roster via the UI.
    }
}

            [
                'id'        => generate_uuid(),
                'user_id'   => null,
                'unit_id'   => 1,
                'name'      => 'John Doe',
                'specialty' => 'Technician',
                'status'    => 'available',
                'created_at'=> date('Y-m-d H:i:s'),
                'updated_at'=> date('Y-m-d H:i:s'),
            ],
            [
                'id'        => generate_uuid(),
                'user_id'   => null,
                'unit_id'   => 1,
                'name'      => 'Jane Smith',
                'specialty' => 'Electrician',
                'status'    => 'available',
                'created_at'=> date('Y-m-d H:i:s'),
                'updated_at'=> date('Y-m-d H:i:s'),
            ],
            [
                'id'        => generate_uuid(),
                'user_id'   => null,
                'unit_id'   => 1,
                'name'      => 'Mike Johnson',
                'specialty' => 'Plumber',
                'status'    => 'available',
                'created_at'=> date('Y-m-d H:i:s'),
                'updated_at'=> date('Y-m-d H:i:s'),
            ],
            [
                'id'        => generate_uuid(),
                'user_id'   => null,
                'unit_id'   => 1,
                'name'      => 'Emily Davis',
                'specialty' => 'Carpenter',
                'status'    => 'available',
                'created_at'=> date('Y-m-d H:i:s'),
                'updated_at'=> date('Y-m-d H:i:s'),
            ],
            [
                'id'        => generate_uuid(),
                'user_id'   => null,
                'unit_id'   => 1,
                'name'      => 'Chris Wilson',
                'specialty' => 'Painter',
                'status'    => 'available',
                'created_at'=> date('Y-m-d H:i:s'),
                'updated_at'=> date('Y-m-d H:i:s'),
            ],
            // LEAU (unit_id = 2)
            [
                'id'        => generate_uuid(),
                'user_id'   => null,
                'unit_id'   => 2,
                'name'      => 'Robert Green',
                'specialty' => 'Gardener',
                'status'    => 'available',
                'created_at'=> date('Y-m-d H:i:s'),
                'updated_at'=> date('Y-m-d H:i:s'),
            ],
            [
                'id'        => generate_uuid(),
                'user_id'   => null,
                'unit_id'   => 2,
                'name'      => 'Sarah Plant',
                'specialty' => 'Landscaper',
                'status'    => 'available',
                'created_at'=> date('Y-m-d H:i:s'),
                'updated_at'=> date('Y-m-d H:i:s'),
            ],
            [
                'id'        => generate_uuid(),
                'user_id'   => null,
                'unit_id'   => 2,
                'name'      => 'William Field',
                'specialty' => 'Groundskeeper',
                'status'    => 'available',
                'created_at'=> date('Y-m-d H:i:s'),
                'updated_at'=> date('Y-m-d H:i:s'),
            ],
            [
                'id'        => generate_uuid(),
                'user_id'   => null,
                'unit_id'   => 2,
                'name'      => 'Diana Rose',
                'specialty' => 'Cleaner',
                'status'    => 'available',
                'created_at'=> date('Y-m-d H:i:s'),
                'updated_at'=> date('Y-m-d H:i:s'),
            ],
            [
                'id'        => generate_uuid(),
                'user_id'   => null,
                'unit_id'   => 2,
                'name'      => 'Mark Wood',
                'specialty' => 'Tree Trimmer',
                'status'    => 'available',
                'created_at'=> date('Y-m-d H:i:s'),
                'updated_at'=> date('Y-m-d H:i:s'),
            ],
        ];

        $db->table('personnel')->insertBatch($personnel);
    }
}
