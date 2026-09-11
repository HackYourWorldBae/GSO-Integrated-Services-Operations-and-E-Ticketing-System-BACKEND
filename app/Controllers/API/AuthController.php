<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Libraries\JwtService;
use App\Models\UserModel;
use App\Models\UserSessionModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * AuthController
 *
 * Handles user authentication, profile retrieval, and password changes.
 *
 * Endpoints:
 *  POST /api/v1/auth/login    - Login (student ID or email)
 *  POST /api/v1/auth/logout   - Logout (revokes active session & clears HttpOnly cookie)
 *  GET  /api/v1/auth/me       - Get authenticated user's profile
 *  PATCH /api/v1/auth/profile - Update own profile (name, contact number)
 */
class AuthController extends BaseController
{
    private UserModel $userModel;
    private UserSessionModel $userSessionModel;
    private JwtService $jwt;

    public function __construct()
    {
        $this->userModel        = new UserModel();
        $this->userSessionModel = new UserSessionModel();
        $this->jwt              = new JwtService();
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

        $errors = [];
        if (empty($firstName)) $errors['first_name'] = ['First name is required.'];
        if (empty($lastName))  $errors['last_name']  = ['Last name is required.'];
        if (!in_array($role, ['student', 'employee'], true)) {
            $errors['role'] = ['Role must be either Student or Employee.'];
        }
        if (empty($studentIdNumber)) {
            $errors['student_id_number'] = ['Employee / Student ID Number is required.'];
        }
        if (empty($contactNumber)) {
            $errors['contact_number'] = ['Contact number is required.'];
        }
        if (strlen($password) < 8) {
            $errors['password'] = ['Password must be at least 8 characters long.'];
        }

        // Email validation (optional)
        $emailToSave = null;
        if (!empty($email)) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = ['Please enter a valid email address.'];
            } else {
                $existingEmail = $this->userModel->where('email', strtolower($email))->first();
                if ($existingEmail) {
                    $errors['email'] = ['This email address is already registered.'];
                } else {
                    $emailToSave = strtolower($email);
                }
            }
        }

        // Check if student_id_number is already used
        if (!empty($studentIdNumber)) {
            $existingId = $this->userModel->where('student_id_number', $studentIdNumber)->first();
            if ($existingId) {
                $errors['student_id_number'] = ['This Employee / Student ID Number is already registered.'];
            }
        }

        // File upload verification for Employee / Student ID picture
        $file = $this->request->getFile('id_card_image');
        if (!$file || !$file->isValid() || $file->hasMoved()) {
            $errors['id_card_image'] = ['Please upload a clear picture of your Employee or Student ID.'];
        } else {
            if ($file->getSizeByUnit('mb') > 5) {
                $errors['id_card_image'] = ['The ID picture size must not exceed 5MB.'];
            }
            $ext = strtolower($file->getClientExtension());
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $errors['id_card_image'] = ['Only JPG, PNG, and WebP images are allowed for the ID picture.'];
            }
        }

        if (!empty($errors)) {
            return $this->errorResponse('Registration validation failed. Please check the form.', $errors, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Security inspection using FileSecurityService
        $securityService = new \App\Libraries\FileSecurityService();
        $inspection = $securityService->inspectFile($file->getTempName(), $file->getClientName(), $file->getClientMimeType());
        if (!$inspection['safe']) {
            return $this->errorResponse('Security check failed: ' . $inspection['reason'], ['id_card_image' => [$inspection['reason']]], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Generate UUID
        $userId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        // Move ID file to WRITEPATH uploads/id_cards/
        $uploadDir = WRITEPATH . 'uploads/id_cards/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }
        $fileName = 'id_card_' . $userId . '_' . time() . '.' . $ext;
        if (!$file->move($uploadDir, $fileName)) {
            return $this->errorResponse('Failed to save ID picture file.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $idCardRelativePath = 'id_cards/' . $fileName;

        $insertData = [
            'id'                => $userId,
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'email'             => $emailToSave,
            'password_hash'     => password_hash($password, PASSWORD_DEFAULT),
            'contact_number'    => $contactNumber,
            'student_id_number' => $studentIdNumber,
            'role'              => $role,
            'unit_id'           => null,
            'id_card_image'     => $idCardRelativePath,
            'status'            => 'Pending',
            'is_verified'       => 0, // Unverified initially
        ];

        if (!$this->userModel->skipValidation(true)->insert($insertData)) {
            $dbError = $this->userModel->db->error();
            return $this->errorResponse('Failed to register account: ' . ($dbError['message'] ?? 'Database error'), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $createdUser = $this->userModel->getSafeUser($userId);

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

        // --- Account Status Checks ---
        if ($user['status'] === 'Rejected') {
            return $this->errorResponse(
                'Your account has been rejected. Please contact the GSO office for assistance.',
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

        // --- Password Verification ---
        if (!password_verify($password, $user['password_hash'])) {
            return $this->errorResponse(
                'Invalid credentials. Please check your Email / ID Number and password.',
                [],
                ResponseInterface::HTTP_UNAUTHORIZED
            );
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
        if (!$user || $user['status'] !== 'Active') {
            return $this->errorResponse('Account is not active or no longer exists.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $role = $user['role'] ?? ($this->request->jwtPayload['role'] ?? '');
        $rolePermissionModel = new \App\Models\RolePermissionModel();
        $permissions = !empty($role) ? $rolePermissionModel->getPermissionsForRole($role) : [];

        return $this->successResponse('Session is active and valid.', [
            'valid'       => true,
            'user_id'     => $userId,
            'role'        => $role,
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
     * Stream uploaded Employee / Student ID card image for verification.
     * Accessible by Superadmin, Director, Admin, or the user themselves.
     */
    public function getIdCard(string $userId)
    {
        $user = $this->userModel->find($userId);
        if (!$user || empty($user['id_card_image'])) {
            return $this->response->setStatusCode(404)->setBody('ID card image not found.');
        }

        $relativePath = ltrim(str_replace('\\', '/', $user['id_card_image']), '/');
        if (str_starts_with($relativePath, 'uploads/')) {
            $relativePath = substr($relativePath, 8);
        }

        $fullPath = WRITEPATH . 'uploads/' . $relativePath;
        if (!is_file($fullPath)) {
            $altPath = WRITEPATH . $relativePath;
            if (is_file($altPath)) {
                $fullPath = $altPath;
            } else {
                return $this->response->setStatusCode(404)->setBody('ID card file not found on disk.');
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
}
