<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Libraries\ResendEmailService;
use App\Models\AccountActivityLogModel;
use App\Models\PasswordResetModel;
use App\Models\UserModel;
use App\Models\UserSessionModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PasswordRecoveryController
 *
 * Dedicated controller for self-service password recovery workflows.
 * Handles forgot-password reset link generation, secure token validation,
 * password resetting with bcrypt hashing, and confirmation notifications.
 */
class PasswordRecoveryController extends BaseController
{
    private UserModel $userModel;
    private PasswordResetModel $passwordResetModel;
    private UserSessionModel $userSessionModel;
    private AccountActivityLogModel $activityLogModel;
    private ResendEmailService $emailService;

    public function __construct()
    {
        $this->userModel          = new UserModel();
        $this->passwordResetModel = new PasswordResetModel();
        $this->userSessionModel   = new UserSessionModel();
        $this->activityLogModel   = new AccountActivityLogModel();
        $this->emailService       = new ResendEmailService();
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

        $this->userModel->update($user['id'], [
            'password_hash'         => password_hash($password, PASSWORD_BCRYPT),
            'failed_login_attempts' => 0,
            'lockout_until'         => null,
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        $this->passwordResetModel->clearResetToken($email);

        $this->userSessionModel->where('user_id', $user['id'])->delete();

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
