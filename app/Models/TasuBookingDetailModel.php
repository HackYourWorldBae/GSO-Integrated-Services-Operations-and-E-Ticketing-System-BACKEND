<?php

namespace App\Models;

use CodeIgniter\Model;

class TasuBookingDetailModel extends Model
{
    protected $table         = 'tasu_booking_details';
    protected $primaryKey    = 'ticket_id';
    protected $useAutoIncrement = false;
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'ticket_id',
        'request_time',
        'return_time',
        'requesting_personnel',
        'office_college_department',
        'agency_address',
        'num_passengers',
        'date_of_travel',
        'destination',
        'purpose_of_travel',
        'travel_order_no',
    ];
}
