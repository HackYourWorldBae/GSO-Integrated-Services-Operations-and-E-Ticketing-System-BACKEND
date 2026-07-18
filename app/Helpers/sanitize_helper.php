<?php

namespace App\Helpers;

/**
 * Sanitization & Validation Helpers
 *
 * Provides unified, reusable helpers for input sanitization,
 * UUID generation, and ticket ID formatting.
 *
 * Usage: helper('sanitize') OR load via App\Controllers\BaseApiController.
 */

if (!function_exists('sanitize_string')) {
    /**
     * Strip HTML tags and trim whitespace from a string.
     * Handles null values gracefully.
     */
    function sanitize_string(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return trim(strip_tags($value));
    }
}

if (!function_exists('sanitize_array')) {
    /**
     * Recursively sanitize all string values in an array.
     * Non-string scalars (int, bool, float) are passed through unchanged.
     *
     * @param array $data
     * @return array
     */
    function sanitize_array(array $data): array
    {
        array_walk_recursive($data, static function (&$value) {
            if (is_string($value)) {
                $value = trim(strip_tags($value));
            }
        });

        return $data;
    }
}

if (!function_exists('generate_uuid')) {
    /**
     * Generate a version-4 UUID string.
     * Used as primary keys for users and personnel records.
     */
    function generate_uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('generate_ticket_id')) {
    /**
     * Generate a human-readable ticket ID with a sequence counter.
     *
     * Format: {UNIT}-TIC-{sequence}-{year}
     * Example: FGMU-TIC-42-2026
     *
     * @param string $unitCode e.g., 'FGMU', 'LEAU', 'SSU', 'TASU'
     * @param int    $sequence The next sequential number for this unit+year.
     */
    function generate_ticket_id(string $unitCode, int $sequence): string
    {
        $year = date('Y');
        return strtoupper($unitCode) . '-TIC-' . $sequence . '-' . $year;
    }
}

if (!function_exists('api_response')) {
    /**
     * Build a standardised API response array.
     *
     * @param bool   $status  Whether the operation succeeded.
     * @param string $message Human-readable description.
     * @param array  $data    Payload to return.
     * @param int    $code    HTTP status code.
     */
    function api_response(bool $status, string $message, array $data = [], int $code = 200): array
    {
        return [
            'status'  => $status,
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ];
    }
}
