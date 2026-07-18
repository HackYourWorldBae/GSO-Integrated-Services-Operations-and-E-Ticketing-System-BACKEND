<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * UserModel
 *
 * Handles all authentication and profile queries for the unified `users` table.
 * Covers students, employees, admins, dispatchers, directors, workers, and drivers.
 */
class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false; // UUID primary key
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    protected $allowedFields = [
        'id',
        'first_name',
        'last_name',
        'email',
        'password_hash',
        'contact_number',
        'role',
        'unit_id',
        'student_id_number',
        'id_card_image',
        'status',
        'is_verified',
    ];

    // -------------------------------------------------------------------------
    // Validation rules
    // -------------------------------------------------------------------------
    protected $validationRules = [
        'first_name'        => 'required|max_length[100]',
        'last_name'         => 'required|max_length[100]',
        'email'             => 'required|valid_email|max_length[255]|is_unique[users.email,id,{id}]',
        'password_hash'     => 'required|min_length[8]',
        'role'              => 'required|in_list[student,employee,admin,dispatcher,director,worker,driver]',
    ];

    protected $validationMessages = [
        'email' => [
            'is_unique' => 'This email address is already registered.',
        ],
    ];

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /**
     * Find a user by student ID number.
     * Used during the login flow when the identifier is 7 digits.
     */
    public function findByStudentId(string $studentId): ?array
    {
        return $this->where('student_id_number', $studentId)
                    ->where('status', 'Active')
                    ->first();
    }

    /**
     * Find a user by email address.
     * Used for employee/admin/staff login.
     */
    public function findByEmail(string $email): ?array
    {
        return $this->where('email', $email)
                    ->where('status', 'Active')
                    ->first();
    }

    /**
     * Return a safe user payload for API responses (no password).
     */
    public function getSafeUser(string $userId): ?array
    {
        $user = $this->find($userId);

        if (!$user) {
            return null;
        }

        unset($user['password_hash']);
        return $user;
    }
}
