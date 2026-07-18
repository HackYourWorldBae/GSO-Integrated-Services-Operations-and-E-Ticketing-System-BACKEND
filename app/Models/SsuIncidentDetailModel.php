<?php

namespace App\Models;

use CodeIgniter\Model;

class SsuIncidentDetailModel extends Model
{
    protected $table         = 'ssu_incident_details';
    protected $primaryKey    = 'ticket_id';
    protected $useAutoIncrement = false;
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'ticket_id',
        'other_incident',
        'other_information',
        'follow_up',
        'who_involved',
        'where_occurred',
        'when_occurred',
        'how_narrative',
        'reporter_name',
        'reporter_signature',
    ];
}
