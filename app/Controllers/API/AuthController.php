<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Libraries\JwtService;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * AuthController
 *
 * Handles user authentication, profile retrieval, and password changes.
 *
 * Endpoints:
 *  POST /api/v1/auth/login    - Login (student ID or email)
 *  POST /api/v1/auth/logout   - Logout (client-side token invalidation)
 *  GET  /api/v1/auth/me       - Get authenticated user's profile
 *  PATCH /api/v1/auth/profile - Update own profile (name, contact number)
 */
class AuthController extends BaseController
{
    private UserModel $userModel;
    private JwtService $jwt;

    public function __construct()
    {
        $this->userModel = new UserModel();
        $this->jwt       = new JwtService();
    }

    /**
     * Login
     *
     * Accepts an email address as identifier.
     * Returns a signed JWT access token on success.
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

        // --- Build JWT Payload ---
        $tokenPayload = [
            'id'      => $user['id'],
            'role'    => $user['role'],
            'unit_id' => $user['unit_id'],
        ];

        $accessToken  = $this->jwt->generateAccessToken($tokenPayload);
        $refreshToken = $this->jwt->generateRefreshToken($tokenPayload);

        // Remove sensitive fields before returning user data
        unset($user['password_hash'], $user['id_card_image']);

        return $this->successResponse('Login successful.', [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => (int) env('JWT_EXPIRES_IN', 3600),
            'user'          => $user,
        ]);
    }

    /**
     * Logout
     *
     * Since JWTs are stateless, logout is handled client-side.
     * This endpoint is a standard convention for clients to confirm logout.
     * For production, extend with a token blacklist table if needed.
     */
    public function logout(): ResponseInterface
    {
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
}
