<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * SystemSettingModel
 *
 * Manages global application and infrastructure settings stored in the database.
 * Supports fallback to environment variables (.env).
 */
class SystemSettingModel extends Model
{
    protected $table            = 'system_settings';
    protected $primaryKey       = 'key';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['key', 'value', 'description'];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Retrieve a setting by key with .env fallback.
     */
    public function getSetting(string $key, $default = null): ?string
    {
        try {
            $record = $this->where('key', $key)->first();
            if ($record && !empty($record['value'])) {
                return $record['value'];
            }
        } catch (\Throwable $e) {
            // Defensive: if DB connection fails, proceed to .env fallback
        }

        // Fallbacks to environment variables
        if ($key === 'resend_api_key') {
            $envVal = env('RESEND_API_KEY', getenv('RESEND_API_KEY') ?: null);
            if (!empty($envVal)) {
                return $envVal;
            }
        } elseif ($key === 'resend_from_email') {
            $envVal = env('RESEND_FROM_EMAIL', getenv('RESEND_FROM_EMAIL') ?: null);
            if (!empty($envVal)) {
                return $envVal;
            }
        }

        return $default;
    }

    /**
     * Persist or update a system setting.
     */
    public function setSetting(string $key, ?string $value, ?string $description = null): bool
    {
        $existing = $this->where('key', $key)->first();
        if ($existing) {
            $data = ['value' => $value];
            if ($description !== null) {
                $data['description'] = $description;
            }
            return $this->update($key, $data);
        }

        return (bool) $this->insert([
            'key'         => $key,
            'value'       => $value,
            'description' => $description ?? "Configuration key: {$key}",
        ]);
    }

    /**
     * Check if Resend API key is configured.
     */
    public function isResendConfigured(): bool
    {
        $key = $this->getSetting('resend_api_key');
        return !empty($key) && str_starts_with($key, 're_');
    }

    /**
     * Get masked API key for safe UI display (e.g., 're_••••••••••••3a4b').
     */
    public function getMaskedResendKey(): string
    {
        $key = $this->getSetting('resend_api_key');
        if (empty($key)) {
            return '';
        }

        $length = strlen($key);
        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        $prefix = substr($key, 0, 3);
        $suffix = substr($key, -4);
        return $prefix . str_repeat('•', max(4, $length - 7)) . $suffix;
    }

    /**
     * Determine if the system is currently under emergency or scheduled maintenance mode.
     */
    public function isMaintenanceActive(): bool
    {
        $val = $this->getSetting('maintenance_mode', '0');
        return $val === '1' || $val === 'true' || $val === 1 || $val === true;
    }

    /**
     * Retrieve structured maintenance mode metadata.
     */
    public function getMaintenanceDetails(): array
    {
        $active    = $this->isMaintenanceActive();
        $message   = $this->getSetting('maintenance_message') ?: 'The GSO E-Ticketing System is currently undergoing maintenance and database synchronization. Please check back shortly.';
        $countdown = (int) ($this->getSetting('maintenance_countdown_seconds') ?: 30);
        $timestamp = $this->getSetting('maintenance_activated_at');

        return [
            'active'            => $active,
            'message'           => $message,
            'countdown_seconds' => max(5, min(300, $countdown)),
            'activated_at'      => $timestamp,
        ];
    }

    /**
     * Set maintenance mode state and parameters.
     */
    public function setMaintenanceMode(bool $active, string $message = '', int $countdown = 30, ?string $actorId = null): bool
    {
        $val = $active ? '1' : '0';
        $this->setSetting('maintenance_mode', $val, 'Emergency or scheduled maintenance mode switch (1=active, 0=inactive)');

        if (!empty($message)) {
            $this->setSetting('maintenance_message', trim($message), 'Announcement message displayed to users during maintenance mode');
        }

        $countdownVal = (string) max(5, min(300, $countdown));
        $this->setSetting('maintenance_countdown_seconds', $countdownVal, 'Countdown in seconds provided to active users before automated session logout');

        $now = $active ? date('Y-m-d H:i:s') : null;
        $this->setSetting('maintenance_activated_at', $now, 'Timestamp when maintenance mode was initiated');

        return true;
    }
}
