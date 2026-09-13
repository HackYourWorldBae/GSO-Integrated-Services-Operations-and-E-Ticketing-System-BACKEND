<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * UserSessionModel
 *
 * Enforces "One Active Session Per User".
 * Manages session tokens (sid) embedded in JWT claims.
 */
class UserSessionModel extends Model
{
    protected $table            = 'user_sessions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'id',
        'user_id',
        'session_id',
        'ip_address',
        'user_agent',
        'created_at',
        'last_activity',
    ];

    /**
     * Register a new active session for a user.
     * Invalidates any prior active session for this user (Single Session Enforcement).
     */
    public function registerSession(string $userId, string $sessionId, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        helper('sanitize');
        $id = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');

        // Strictly enforce 1 session per user: delete any prior session for this user
        $this->where('user_id', $userId)->delete();

        // Insert the single active session record
        $this->insert([
            'id'            => $id,
            'user_id'       => $userId,
            'session_id'    => $sessionId,
            'ip_address'    => $ipAddress,
            'user_agent'    => $userAgent,
            'created_at'    => $now,
            'last_activity' => $now,
        ]);
    }

    /**
     * Check whether a given session ID is currently the single active session for the user.
     */
    public function isValidSession(string $userId, string $sessionId): bool
    {
        $session = $this->select('id')
                        ->where('user_id', $userId)
                        ->where('session_id', $sessionId)
                        ->first();

        return !empty($session);
    }

    /**
     * Update the last activity timestamp periodically.
     * Throttled to only update if last activity is older than 60 seconds.
     */
    public function touchSession(string $userId, string $sessionId): void
    {
        try {
            $session = $this->select('id, last_activity')
                            ->where('user_id', $userId)
                            ->where('session_id', $sessionId)
                            ->first();

            if ($session && !empty($session['last_activity'])) {
                $lastTime = strtotime($session['last_activity']);
                if ($lastTime && (time() - $lastTime > 60)) {
                    $this->update($session['id'], [
                        'last_activity' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Non-blocking: fail quietly on touch
            log_message('error', '[UserSessionModel] touchSession error: ' . $e->getMessage());
        }
    }

    /**
     * Destroy the active session for a user on explicit logout.
     */
    public function destroyUserSession(string $userId): void
    {
        $this->where('user_id', $userId)->delete();
    }

    /**
     * Destroy an active session by session ID.
     */
    public function destroySessionById(string $sessionId): void
    {
        $this->where('session_id', $sessionId)->delete();
    }
}
