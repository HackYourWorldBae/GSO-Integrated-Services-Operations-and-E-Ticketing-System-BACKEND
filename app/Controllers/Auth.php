<?php namespace App\Controllers;
use CodeIgniter\API\ResponseTrait;
use App\Models\EmployeeModel;
use App\Models\OtpModel;
use App\Models\StudentModel;


class Auth extends BaseController {
    use ResponseTrait;

    public function login() {
        $identifier = $this->request->getVar('identifier'); // Can be Email OR Student ID
        $password   = $this->request->getVar('password');

        if (empty($identifier) || empty($password)) {
            return $this->fail('Please provide both your ID/Email and password.', 400);
        }

        // ==========================================
        // 1. CHECK STUDENT (If input is 7 digits)
        // ==========================================
        if (preg_match('/^[0-9]{7}$/', $identifier)) {
            $studentModel = new StudentModel();
            $student = $studentModel->where('student_id_number', $identifier)->first();

            if ($student) {
                // Check if admin has verified their uploaded ID
                if ($student['status'] === 'Pending') {
                    return $this->fail('Your account is still Pending. Please wait for Admin approval.', 401);
                }
                if ($student['status'] === 'Rejected') {
                    return $this->fail('Your ID verification was rejected. Please contact the GSO office.', 401);
                }
                
                // Verify Password
                if (password_verify($password, $student['password'])) {
                    unset($student['password']); // Never send password back to frontend
                    return $this->respond([
                        'message' => 'Login successful', 
                        'role'    => 'student', 
                        'user'    => $student
                    ], 200);
                }
            }
        } 
        
        // ==========================================
        // 2. CHECK EMPLOYEE & ADMIN (If input is Email)
        // ==========================================
        else if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            
            // Check Employees Table First
            $empModel = new EmployeeModel();
            $employee = $empModel->where('email', $identifier)->first();

            if ($employee) {
                if ($employee['is_verified'] == 0) {
                    return $this->fail('Please verify your email address first.', 401);
                }
                if (password_verify($password, $employee['password'])) {
                    unset($employee['password']);
                    return $this->respond([
                        'message' => 'Login successful', 
                        'role'    => 'employee', 
                        'user'    => $employee
                    ], 200);
                }
            }

            // Check Admin Table (Assuming you have an AdminModel and admins table)
            // Uncomment and adjust this once your Admin table is ready!
            /*
            $adminModel = new \App\Models\AdminModel();
            $admin = $adminModel->where('email', $identifier)->first();
            if ($admin && password_verify($password, $admin['password'])) {
                unset($admin['password']);
                return $this->respond([
                    'message' => 'Login successful', 
                    'role'    => 'admin', 
                    'user'    => $admin
                ], 200);
            }
            */
        }

        // If no matches are found or password fails
        return $this->fail('Invalid credentials. Please check your ID/Email and Password.', 401);
    }
}

