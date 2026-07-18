<?php

namespace App\Models;

use CodeIgniter\Model;

class LeauTicketDetailModel extends Model
{
    protected $table         = 'leau_ticket_details';
    protected $primaryKey    = 'ticket_id';
    protected $useAutoIncrement = false;
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['ticket_id', 'college_building', 'office_room', 'source_of_fund'];
}
