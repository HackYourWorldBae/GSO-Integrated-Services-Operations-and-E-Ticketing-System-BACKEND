<?php

namespace App\Models;

use CodeIgniter\Model;

class TicketMaterialModel extends Model
{
    protected $table         = 'ticket_materials';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['assignment_id', 'material_name', 'quantity', 'unit_measurement'];
}
