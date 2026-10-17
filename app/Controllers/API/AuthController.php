<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Libraries\JwtService;
use App\Libraries\RequestContext;
use App\Libraries\ResendEmailService;
use App\Models\UserModel;
use App\Models\UserSessionModel;
use App\Models\AccountActivityLogModel;
use App\Models\PasswordResetModel;
use App\Models\UserMfaChallengeModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * AuthController
 *
 * Handles user authentication, profile retrieval, password changes, and email recovery.
 *
 * Endpoints:
 *  POST /api/v1/auth/login            - Login (student ID or email)
 *  POST /api/v1/auth/verify-mfa       - Verify 6-digit MFA OTP code
 *  POST /api/v1/auth/resend-mfa       - Resend 6-digit MFA OTP code
 *  POST /api/v1/auth/logout           - Logout (revokes active session & clears HttpOnly cookie)
 *  GET  /api/v1/auth/me               - Get authenticated user's profile
 *  PATCH /api/v1/auth/profile         - Update own profile (name, contact number)
 *  POST /api/v1/auth/forgot-password  - Request password reset link to email
 *  POST /api/v1/auth/verify-reset-token - Validate password reset token
 *  POST /api/v1/auth/reset-password   - Reset password using secure token link
 */
class AuthController extends BaseController
{
    private UserModel $userModel;
    private UserSessionModel $userSessionModel;
    private AccountActivityLogModel $activityLogModel;
    private PasswordResetModel $passwordResetModel;
    private UserMfaChallengeModel $mfaChallengeModel;
    private ResendEmailService $emailService;
    private JwtService $jwt;

    public function __construct()
    {
        $this->userModel          = new UserModel();
        $this->userSessionModel   = new UserSessionModel();
        $this->activityLogModel   = new AccountActivityLogModel();
        $this->passwordResetModel = new PasswordResetModel();
        $this->mfaChallengeModel  = new UserMfaChallengeModel();
        $this->emailService       = new ResendEmailService();
        $this->jwt                = new JwtService();
    }


    /**
     * Register a new user account (Self-service sign up).
     *
     * Fields:
     * - first_name (required)
     * - last_name (required)
     * - role (required: student or employee)
     * - student_id_number (required)
     * - contact_number (required)
     * - email (optional, must be valid and unique if provided)
     * - password (required, min 8 chars)
     * - id_card_image (file, required, clear photo of student/employee ID)
     */
    public function register(): ResponseInterface
    {
        $firstName       = trim((string) ($this->request->getPost('first_name') ?? ''));
        $lastName        = trim((string) ($this->request->getPost('last_name') ?? ''));
        $nameExtension   = trim((string) ($this->request->getPost('name_extension') ?? ''));
        $role            = trim((string) ($this->request->getPost('role') ?? ''));
        $studentIdNumber = trim((string) ($this->request->getPost('student_id_number') ?? ''));
        $contactNumber   = trim((string) ($this->request->getPost('contact_number') ?? ''));
        $email           = trim((string) ($this->request->getPost('email') ?? ''));
        $password        = (string) ($this->request->getPost('password') ?? '');
        $passwordConfirm = (string) ($this->request->getPost('password_confirm') ?? '');
        $studentType     = strtolower(trim((string) ($this->request->getPost('student_type') ?? '')));
        $employeeType    = trim((string) ($this->request->getPost('employee_type') ?? ''));
        $organizationName = trim((string) ($this->request->getPost('organization_name') ?? ''));
        $college          = trim((string) ($this->request->getPost('college') ?? ''));

        $errors = [];

        // 1. First Name Validation
        if (empty($firstName)) {
            $errors['first_name'] = ['First name is required.'];
        } elseif (mb_strlen($firstName) < 2 || mb_strlen($firstName) > 50) {
            $errors['first_name'] = ['First name must be between 2 and 50 characters long.'];
        } elseif (!preg_match('/^[\p{L}\s\-\'.]{2,50}$/u', $firstName) || !preg_match('/[\p{L}]/u', $firstName)) {
            $errors['first_name'] = ['First name can only contain letters, spaces, hyphens, and apostrophes.'];
        }

        // 2. Last Name Validation
        if (empty($lastName)) {
            $errors['last_name'] = ['Last name is required.'];
        } elseif (mb_strlen($lastName) < 2 || mb_strlen($lastName) > 50) {
            $errors['last_name'] = ['Last name must be between 2 and 50 characters long.'];
        } elseif (!preg_match('/^[\p{L}\s\-\'.]{2,50}$/u', $lastName) || !preg_match('/[\p{L}]/u', $lastName)) {
            $errors['last_name'] = ['Last name can only contain letters, spaces, hyphens, and apostrophes.'];
        }

        // 3. Role Validation
        if (!in_array($role, ['student', 'employee'], true)) {
            $errors['role'] = ['Role must be either Student or Employee.'];
        }

        // 3.1 Student / Employee Classification Validation
        if ($role === 'student') {
            $employeeType = null;
            if (!in_array($studentType, ['rso', 'ssg'], true)) {
                $errors['student_type'] = ['Student accounts are exclusively for authorized representatives of Recognized Student Organizations (RSO) or the Supreme Student Government (SSG).'];
            } elseif ($studentType === 'rso') {
                if (empty($organizationName)) {
                    $errors['organization_name'] = ['Please specify the full name of the Recognized Student Organization (RSO).'];
                } elseif (mb_strlen($organizationName) < 2 || mb_strlen($organizationName) > 150) {
                    $errors['organization_name'] = ['Organization name must be between 2 and 150 characters long.'];
                }
            } elseif ($studentType === 'ssg') {
                if (empty($organizationName)) {
                    $errors['organization_name'] = ['Please specify your officer position.'];
                } elseif (mb_strlen($organizationName) < 2 || mb_strlen($organizationName) > 150) {
                    $errors['organization_name'] = ['Officer position must be between 2 and 150 characters long.'];
                }
            }
            if (empty($college)) {
                $errors['college'] = ['Please select the college or academic unit you belong to.'];
            } elseif (mb_strlen($college) > 150) {
                $errors['college'] = ['College name must not exceed 150 characters.'];
            }
        } elseif ($role === 'employee') {
            $studentType = null;
            $organizationName = null;

            $validEmployeeTypes = [
                'Teaching Staff',
                'Research and Extension Staff',
                'Support / Administrative Staff',
            ];

            if (empty($employeeType)) {
                $errors['employee_type'] = ['Please select your faculty or staff classification (Teaching Staff, Research and Extension Staff, or Support / Administrative Staff).'];
            } elseif (!in_array($employeeType, $validEmployeeTypes, true)) {
                $errors['employee_type'] = ['Invalid faculty or staff classification selected.'];
            } elseif ($employeeType === 'Teaching Staff') {
                if (empty($college)) {
                    $errors['college'] = ['Please select the college or academic unit you are assigned to.'];
                } elseif (mb_strlen($college) > 150) {
                    $errors['college'] = ['College name must not exceed 150 characters.'];
                }
            } elseif ($employeeType === 'Research and Extension Staff') {
                if (empty($college)) {
                    $errors['college'] = ['Please select your assigned research or extension center.'];
                } elseif (mb_strlen($college) > 150) {
                    $errors['college'] = ['Center name must not exceed 150 characters.'];
                }
            } elseif ($employeeType === 'Support / Administrative Staff') {
                if (empty($college)) {
                    $errors['college'] = ['Please select your assigned college, administrative, or support building.'];
                } elseif (mb_strlen($college) > 150) {
                    $errors['college'] = ['Facility name must not exceed 150 characters.'];
                }
            } else {
                $college = null;
            }
        } else {
            $studentType = null;
            $organizationName = null;
            $employeeType = null;
            $college = null;
        }

        // 4. Institutional ID Number (Strict 7 digits for students)
        if (empty($studentIdNumber)) {
            $errors['student_id_number'] = [$role === 'student' ? 'Student ID Number is required.' : 'Employee ID Number is required.'];
        } elseif ($role === 'student') {
            if (!preg_match('/^\d{7}$/', $studentIdNumber)) {
                $errors['student_id_number'] = ['Student ID Number must be exactly 7 numeric digits (e.g. 2301219).'];
            }
        } elseif ($role === 'employee') {
            if (!preg_match('/^[A-Za-z0-9\-]{4,15}$/', $studentIdNumber)) {
                $errors['student_id_number'] = ['Employee ID Number must be 4 to 15 alphanumeric characters (typically 6 digits, e.g. 102456).'];
            }
        }

        // 5. Contact Number (Normalize and enforce strict 11 digits starting with 09)
        $cleanContact = preg_replace('/[^\d+]/', '', $contactNumber);
        if (str_starts_with($cleanContact, '+63')) {
            $cleanContact = '0' . substr($cleanContact, 3);
        } elseif (str_starts_with($cleanContact, '63') && strlen($cleanContact) === 12) {
            $cleanContact = '0' . substr($cleanContact, 2);
        }

        if (empty($cleanContact)) {
            $errors['contact_number'] = ['Contact number is required.'];
        } elseif (!preg_match('/^09\d{9}$/', $cleanContact)) {
            $errors['contact_number'] = ['Contact number must be an 11-digit Philippine mobile number starting with 09 (e.g. 09171234567).'];
        } else {
            $contactNumber = $cleanContact;
        }

        // 6. Password Validation (Elder-friendly: min 8 chars, letters and numbers, no special symbols required)
        if (empty($password)) {
            $errors['password'] = ['Password is required.'];
        } elseif (strlen($password) < 8) {
            $errors['password'] = ['Password must be at least 8 characters long.'];
        } elseif (strlen($password) > 64) {
            $errors['password'] = ['Password must not exceed 64 characters.'];
        } elseif (!preg_match('/[a-zA-Z]/', $password)) {
            $errors['password'] = ['Password must contain at least one letter.'];
        } elseif (!preg_match('/[0-9]/', $password)) {
            $errors['password'] = ['Password must contain at least one number.'];
        }

        if (!empty($passwordConfirm) && $password !== $passwordConfirm) {
            $errors['password_confirm'] = ['Passwords do not match.'];
        }

        // Email validation (mandatory for password recovery & notifications)
        $emailToSave = null;
        if (empty($email)) {
            $errors['email'] = ['Email is required for password recovery and system notifications.'];
        } elseif (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['Please enter a valid email address (e.g. name@bsu.edu.ph).'];
        } else {
            $existingEmail = $this->userModel->where('email', strtolower($email))->first();
            if ($existingEmail) {
                $errors['email'] = ['This email address is already registered.'];
            } else {
                $emailToSave = strtolower($email);
            }
        }

        // Check if student_id_number is already used
        if (!empty($studentIdNumber) && empty($errors['student_id_number'])) {
            $existingId = $this->userModel->where('student_id_number', $studentIdNumber)->first();
            if ($existingId) {
                $errors['student_id_number'] = ['This Employee / Student ID Number is already registered.'];
            }
        }

        // Check if contact_number is already used
        if (!empty($contactNumber) && empty($errors['contact_number'])) {
            $existingContact = $this->userModel->where('contact_number', $contactNumber)->first();
            if ($existingContact) {
                $errors['contact_number'] = ['This contact number is already registered.'];
            }
        }

        // File upload verification for Employee / Student ID picture (Front ID)
        $fileFront = $this->request->getFile('id_card_image');
        if (!$fileFront || !$fileFront->isValid() || $fileFront->hasMoved()) {
            $errors['id_card_image'] = ['Please upload a clear picture of the front of your Employee or Student ID.'];
        } else {
            if ($fileFront->getSizeByUnit('mb') > 5) {
                $errors['id_card_image'] = ['The Front ID picture size must not exceed 5MB.'];
            }
            $extFront = strtolower($fileFront->getClientExtension());
            if (!in_array($extFront, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $errors['id_card_image'] = ['Only JPG, PNG, and WebP images are allowed for the Front ID picture.'];
            }
        }

        // File upload verification for Selfie while holding ID
        $fileSelfie = $this->request->getFile('id_selfie_image');
        if (!$fileSelfie || !$fileSelfie->isValid() || $fileSelfie->hasMoved()) {
            $errors['id_selfie_image'] = ['Please upload a clear selfie while holding your Employee or Student ID.'];
        } else {
            if ($fileSelfie->getSizeByUnit('mb') > 5) {
                $errors['id_selfie_image'] = ['The Selfie picture size must not exceed 5MB.'];
            }
            $extSelfie = strtolower($fileSelfie->getClientExtension());
            if (!in_array($extSelfie, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $errors['id_selfie_image'] = ['Only JPG, PNG, and WebP images are allowed for the Selfie picture.'];
            }
        }

        if (!empty($errors)) {
            return $this->errorResponse('Registration validation failed. Please check the form.', $errors, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Security inspection using FileSecurityService
        $securityService = new \App\Libraries\FileSecurityService();
        $inspectionFront = $securityService->inspectFile($fileFront->getTempName(), $fileFront->getClientName(), $fileFront->getClientMimeType());
        if (!$inspectionFront['safe']) {
            return $this->errorResponse('Security check failed: ' . $inspectionFront['reason'], ['id_card_image' => [$inspectionFront['reason']]], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $inspectionSelfie = $securityService->inspectFile($fileSelfie->getTempName(), $fileSelfie->getClientName(), $fileSelfie->getClientMimeType());
        if (!$inspectionSelfie['safe']) {
            return $this->errorResponse('Security check failed: ' . $inspectionSelfie['reason'], ['id_selfie_image' => [$inspectionSelfie['reason']]], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userId = generate_uuid();

        // Move files to WRITEPATH uploads/id_cards/
        $uploadDir = WRITEPATH . 'uploads/id_cards/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $fileNameFront = 'id_card_' . $userId . '_' . time() . '.' . $extFront;
        if (!$fileFront->move($uploadDir, $fileNameFront)) {
            return $this->errorResponse('Failed to save Front ID picture file.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $fileNameSelfie = 'id_selfie_' . $userId . '_' . time() . '.' . $extSelfie;
        if (!$fileSelfie->move($uploadDir, $fileNameSelfie)) {
            return $this->errorResponse('Failed to save Selfie with ID picture file.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $idCardRelativePath = 'id_cards/' . $fileNameFront;
        $idSelfieRelativePath = 'id_cards/' . $fileNameSelfie;

        $insertData = [
            'id'                => $userId,
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'email'             => $emailToSave,
            'password_hash'     => password_hash($password, PASSWORD_DEFAULT),
            'contact_number'    => $contactNumber,
            'student_id_number' => $studentIdNumber,
            'student_type'      => $studentType,
            'employee_type'     => $employeeType,
            'organization_name' => $organizationName,
            'college'           => $college,
            'role'              => $role,
            'unit_id'           => null,
            'id_card_image'     => $idCardRelativePath,
            'status'            => 'Pending',
            'is_verified'       => 0, // Unverified initially
            'email_notifications_enabled' => 0, // Off by default
            'is_mfa_enabled'              => 0, // Off by default
        ];

        if ($this->userModel->db->fieldExists('id_selfie_image', 'users')) {
            $insertData['id_selfie_image'] = $idSelfieRelativePath;
        }

        if (!empty($nameExtension) && $this->userModel->db->fieldExists('name_extension', 'users')) {
            $insertData['name_extension'] = sanitize_string($nameExtension);
        }

        if (!$this->userModel->skipValidation(true)->insert($insertData)) {
            $dbError = $this->userModel->db->error();
            return $this->errorResponse('Failed to register account: ' . ($dbError['message'] ?? 'Database error'), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $createdUser = $this->userModel->getSafeUser($userId);

        $activityDetails = $role === 'student'
            ? "User self-registered a new student representative account (" . strtoupper((string) $studentType) . ": {$organizationName}, College: {$college}, ID: {$studentIdNumber}). Pending ID card verification."
            : "User self-registered a new employee account ({$employeeType}" . ($college ? ", College: {$college}" : "") . ", ID: {$studentIdNumber}). Pending ID card verification.";

        $this->activityLogModel->logEvent([
            'event_type'     => 'ACCOUNT_REGISTERED',
            'severity'       => 'info',
            'actor_id'       => $userId,
            'target_user_id' => $userId,
            'details'        => $activityDetails,
            'metadata'       => [
                'role'              => $role,
                'student_id_number' => $studentIdNumber,
                'student_type'      => $studentType,
                'employee_type'     => $employeeType,
                'organization_name' => $organizationName,
                'college'           => $college,
            ],
        ]);

        // Dispatch in-app notification to active Superadmins for identity document verification
        try {
            $notificationModel = new \App\Models\NotificationModel();
            $dbConn = \Config\Database::connect();
            $superadmins = $dbConn->query("SELECT id FROM users WHERE role = 'superadmin' AND status = 'Active'")->getResultArray();
            $regFullName = trim(($createdUser['first_name'] ?? '') . ' ' . ($createdUser['last_name'] ?? ''));
            $roleLabel = ucfirst($role);
            $notifMsg = "New registration request from {$regFullName} ({$roleLabel}, ID: {$studentIdNumber}). Awaiting identity verification.";
            foreach ($superadmins as $sa) {
                $notificationModel->createNotification(
                    $sa['id'],
                    'info',
                    'New User Registration Request',
                    $notifMsg
                );
            }

            // Welcoming notification for the registrant
            $notificationModel->createNotification(
                $userId,
                'info',
                'Account Registration Received',
                'Your account registration has been submitted. Your institutional ID card is currently queued for verification by the Super Administrator.'
            );
        } catch (\Throwable $notifErr) {
            log_message('error', '[AuthController::register] Notification dispatch error: ' . $notifErr->getMessage());
        }

        return $this->successResponse(
            'Account created successfully! You can now log in to view your dashboard. Please note that an administrator must verify your ID card before you can submit service requests.',
            ['user' => $createdUser],
            ResponseInterface::HTTP_CREATED
        );
    }

    /**
     * Login
     *
     * Accepts an email address, employee/student ID, or contact number as identifier.
     * Enforces single active session per user and issues an HttpOnly cookie.
     */
    public function login(): ResponseInterface
    {
        // Parse JSON body
        $body = $this->request->getJSON(true) ?? [];

        $rules = [
            'identifier' => 'required',
            'password'   => 'required'
        ];

        if (!$this->validateData($body, $rules)) {
            return $this->errorResponse(
                'Please provide your identifier (Email, Student ID, or Employee ID) and password.',
                $this->validator->getErrors(),
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $identifier = trim((string) ($body['identifier'] ?? ''));
        $password   = (string) ($body['password'] ?? '');

        // Administrative shortcut: appends '@email.com' if mock username is entered without domain.
        $adminMockUsernames = [
            'director',
            'superadmin',
            'fgmu-admin',
            'leau-admin',
            'ssu-admin',
        ];
        if (in_array(strtolower($identifier), $adminMockUsernames, true)) {
            $identifier = strtolower($identifier) . '@email.com';
        }

        // --- Fetch User by Email, Student ID, or Contact Number ---
        $user = $this->userModel->findUserByIdentifier($identifier);

        // --- User Not Found ---
        if (!$user) {
            return $this->errorResponse(
                'Invalid credentials. Please check your Email / ID Number and password.',
                [],
                ResponseInterface::HTTP_UNAUTHORIZED
            );
        }

        // --- Maintenance Mode Check ---
        // Superadmins are exempt from maintenance lockouts so they can execute backups, restores, and recovery.
        if (($user['role'] ?? '') !== 'superadmin') {
            $systemSettingModel = new \App\Models\SystemSettingModel();
            if ($systemSettingModel->isMaintenanceActive()) {
                $maintDetails = $systemSettingModel->getMaintenanceDetails();
                return $this->errorResponse(
                    $maintDetails['message'] ?: 'The website is currently under emergency maintenance. Please try again later.',
                    [
                        'maintenance_mode'    => true,
                        'maintenance_details' => $maintDetails,
                    ],
                    ResponseInterface::HTTP_SERVICE_UNAVAILABLE
                );
            }
        }

        // --- Account Lockout Check (5 failed attempts -> 15 min lock) ---
        if (!empty($user['lockout_until'])) {
            $lockoutTime = strtotime($user['lockout_until']);
            $currentTime = time();

            if ($currentTime < $lockoutTime) {
                $remainingSeconds = $lockoutTime - $currentTime;
                $remainingMinutes = (int) ceil($remainingSeconds / 60);

                return $this->errorResponse(
                    "Account temporarily locked due to 5 consecutive failed login attempts. Please try again in {$remainingMinutes} minute(s).",
                    [
                        'is_locked'          => true,
                        'lockout_until'      => $user['lockout_until'],
                        'remaining_seconds'  => $remainingSeconds,
                        'remaining_minutes'  => $remainingMinutes,
                    ],
                    ResponseInterface::HTTP_TOO_MANY_REQUESTS
                );
            }
        }

        // --- Account Status Checks ---
        if ($user['status'] === 'Rejected') {
            return $this->errorResponse(
                'Your account has been rejected. Please contact or visit the GSO office for assistance.',
                [],
                ResponseInterface::HTTP_UNAUTHORIZED
            );
        }

        if ($user['status'] === 'Suspended') {
            return $this->errorResponse(
                'Your account has been suspended. Please contact the GSO office.',
                ['is_suspended' => true],
                ResponseInterface::HTTP_FORBIDDEN
            );
        }
        if ($user['status'] === 'Archived') {
            return $this->errorResponse(
                'Your account has been archived due to 6+ months of inactivity. Please contact the GSO Administrator to reactivate your account.',
                ['is_archived' => true],
                ResponseInterface::HTTP_FORBIDDEN
            );
        }
        // Legacy Deactivated (migrated to Suspended) — treat same as Suspended for cached JWTs
        if ($user['status'] === 'Deactivated') {
            return $this->errorResponse(
                'Your account has been suspended. Please contact the GSO office.',
                ['is_suspended' => true],
                ResponseInterface::HTTP_FORBIDDEN
            );
        }

        // --- Password Verification & Lockout Tracking ---
        if (!password_verify($password, $user['password_hash'])) {
            // If previous lockout window expired, reset attempts baseline to 0 so this starts a fresh cycle
            $hasExpiredLockout = (!empty($user['lockout_until']) && time() >= strtotime($user['lockout_until']));
            $currentAttempts   = $hasExpiredLockout ? 0 : (int) ($user['failed_login_attempts'] ?? 0);

            $lockoutResult = $this->userModel->recordFailedAttempt($user['id'], $currentAttempts, 5, 15);

            if ($lockoutResult['is_locked']) {
                $this->activityLogModel->logEvent([
                    'event_type'     => 'AUTH_LOCKOUT',
                    'severity'       => 'critical',
                    'actor_id'       => null,
                    'target_user_id' => $user['id'],
                    'details'        => "Account temporarily locked for 15 minutes due to 5 consecutive failed login attempts.",
                    'metadata'       => ['lockout_until' => $lockoutResult['lockout_until']],
                ]);

                return $this->errorResponse(
                    'Account temporarily locked for 15 minutes due to 5 consecutive failed login attempts.',
                    [
                        'is_locked'          => true,
                        'lockout_until'      => $lockoutResult['lockout_until'],
                        'remaining_seconds'  => $lockoutResult['remaining_seconds'],
                        'remaining_minutes'  => 15,
                        'remaining_attempts' => 0,
                    ],
                    ResponseInterface::HTTP_TOO_MANY_REQUESTS
                );
            }

            $remainingAttempts = $lockoutResult['remaining_attempts'];
            $attemptPlural     = $remainingAttempts === 1 ? 'attempt' : 'attempts';

            $this->activityLogModel->logEvent([
                'event_type'     => 'AUTH_LOGIN_FAILED',
                'severity'       => 'warning',
                'actor_id'       => null,
                'target_user_id' => $user['id'],
                'details'        => "Failed login attempt (Incorrect password). Remaining attempts before lockout: {$remainingAttempts}.",
                'metadata'       => ['failed_attempts' => $lockoutResult['attempts'], 'remaining_attempts' => $remainingAttempts],
            ]);

            return $this->errorResponse(
                "Invalid credentials. You have {$remainingAttempts} {$attemptPlural} remaining before your account is locked for 15 minutes.",
                [
                    'is_locked'          => false,
                    'failed_attempts'    => $lockoutResult['attempts'],
                    'remaining_attempts' => $remainingAttempts,
                ],
                ResponseInterface::HTTP_UNAUTHORIZED
            );
        }

        // --- Password is Correct: Clear Lockout & Failed Attempts ---
        if (!empty($user['failed_login_attempts']) || !empty($user['lockout_until'])) {
            $this->userModel->resetLockout($user['id']);
        }

        // --- Optional Multi-Factor Authentication (MFA) Check ---
        $isMfaEnabled = (int) ($user['is_mfa_enabled'] ?? 0) === 1;
        if ($isMfaEnabled && !empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            $ipAddress = $this->request->getIPAddress();
            $userAgent = substr($this->request->getUserAgent()->getAgentString() ?? '', 0, 255);
            $challenge = $this->mfaChallengeModel->createChallenge($user['id'], $ipAddress, $userAgent, 600);

            $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'User';
            $this->emailService->sendMfaOtpEmail($user['email'], $fullName, $challenge['otp_code'], 10);

            $this->activityLogModel->logEvent([
                'event_type'     => 'AUTH_MFA_CHALLENGE_ISSUED',
                'severity'       => 'info',
                'actor_id'       => $user['id'],
                'target_user_id' => $user['id'],
                'details'        => "Two-factor authentication code dispatched via email to user {$user['id']}.",
                'metadata'       => ['masked_email' => $this->maskEmail($user['email'])],
                'ip_address'     => $ipAddress,
                'user_agent'     => $userAgent,
            ]);

            return $this->successResponse('Multifactor authentication required. Please enter the verification code sent to your email.', [
                'mfa_required' => true,
                'mfa_token'    => $challenge['temp_token'],
                'masked_email' => $this->maskEmail($user['email']),
                'expires_in'   => 600,
            ]);
        }

        // --- Generate Unique Session ID & Enforce "One Session Per User" ---
        $sessionId = bin2hex(random_bytes(16));
        $ipAddress = $this->request->getIPAddress();
        $userAgent = substr($this->request->getUserAgent()->getAgentString() ?? '', 0, 255);
        $this->userSessionModel->registerSession($user['id'], $sessionId, $ipAddress, $userAgent);

        // --- Record Last Login Timestamp on Users Table ---
        try {
            $this->userModel->update($user['id'], [
                'last_login_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $ignored) {
        }

        // --- Build JWT Payload with sid (Session ID) ---
        $tokenPayload = [
            'id'      => $user['id'],
            'role'    => $user['role'],
            'unit_id' => $user['unit_id'],
            'sid'     => $sessionId,
        ];

        $accessToken  = $this->jwt->generateAccessToken($tokenPayload);
        $refreshToken = $this->jwt->generateRefreshToken($tokenPayload);

        $this->activityLogModel->logEvent([
            'event_type'     => 'AUTH_LOGIN_SUCCESS',
            'severity'       => 'info',
            'actor_id'       => $user['id'],
            'target_user_id' => $user['id'],
            'details'        => "User successfully authenticated. Single active session registered.",
            'metadata'       => ['role' => $user['role'], 'session_id' => $sessionId],
            'ip_address'     => $ipAddress,
            'user_agent'     => $userAgent,
        ]);

        // --- Issue HttpOnly Secure Cookie ---
        $expiresIn = $this->jwt->getExpiresIn();
        $isSecure  = (ENVIRONMENT === 'production' || $this->request->isSecure());
        $this->response->setCookie([
            'name'     => 'gso_jwt_token',
            'value'    => $accessToken,
            'expire'   => $expiresIn,
            'domain'   => '',
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Fetch safe user payload enriched with dynamic RBAC permissions and avatar URL
        $safeUser = $this->userModel->getSafeUser($user['id']);

        return $this->successResponse('Login successful.', [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $expiresIn,
            'user'          => $safeUser ?? $user,
        ]);
    }

    /**
     * Logout
     *
     * Invalidates active session in user_sessions table and clears the HttpOnly cookie.
     */
    public function logout(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId) {
            $this->userSessionModel->destroyUserSession($userId);
            $this->activityLogModel->logEvent([
                'event_type'     => 'AUTH_LOGOUT',
                'severity'       => 'info',
                'actor_id'       => $userId,
                'target_user_id' => $userId,
                'details'        => "User voluntarily logged out. Active session invalidated.",
            ]);
        }

        // Clear the HttpOnly session cookie
        $this->response->deleteCookie('gso_jwt_token', '', '/', '');

        return $this->successResponse('Logged out successfully.');
    }

    /**
     * Verify Multi-Factor Authentication (MFA) OTP code and complete login.
     * Public throttled endpoint: POST /api/v1/auth/verify-mfa
     */
    public function verifyMfa(): ResponseInterface
    {
        $body      = $this->request->getJSON(true) ?? [];
        $tempToken = trim((string) ($body['mfa_token'] ?? ''));
        $otpCode   = trim((string) ($body['otp_code'] ?? ''));

        if ($tempToken === '' || $otpCode === '') {
            return $this->errorResponse('Verification token and 6-digit code are required.', [
                'otp_code' => 'Please enter the 6-digit verification code.',
            ], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->mfaChallengeModel->verifyChallenge($tempToken, $otpCode);
        if (!$result['valid']) {
            $this->activityLogModel->logEvent([
                'event_type'     => 'AUTH_MFA_FAILED',
                'severity'       => 'warning',
                'actor_id'       => null,
                'target_user_id' => null,
                'details'        => "MFA verification failed: " . ($result['message'] ?? 'Invalid code.'),
                'metadata'       => ['remaining_attempts' => $result['remaining_attempts'] ?? null],
                'ip_address'     => $this->request->getIPAddress(),
                'user_agent'     => substr($this->request->getUserAgent()->getAgentString() ?? '', 0, 255),
            ]);

            return $this->errorResponse($result['message'], [
                'remaining_attempts' => $result['remaining_attempts'] ?? null,
            ], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $userId = $result['user_id'];
        $user   = $this->userModel->find($userId);
        if (!$user || (int) ($user['is_active'] ?? 1) === 0) {
            return $this->errorResponse('Account is deactivated or does not exist.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        // --- Generate Unique Session ID & Enforce "One Session Per User" ---
        $sessionId = bin2hex(random_bytes(16));
        $ipAddress = $this->request->getIPAddress();
        $userAgent = substr($this->request->getUserAgent()->getAgentString() ?? '', 0, 255);
        $this->userSessionModel->registerSession($user['id'], $sessionId, $ipAddress, $userAgent);

        // --- Record Last Login Timestamp on Users Table ---
        try {
            $this->userModel->update($user['id'], [
                'last_login_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $ignored) {
        }

        // --- Build JWT Payload with sid (Session ID) ---
        $tokenPayload = [
            'id'      => $user['id'],
            'role'    => $user['role'],
            'unit_id' => $user['unit_id'],
            'sid'     => $sessionId,
        ];

        $accessToken  = $this->jwt->generateAccessToken($tokenPayload);
        $refreshToken = $this->jwt->generateRefreshToken($tokenPayload);

        $this->activityLogModel->logEvent([
            'event_type'     => 'AUTH_LOGIN_SUCCESS',
            'severity'       => 'info',
            'actor_id'       => $user['id'],
            'target_user_id' => $user['id'],
            'details'        => "User successfully authenticated via Two-Factor Authentication. Single active session registered.",
            'metadata'       => ['role' => $user['role'], 'session_id' => $sessionId, 'auth_method' => 'mfa_email_otp'],
            'ip_address'     => $ipAddress,
            'user_agent'     => $userAgent,
        ]);

        // --- Issue HttpOnly Secure Cookie ---
        $expiresIn = $this->jwt->getExpiresIn();
        $isSecure  = (ENVIRONMENT === 'production' || $this->request->isSecure());
        $this->response->setCookie([
            'name'     => 'gso_jwt_token',
            'value'    => $accessToken,
            'expire'   => $expiresIn,
            'domain'   => '',
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Fetch safe user payload enriched with dynamic RBAC permissions and avatar URL
        $safeUser = $this->userModel->getSafeUser($user['id']);

        return $this->successResponse('Verification successful. Welcome back!', [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $expiresIn,
            'user'          => $safeUser ?? $user,
        ]);
    }

    /**
     * Resend Multi-Factor Authentication (MFA) OTP code to user's registered email.
     * Public throttled endpoint: POST /api/v1/auth/resend-mfa
     */
    public function resendMfa(): ResponseInterface
    {
        $body      = $this->request->getJSON(true) ?? [];
        $tempToken = trim((string) ($body['mfa_token'] ?? ''));

        if ($tempToken === '') {
            return $this->errorResponse('Verification session token is required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ipAddress = $this->request->getIPAddress();
        $userAgent = substr($this->request->getUserAgent()->getAgentString() ?? '', 0, 255);

        $resendResult = $this->mfaChallengeModel->resendChallenge($tempToken, $ipAddress, $userAgent);
        if (!$resendResult['success']) {
            return $this->errorResponse($resendResult['message'], [], ResponseInterface::HTTP_TOO_MANY_REQUESTS);
        }

        $user = $this->userModel->find($resendResult['user_id']);
        if (!$user || empty($user['email'])) {
            return $this->errorResponse('Account could not be resolved. Please log in again.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'User';
        $this->emailService->sendMfaOtpEmail($user['email'], $fullName, $resendResult['otp_code'], 10);

        $this->activityLogModel->logEvent([
            'event_type'     => 'AUTH_MFA_CHALLENGE_RESENT',
            'severity'       => 'info',
            'actor_id'       => $user['id'],
            'target_user_id' => $user['id'],
            'details'        => "Two-factor authentication code resent via email to user {$user['id']}.",
            'metadata'       => ['masked_email' => $this->maskEmail($user['email'])],
            'ip_address'     => $ipAddress,
            'user_agent'     => $userAgent,
        ]);

        return $this->successResponse('A new verification code has been dispatched to your email.', [
            'mfa_token'    => $resendResult['temp_token'],
            'masked_email' => $this->maskEmail($user['email']),
            'expires_in'   => $resendResult['expires_in'] ?? 600,
        ]);
    }

    /**
     * Helper to mask an email address for privacy (e.g., j***e@domain.com)
     */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***@***.***';
        }
        $name   = $parts[0];
        $domain = $parts[1];
        $len    = strlen($name);

        if ($len <= 2) {
            $masked = substr($name, 0, 1) . '***';
        } else {
            $masked = substr($name, 0, 1) . str_repeat('*', max(3, $len - 2)) . substr($name, -1);
        }

        return $masked . '@' . $domain;
    }

    /**
     * Get current authenticated user's profile.
     * Requires: JwtAuthFilter
     */
    public function me(): ResponseInterface
    {
        $userId = $this->currentUserId();

        if (!$userId) {
            return $this->errorResponse('Unable to resolve user identity.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $user = $this->userModel->getSafeUser($userId);

        if (!$user) {
            return $this->notFoundResponse('User');
        }

        $jwtPayload = RequestContext::getJwtPayload();
        if (!empty($jwtPayload['is_acting_head'])) {
            $user['role']             = $jwtPayload['role'] ?? 'admin';
            $user['unit_id']          = $jwtPayload['unit_id'] ?? null;
            $user['unit_code']        = $jwtPayload['acting_unit_code'] ?? null;
            $user['is_acting_head']   = true;
            $user['original_role']    = $jwtPayload['original_role'] ?? 'director';
            $user['acting_unit_code'] = $jwtPayload['acting_unit_code'] ?? null;
            $user['acting_unit_name'] = $jwtPayload['acting_unit_name'] ?? null;

            $rolePermissionModel = new \App\Models\RolePermissionModel();
            $user['permissions'] = $rolePermissionModel->getPermissionsForRole('admin');
        }

        return $this->successResponse('User profile retrieved.', ['user' => $user]);
    }

    /**
     * Check whether current session is active and valid.
     * Protected by JwtAuthFilter — if superseded, 401 SESSION_SUPERSEDED is returned automatically.
     * Also returns dynamic RBAC permissions to keep client UI synchronized.
     */
    public function checkSession(): ResponseInterface
    {
        $userId = $this->currentUserId();

        if (!$userId) {
            return $this->errorResponse('Unable to resolve user identity.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $user = $this->userModel->find($userId);
        if (!$user) {
            return $this->errorResponse('Account no longer exists.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        if ($user['status'] === 'Suspended') {
            return $this->errorResponse('Account Suspended: Your account has been suspended by the administrator.', ['is_suspended' => true], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        if ($user['status'] === 'Rejected') {
            return $this->errorResponse('Account Rejected: Your registration was rejected by the administrator.', ['is_rejected' => true], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $jwtPayload = RequestContext::getJwtPayload();
        $isActing   = !empty($jwtPayload['is_acting_head']);
        $role       = $isActing ? ($jwtPayload['role'] ?? 'admin') : ($user['role'] ?? ($this->request->jwtPayload['role'] ?? ''));

        $rolePermissionModel = new \App\Models\RolePermissionModel();
        $permissions = !empty($role) ? $rolePermissionModel->getPermissionsForRole($role) : [];

        // Deactivated or unverified pending accounts cannot create tickets
        $isVerified = (int)($user['is_verified'] ?? 0) === 1;
        if ($user['status'] === 'Deactivated' || !$isVerified) {
            $permissions = array_values(array_diff($permissions, ['tickets.create']));
        }

        $systemSettingModel = new \App\Models\SystemSettingModel();
        $isMaintenance      = $systemSettingModel->isMaintenanceActive();
        $maintDetails       = $isMaintenance ? $systemSettingModel->getMaintenanceDetails() : null;

        $responsePayload = [
            'valid'               => true,
            'user_id'             => $userId,
            'role'                => $role,
            'status'              => $user['status'],
            'is_verified'         => (int) ($user['is_verified'] ?? 0),
            'permissions'         => $permissions,
            'maintenance_mode'    => $isMaintenance,
            'maintenance_details' => $maintDetails,
        ];

        if ($isActing) {
            $responsePayload['is_acting_head']   = true;
            $responsePayload['unit_id']          = $jwtPayload['unit_id'] ?? null;
            $responsePayload['original_role']    = $jwtPayload['original_role'] ?? 'director';
            $responsePayload['acting_unit_code'] = $jwtPayload['acting_unit_code'] ?? null;
            $responsePayload['acting_unit_name'] = $jwtPayload['acting_unit_name'] ?? null;
        }

        return $this->successResponse('Session is active and valid.', $responsePayload);
    }

    // -------------------------------------------------------------------------
    // Forwarding Proxies for Decomposed Controllers (Backward Compatibility)
    // -------------------------------------------------------------------------

    public function getDelegationUnits(): ResponseInterface
    {
        return (new DirectorDelegationController())->initController($this->request, $this->response, $this->logger)->getDelegationUnits();
    }

    public function assumeUnitHead(): ResponseInterface
    {
        return (new DirectorDelegationController())->initController($this->request, $this->response, $this->logger)->assumeUnitHead();
    }

    public function revertUnitHead(): ResponseInterface
    {
        return (new DirectorDelegationController())->initController($this->request, $this->response, $this->logger)->revertUnitHead();
    }

    public function updateProfile(): ResponseInterface
    {
        return (new UserProfileController())->initController($this->request, $this->response, $this->logger)->updateProfile();
    }

    public function changePassword(): ResponseInterface
    {
        return (new UserProfileController())->initController($this->request, $this->response, $this->logger)->changePassword();
    }

    public function uploadAvatar(): ResponseInterface
    {
        return (new UserProfileController())->initController($this->request, $this->response, $this->logger)->uploadAvatar();
    }

    public function getAvatar(string $userId)
    {
        return (new UserProfileController())->initController($this->request, $this->response, $this->logger)->getAvatar($userId);
    }

    public function uploadSignature(): ResponseInterface
    {
        return (new UserProfileController())->initController($this->request, $this->response, $this->logger)->uploadSignature();
    }

    public function deleteSignature(): ResponseInterface
    {
        return (new UserProfileController())->initController($this->request, $this->response, $this->logger)->deleteSignature();
    }

    public function getSignature(string $userId)
    {
        return (new UserProfileController())->initController($this->request, $this->response, $this->logger)->getSignature($userId);
    }

    public function getDocumentSignatures(): ResponseInterface
    {
        return (new UserProfileController())->initController($this->request, $this->response, $this->logger)->getDocumentSignatures();
    }

    public function getIdCard(string $userId)
    {
        return (new UserProfileController())->initController($this->request, $this->response, $this->logger)->getIdCard($userId);
    }

    public function getIdSelfie(string $userId)
    {
        return (new UserProfileController())->initController($this->request, $this->response, $this->logger)->getIdSelfie($userId);
    }

    public function forgotPassword(): ResponseInterface
    {
        return (new PasswordRecoveryController())->initController($this->request, $this->response, $this->logger)->forgotPassword();
    }

    public function verifyResetToken(): ResponseInterface
    {
        return (new PasswordRecoveryController())->initController($this->request, $this->response, $this->logger)->verifyResetToken();
    }

    public function resetPassword(): ResponseInterface
    {
        return (new PasswordRecoveryController())->initController($this->request, $this->response, $this->logger)->resetPassword();
    }

    /**
     * Public Maintenance Mode status endpoint.
     * Used by the login page and client heartbeat when unauthenticated.
     */
    public function maintenanceStatus(): ResponseInterface
    {
        $systemSettingModel = new \App\Models\SystemSettingModel();
        $details = $systemSettingModel->getMaintenanceDetails();

        return $this->successResponse('Maintenance status retrieved.', [
            'maintenance' => $details,
        ]);
    }
}

