<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * PasswordResetModel
 *
 * Handles lifecycle of secure email password reset tokens.
 */
class PasswordResetModel extends Model
{
    protected $table            = 'password_resets';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['email', 'token', 'expires_at', 'created_at'];

    protected $useTimestamps = false;

    /**
     * Generate a secure random token for the specified email and set expiration.
     */
    public function generateResetToken(string $email, int $ttlMinutes = 60): string
    {
        // Purge any stale or existing reset tokens for this email address
        $this->where('email', $email)->delete();

        // 64-character hex token (256-bit entropy)
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

        $this->insert([
            'email'      => $email,
            'token'      => $token,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /**
     * Verify if the token exists, matches email, and is not expired.
     */
    public function verifyResetToken(string $email, string $token): bool
    {
        if (empty($email) || empty($token)) {
            return false;
        }

        $record = $this->where('email', $email)
                       ->where('token', $token)
                       ->first();

        if (!$record) {
            return false;
        }

        return strtotime($record['expires_at']) >= time();
    }

    /**
     * Remove tokens for an email once successfully reset or invalidated.
     */
    public function clearResetToken(string $email): void
    {
        $this->where('email', $email)->delete();
    }
}
