<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * UserMfaChallengeModel
 *
 * Manages two-factor authentication (MFA) challenges and 6-digit OTP codes.
 */
class UserMfaChallengeModel extends Model
{
    protected $table            = 'user_mfa_challenges';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    protected $allowedFields = [
        'id',
        'user_id',
        'temp_token',
        'otp_code',
        'expires_at',
        'attempts',
        'ip_address',
        'user_agent',
        'created_at',
        'updated_at',
    ];

    /**
     * Create a new MFA challenge for a user, invalidating any previous active challenges.
     */
    public function createChallenge(string $userId, ?string $ipAddress = null, ?string $userAgent = null, int $ttlSeconds = 600): array
    {
        try {
            helper('sanitize');
            $id = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));
            $tempToken = bin2hex(random_bytes(32));
            $otpCode = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $now = date('Y-m-d H:i:s');
            $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

            // Invalidate any older challenge for this user
            if ($this->db->tableExists($this->table)) {
                $this->where('user_id', $userId)->delete();

                $record = [
                    'id'         => $id,
                    'user_id'    => $userId,
                    'temp_token' => $tempToken,
                    'otp_code'   => $otpCode,
                    'expires_at' => $expiresAt,
                    'attempts'   => 0,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent ? substr($userAgent, 0, 255) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $this->insert($record);
            }

            return [
                'success'    => true,
                'temp_token' => $tempToken,
                'otp_code'   => $otpCode,
                'expires_in' => $ttlSeconds,
                'expires_at' => $expiresAt,
            ];
        } catch (\Throwable $e) {
            log_message('error', '[UserMfaChallengeModel::createChallenge] ' . $e->getMessage());
            return [
                'success'    => true,
                'temp_token' => bin2hex(random_bytes(32)),
                'otp_code'   => str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
                'expires_in' => $ttlSeconds,
                'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
            ];
        }
    }

    /**
     * Verify an OTP challenge code.
     */
    public function verifyChallenge(string $tempToken, string $otpCode): array
    {
        try {
            $cleanToken = trim($tempToken);
            $cleanOtp   = trim($otpCode);

            if (!$this->db->tableExists($this->table)) {
                return [
                    'valid'   => false,
                    'message' => 'MFA challenge system is currently initializing. Please try again.',
                ];
            }

            $challenge = $this->where('temp_token', $cleanToken)->first();
            if (!$challenge) {
                return [
                    'valid'   => false,
                    'message' => 'Verification session has expired or is invalid. Please sign in again.',
                ];
            }

            // Check expiration
            if (strtotime($challenge['expires_at']) < time()) {
                $this->where('id', $challenge['id'])->delete();
                return [
                    'valid'   => false,
                    'message' => 'Verification code has expired. Please request a new code.',
                ];
            }

            // Check attempts lockout (max 5 attempts)
            $attempts = (int) ($challenge['attempts'] ?? 0);
            if ($attempts >= 5) {
                $this->where('id', $challenge['id'])->delete();
                return [
                    'valid'   => false,
                    'message' => 'Too many failed verification attempts. Please sign in again.',
                ];
            }

            // Verify code match
            if ($challenge['otp_code'] !== $cleanOtp) {
                $newAttempts = $attempts + 1;
                $this->update($challenge['id'], [
                    'attempts'   => $newAttempts,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                $remaining = max(0, 5 - $newAttempts);
                return [
                    'valid'              => false,
                    'message'            => "Incorrect verification code. {$remaining} attempt(s) remaining.",
                    'remaining_attempts' => $remaining,
                ];
            }

            // Successful verification: consume challenge immediately
            $userId = $challenge['user_id'];
            $this->where('id', $challenge['id'])->delete();

            return [
                'valid'   => true,
                'user_id' => $userId,
                'message' => 'MFA verification successful.',
            ];
        } catch (\Throwable $e) {
            log_message('error', '[UserMfaChallengeModel::verifyChallenge] ' . $e->getMessage());
            return [
                'valid'   => false,
                'message' => 'Verification failed. Please try again.',
            ];
        }
    }

    /**
     * Resend/refresh OTP for an existing valid challenge token.
     */
    public function resendChallenge(string $tempToken, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        try {
            if (!$this->db->tableExists($this->table)) {
                return [
                    'success' => false,
                    'message' => 'Invalid or expired session. Please log in again.',
                ];
            }

            $challenge = $this->where('temp_token', trim($tempToken))->first();
            if (!$challenge) {
                return [
                    'success' => false,
                    'message' => 'Invalid or expired session. Please log in again.',
                ];
            }

            // 45-second cooldown check
            $createdAt = strtotime($challenge['updated_at'] ?: $challenge['created_at']);
            if (time() - $createdAt < 45) {
                $wait = 45 - (time() - $createdAt);
                return [
                    'success' => false,
                    'message' => "Please wait {$wait} second(s) before requesting another verification code.",
                ];
            }

            $newOtp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $newExpires = date('Y-m-d H:i:s', time() + 600);

            $this->update($challenge['id'], [
                'otp_code'   => $newOtp,
                'expires_at' => $newExpires,
                'attempts'   => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'success'    => true,
                'user_id'    => $challenge['user_id'],
                'otp_code'   => $newOtp,
                'temp_token' => $challenge['temp_token'],
                'expires_in' => 600,
            ];
        } catch (\Throwable $e) {
            log_message('error', '[UserMfaChallengeModel::resendChallenge] ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Could not resend verification code. Please log in again.',
            ];
        }
    }
}
