<?php namespace App\Models;
use CodeIgniter\Model;

class StudentModel extends Model {
    protected $table = 'students';
    protected $primaryKey = 'id';
    protected $allowedFields = ['student_id_number', 'first_name', 'last_name', 'email', 'password', 'id_card_image', 'status'];
    protected $useTimestamps = true;
}