<?php

namespace App\Models;

use CodeIgniter\Model;

class FgmuTicketDetailModel extends Model
{
    protected $table         = 'fgmu_ticket_details';
    protected $primaryKey    = 'ticket_id';
    protected $useAutoIncrement = false;
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['ticket_id', 'college_building', 'office_room', 'source_of_fund', 'jr_no'];
}
