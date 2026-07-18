<?php namespace App\Models;
use CodeIgniter\Model;

class OtpModel extends Model {
    protected $table = 'otp_codes';
    protected $primaryKey = 'id';
    protected $allowedFields = ['email', 'code', 'expires_at', 'created_at', 'user_data'];
    protected $useTimestamps = false;
}
