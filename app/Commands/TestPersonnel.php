<?php
namespace App\Commands;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\PersonnelModel;

class TestPersonnel extends BaseCommand
{
    protected $group       = 'Testing';
    protected $name        = 'test:personnel';
    protected $description = 'Test personnel insert';

    public function run(array $params)
    {
        helper('sanitize');
        try {
            $model = new PersonnelModel();
            $id = generate_uuid();
            CLI::write("Generated UUID: " . $id);
            $res = $model->insert([
                'id'        => $id,
                'user_id'   => null,
                'unit_id'   => 4,
                'name'      => 'Test User',
                'specialty' => 'Driver',
                'status'    => 'available',
            ]);
            CLI::write("Insert Result: " . json_encode($res));
        } catch (\Exception $e) {
            CLI::write("Exception: " . $e->getMessage());
        }
    }
}
