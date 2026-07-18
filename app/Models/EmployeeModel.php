<?php namespace App\Models;
use CodeIgniter\Model;

class EmployeeModel extends Model {
    protected $table = 'employees';
    protected $primaryKey = 'id';
    protected $allowedFields = ['first_name', 'last_name', 'email', 'password', 'is_verified'];
    protected $useTimestamps = true;
}