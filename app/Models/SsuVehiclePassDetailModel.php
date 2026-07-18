<?php

namespace App\Models;

use CodeIgniter\Model;

class SsuVehiclePassDetailModel extends Model
{
    protected $table         = 'ssu_vehicle_pass_details';
    protected $primaryKey    = 'ticket_id';
    protected $useAutoIncrement = false;
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'ticket_id',
        'account_type',
        'applicant_name',
        'college_office',
        'contact_no',
        'driver_name',
        'driver_contact',
        'complete_address',
        'registered_owner',
        'plate_no',
        'make_series',
        'type_color',
        'id_type_no',
        'valid_until',
        'privacy_agreed',
        'disclosure_agreed',
        'applicant_signature',
        'charge_slip_no',
    ];
}
