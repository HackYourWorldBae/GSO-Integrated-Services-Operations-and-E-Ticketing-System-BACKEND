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
}
