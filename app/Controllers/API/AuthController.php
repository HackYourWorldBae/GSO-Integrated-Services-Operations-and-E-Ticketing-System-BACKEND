<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Libraries\JwtService;
use App\Libraries\ResendEmailService;
use App\Models\UserModel;
use App\Models\UserSessionModel;
use App\Models\AccountActivityLogModel;
use App\Models\PasswordResetModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * AuthController
 *
 * Handles user authentication, profile retrieval, password changes, and email recovery.
 *
 * Endpoints:
 *  POST /api/v1/auth/login            - Login (student ID or email)
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
    private ResendEmailService $emailService;
    private JwtService $jwt;

    public function __construct()
    {
        $this->userModel          = new UserModel();
        $this->userSessionModel   = new UserSessionModel();
        $this->activityLogModel   = new AccountActivityLogModel();
        $this->passwordResetModel = new PasswordResetModel();
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
        $role            = trim((string) ($this->request->getPost('role') ?? ''));
        $studentIdNumber = trim((string) ($this->request->getPost('student_id_number') ?? ''));
        $contactNumber   = trim((string) ($this->request->getPost('contact_number') ?? ''));
        $email           = trim((string) ($this->request->getPost('email') ?? ''));
        $password        = (string) ($this->request->getPost('password') ?? '');
        $passwordConfirm = (string) ($this->request->getPost('password_confirm') ?? '');
        $studentType     = strtolower(trim((string) ($this->request->getPost('student_type') ?? '')));
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

        // 3.1 Student Classification Validation (RSO or SSG only)
        if ($role === 'student') {
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
        } else {
            $studentType = null;
            $organizationName = null;
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
                $errors['student_id_number'] = ['Employee ID Number must be 4 to 15 alphanumeric characters (e.g. EMP-9876).'];
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
            'organization_name' => $organizationName,
            'college'           => $college,
            'role'              => $role,
            'unit_id'           => null,
            'id_card_image'     => $idCardRelativePath,
            'status'            => 'Pending',
            'is_verified'       => 0, // Unverified initially
        ];

        if ($this->userModel->db->fieldExists('id_selfie_image', 'users')) {
            $insertData['id_selfie_image'] = $idSelfieRelativePath;
        }

        if (!$this->userModel->skipValidation(true)->insert($insertData)) {
            $dbError = $this->userModel->db->error();
            return $this->errorResponse('Failed to register account: ' . ($dbError['message'] ?? 'Database error'), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $createdUser = $this->userModel->getSafeUser($userId);

        $activityDetails = $role === 'student'
            ? "User self-registered a new student representative account (" . strtoupper((string) $studentType) . ": {$organizationName}, College: {$college}, ID: {$studentIdNumber}). Pending ID card verification."
            : "User self-registered a new {$role} account (ID: {$studentIdNumber}). Pending ID card verification.";

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
                'organization_name' => $organizationName,
                'college'           => $college,
            ],
        ]);

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

        // ---------------------------------------------------------------------
        // TEMPORARY: Administrative mock account shortcut for ICT mentor testing.
        // Automatically appends '@email.com' if only the username is entered.
        // Mock targets: director, superadmin, fgmu-admin, leau-admin, ssu-admin
        // TODO: Remove/disable once real institutional administrative credentials are deployed.
        // ---------------------------------------------------------------------
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
                'Account Suspended: Your account has been suspended by the administrator. Login access is disabled. Please contact the GSO office for assistance.',
                ['is_suspended' => true],
                ResponseInterface::HTTP_UNAUTHORIZED
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

        // --- Generate Unique Session ID & Enforce "One Session Per User" ---
        $sessionId = bin2hex(random_bytes(16));
        $ipAddress = $this->request->getIPAddress();
        $userAgent = substr($this->request->getUserAgent()->getAgentString() ?? '', 0, 255);
        $this->userSessionModel->registerSession($user['id'], $sessionId, $ipAddress, $userAgent);

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

        $role = $user['role'] ?? ($this->request->jwtPayload['role'] ?? '');
        $rolePermissionModel = new \App\Models\RolePermissionModel();
        $permissions = !empty($role) ? $rolePermissionModel->getPermissionsForRole($role) : [];

        // Deactivated or unverified pending accounts cannot create tickets
        $isVerified = (int)($user['is_verified'] ?? 0) === 1;
        if ($user['status'] === 'Deactivated' || !$isVerified) {
            $permissions = array_values(array_diff($permissions, ['tickets.create']));
        }

        return $this->successResponse('Session is active and valid.', [
            'valid'       => true,
            'user_id'     => $userId,
            'role'        => $role,
            'status'      => $user['status'],
            'is_verified' => (int) ($user['is_verified'] ?? 0),
            'permissions' => $permissions,
        ]);
    }

    /**
     * Update the current user's profile fields (name, contact number).
     * Requires: JwtAuthFilter
     */
    public function updateProfile(): ResponseInterface
    {
        $userId = $this->currentUserId();
        $body   = $this->request->getJSON(true) ?? [];

        $updateData = [];

        if (isset($body['first_name'])) {
            $updateData['first_name'] = sanitize_string($body['first_name']);
        }
        if (isset($body['last_name'])) {
            $updateData['last_name'] = sanitize_string($body['last_name']);
        }
        if (isset($body['contact_number'])) {
            $updateData['contact_number'] = sanitize_string($body['contact_number']);
        }

        if (empty($updateData)) {
            return $this->errorResponse('No valid fields provided to update.');
        }

        $this->userModel->update($userId, $updateData);

        $this->activityLogModel->logEvent([
            'event_type'     => 'ACCOUNT_UPDATED',
            'severity'       => 'info',
            'actor_id'       => $userId,
            'target_user_id' => $userId,
            'details'        => "User updated own profile fields: " . implode(', ', array_keys($updateData)) . ".",
            'metadata'       => array_keys($updateData),
        ]);

        $user = $this->userModel->getSafeUser($userId);

        return $this->successResponse('Profile updated successfully.', ['user' => $user]);
    }

    /**
     * Change the authenticated user's own password.
     * Requires: JwtAuthFilter
     */
    public function changePassword(): ResponseInterface
    {
        $userId = $this->currentUserId();
        $body   = $this->request->getJSON(true) ?? [];

        $currentPassword = $body['current_password'] ?? '';
        $newPassword     = $body['new_password'] ?? '';
        $confirmPassword = $body['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            return $this->errorResponse('All password fields are required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($newPassword !== $confirmPassword) {
            return $this->errorResponse('New passwords do not match.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (strlen($newPassword) < 8) {
            return $this->errorResponse('New password must be at least 8 characters long.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->userModel->find($userId);

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return $this->errorResponse('Current password is incorrect.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $this->userModel->update($userId, [
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
        ]);

        $this->activityLogModel->logEvent([
            'event_type'     => 'ACCOUNT_PASSWORD_CHANGED',
            'severity'       => 'notice',
            'actor_id'       => $userId,
            'target_user_id' => $userId,
            'details'        => "User changed own account password.",
        ]);

        return $this->successResponse('Password changed successfully.');
    }

    /**
     * Upload avatar image for authenticated user.
     * Uses FileSecurityService for magic-byte verification and malware/script scanning.
     */
    public function uploadAvatar(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            return $this->errorResponse('Unauthenticated.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $file = $this->request->getFile('avatar');
        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return $this->errorResponse('Please upload a valid image file using the "avatar" field.');
        }

        // Validate size (3MB max)
        if ($file->getSizeByUnit('mb') > 3) {
            return $this->errorResponse('Avatar size must not exceed 3MB.');
        }

        $securityService = new \App\Libraries\FileSecurityService();
        $inspection = $securityService->inspectFile($file->getTempName(), $file->getClientName(), $file->getClientMimeType());
        if (!$inspection['safe']) {
            return $this->errorResponse('Security violation: ' . $inspection['reason'], [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ext = strtolower($file->getClientExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $this->errorResponse('Only JPG, PNG, and WebP images are allowed for avatars.');
        }

        $uploadDir = WRITEPATH . 'uploads/avatars/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $fileName = 'avatar_' . $userId . '_' . time() . '.' . $ext;
        if (!$file->move($uploadDir, $fileName)) {
            return $this->errorResponse('Failed to save avatar image.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $avatarRelativePath = 'avatars/' . $fileName;
        $this->userModel->update($userId, [
            'avatar_path' => $avatarRelativePath,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $safeUser = $this->userModel->getSafeUser($userId);

        return $this->successResponse('Avatar uploaded successfully.', ['user' => $safeUser]);
    }

    /**
     * Publicly or authenticated stream avatar image.
     */
    public function getAvatar(string $userId)
    {
        $user = $this->userModel->find($userId);
        if (!$user || empty($user['avatar_path'])) {
            return $this->response->setStatusCode(404)->setBody('Avatar not found.');
        }

        $fullPath = WRITEPATH . 'uploads/' . $user['avatar_path'];
        if (!is_file($fullPath)) {
            return $this->response->setStatusCode(404)->setBody('Avatar file not found on disk.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $fullPath);
        finfo_close($finfo);

        return $this->response
            ->setHeader('Content-Type', $mime ?: 'image/jpeg')
            ->setHeader('Cache-Control', 'public, max-age=86400')
            ->setBody(file_get_contents($fullPath));
    }

    /**
     * Stream uploaded Employee / Student ID card image or Selfie with ID for verification.
     * Accessible by Superadmin, Director, Admin, or the user themselves.
     */
    public function getIdCard(string $userId)
    {
        $user = $this->userModel->find($userId);
        if (!$user) {
            return $this->response->setStatusCode(404)->setBody('User not found.');
        }

        $type = strtolower((string) ($this->request->getGet('type') ?? 'front'));
        $targetField = ($type === 'selfie') ? 'id_selfie_image' : 'id_card_image';

        if (empty($user[$targetField])) {
            return $this->response->setStatusCode(404)->setBody(($type === 'selfie' ? 'Selfie with ID' : 'Front ID card') . ' image not found.');
        }

        $relativePath = ltrim(str_replace('\\', '/', $user[$targetField]), '/');
        if (str_starts_with($relativePath, 'uploads/')) {
            $relativePath = substr($relativePath, 8);
        }

        $fullPath = WRITEPATH . 'uploads/' . $relativePath;
        if (!is_file($fullPath)) {
            $altPath = WRITEPATH . $relativePath;
            if (is_file($altPath)) {
                $fullPath = $altPath;
            } else {
                return $this->response->setStatusCode(404)->setBody('File not found on disk.');
            }
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $fullPath);
        finfo_close($finfo);

        return $this->response
            ->setHeader('Content-Type', $mime ?: 'image/jpeg')
            ->setHeader('Access-Control-Allow-Origin', '*')
            ->setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->setHeader('Access-Control-Allow-Headers', '*')
            ->setHeader('Cache-Control', 'public, max-age=86400')
            ->setBody(file_get_contents($fullPath));
    }

    /**
     * Dedicated stream endpoint for Selfie with ID.
     */
    public function getIdSelfie(string $userId)
    {
        $_GET['type'] = 'selfie';
        return $this->getIdCard($userId);
    }

    /**
     * Request a password reset link via email.
     * POST /api/v1/auth/forgot-password
     * Body: { "email": "user@bsu.edu.ph" }
     */
    public function forgotPassword(): ResponseInterface
    {
        $body  = $this->request->getJSON(true) ?? [];
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if (empty($email)) {
            return $this->errorResponse('Email address is required.', ['email' => 'Email address is required.'], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->errorResponse('Please enter a valid email address.', ['email' => 'Please enter a valid email address.'], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->userModel->where('email', $email)->first();

        // If user not found, return generic success message to prevent user enumeration
        if (!$user) {
            return $this->successResponse('If an account associated with this email exists, a password reset link has been dispatched to your inbox.');
        }

        if ($user['status'] === 'Suspended') {
            return $this->errorResponse(
                'Your account has been suspended. Password recovery is disabled. Please contact the GSO office.',
                ['is_suspended' => true],
                ResponseInterface::HTTP_FORBIDDEN
            );
        }

        // Generate cryptographically secure token (60 min expiration)
        $token = $this->passwordResetModel->generateResetToken($email, 60);

        // Build reset password link
        $frontendUrl = rtrim(env('APP_FRONTEND_URL', 'http://localhost:5173'), '/');
        $resetUrl    = "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($email);

        $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        if (empty($fullName)) {
            $fullName = 'Campus Member';
        }

        // Dispatch email via Resend
        $this->emailService->sendPasswordResetLink($email, $fullName, $resetUrl);

        $this->activityLogModel->logEvent([
            'event_type'     => 'AUTH_PASSWORD_RESET_REQUESTED',
            'severity'       => 'info',
            'actor_id'       => null,
            'target_user_id' => $user['id'],
            'details'        => "Password reset link requested for email: {$email}.",
            'metadata'       => ['email' => $email],
        ]);

        return $this->successResponse('If an account associated with this email exists, a password reset link has been dispatched to your inbox.');
    }

    /**
     * Validate a password reset token before displaying the reset password view.
     * POST /api/v1/auth/verify-reset-token
     * Body: { "email": "...", "token": "..." }
     */
    public function verifyResetToken(): ResponseInterface
    {
        $body  = $this->request->getJSON(true) ?? [];
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $token = trim((string) ($body['token'] ?? ''));

        if (empty($email) || empty($token)) {
            return $this->errorResponse('Email and reset token are required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $isValid = $this->passwordResetModel->verifyResetToken($email, $token);
        if (!$isValid) {
            return $this->errorResponse(
                'This password reset link is invalid or has expired. Please submit a new password reset request.',
                ['is_expired' => true],
                ResponseInterface::HTTP_GONE
            );
        }

        $user = $this->userModel->where('email', $email)->first();
        if (!$user) {
            return $this->errorResponse('User account not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

        return $this->successResponse('Reset token is valid.', [
            'email' => $email,
            'name'  => $fullName,
            'role'  => $user['role'],
        ]);
    }

    /**
     * Reset account password using verified token link.
     * POST /api/v1/auth/reset-password
     * Body: { "email": "...", "token": "...", "password": "...", "password_confirm": "..." }
     */
    public function resetPassword(): ResponseInterface
    {
        $body            = $this->request->getJSON(true) ?? [];
        $email           = strtolower(trim((string) ($body['email'] ?? '')));
        $token           = trim((string) ($body['token'] ?? ''));
        $password        = (string) ($body['password'] ?? '');
        $passwordConfirm = (string) ($body['password_confirm'] ?? '');

        $errors = [];

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }

        if (empty($token)) {
            $errors['token'] = 'Reset token is required.';
        }

        if (empty($password)) {
            $errors['password'] = 'New password is required.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters long.';
        }

        if ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'Password confirmation does not match.';
        }

        if (!empty($errors)) {
            return $this->errorResponse('Validation failed.', $errors, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Verify token
        if (!$this->passwordResetModel->verifyResetToken($email, $token)) {
            return $this->errorResponse(
                'This password reset link is invalid or has expired. Please submit a new request.',
                ['is_expired' => true],
                ResponseInterface::HTTP_GONE
            );
        }

        $user = $this->userModel->where('email', $email)->first();
        if (!$user) {
            return $this->errorResponse('User account not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        // Update password, clear failed attempts and lockout
        $this->userModel->update($user['id'], [
            'password_hash'         => password_hash($password, PASSWORD_BCRYPT),
            'failed_login_attempts' => 0,
            'lockout_until'         => null,
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        // Clear token so it cannot be re-used
        $this->passwordResetModel->clearResetToken($email);

        // Invalidate active sessions to force re-login with new password
        $this->userSessionModel->where('user_id', $user['id'])->delete();

        // Send confirmation email
        $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        $this->emailService->sendPasswordResetSuccess($email, $fullName);

        $this->activityLogModel->logEvent([
            'event_type'     => 'AUTH_PASSWORD_RESET_COMPLETED',
            'severity'       => 'notice',
            'actor_id'       => $user['id'],
            'target_user_id' => $user['id'],
            'details'        => "User successfully reset account password via secure email link.",
            'metadata'       => ['email' => $email],
        ]);

        return $this->successResponse('Password reset successfully. You can now log in with your new password.');
    }
}

