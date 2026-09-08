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
     * Login
     *
     * Accepts an email address as identifier.
     * Enforces single active session per user and issues an HttpOnly cookie.
     */
    public function login(): ResponseInterface
    {
        // Parse JSON body
        $body = $this->request->getJSON(true) ?? [];

        $rules = [
            'identifier' => 'required|valid_email',
            'password'   => 'required'
        ];

        if (!$this->validateData($body, $rules)) {
            return $this->errorResponse(
                'Please provide a valid Email and password.',
                $this->validator->getErrors(),
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $identifier = $body['identifier'];
        $password   = $body['password'];

        // --- Fetch User ---
        $user = $this->userModel->findByEmail($identifier);

        // --- User Not Found ---
        if (!$user) {
            return $this->errorResponse(
                'Invalid credentials. Please check your Email and password.',
                [],
                ResponseInterface::HTTP_UNAUTHORIZED
            );
        }

        // --- Account Status Checks ---
        if ($user['status'] === 'Pending') {
            return $this->errorResponse(
                'Your account is pending review. Please wait for administrator approval.',
                [],
                ResponseInterface::HTTP_UNAUTHORIZED
            );
        }

        if ($user['status'] === 'Rejected') {
            return $this->errorResponse(
                'Your account has been rejected. Please contact the GSO office for assistance.',
                [],
                ResponseInterface::HTTP_UNAUTHORIZED
            );
        }

        if ($user['status'] === 'Suspended') {
            return $this->errorResponse(
                'Your account has been suspended. Please contact the GSO office.',
                [],
                ResponseInterface::HTTP_UNAUTHORIZED
            );
        }

        if (!$user['is_verified']) {
            return $this->errorResponse(
                'Please verify your email address before logging in.',
                [],
                ResponseInterface::HTTP_UNAUTHORIZED
            );
        }

        // --- Password Verification ---
        if (!password_verify($password, $user['password_hash'])) {
            return $this->errorResponse(
                'Invalid credentials. Please check your ID/Email and password.',
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
}
