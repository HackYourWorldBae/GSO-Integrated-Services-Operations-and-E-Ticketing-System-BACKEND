<?php

namespace App\Models;

use CodeIgniter\Model;

class NotificationModel extends Model
{
    protected $table            = 'notifications';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['user_id', 'type', 'title', 'message', 'is_read'];

    protected bool $allowEmptyInserts = false;

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Remove ticket numbers/identifiers from notification titles
     */
    public static function cleanTitle(?string $title): string
    {
        if (empty($title)) {
            return '';
        }

        // 1. Remove parenthesized ticket hashes or codes, e.g. " (#123)" or " (FGMU-TIC-1-2026)"
        $cleaned = preg_replace('/\s*\(\s*(?:#\s*[A-Za-z0-9\-_]+|(?:FGMU|LEAU|SSU)-(?:TIC|PRJ|INC)-[A-Za-z0-9\-_]+|\d+)\s*\)/i', '', $title);

        // 2. Remove trailing ": <ticketId>", e.g. "Collaboration Request: 123" or ": FGMU-TIC-1-2026"
        $cleaned = preg_replace('/:\s*(?:#\s*[A-Za-z0-9\-_]+|(?:FGMU|LEAU|SSU)-(?:TIC|PRJ|INC)-[A-Za-z0-9\-_]+|\d+)\s*$/i', '', $cleaned);

        // 3. Remove "#123", "#FGMU-TIC-4-2026", "# 123", etc.
        $cleaned = preg_replace('/#\s*[A-Za-z0-9\-_]+/i', '', $cleaned);

        // 4. Remove standalone ticket codes like "FGMU-TIC-1-2026" if any remain in title
        $cleaned = preg_replace('/\b(?:FGMU|LEAU|SSU)-(?:TIC|PRJ|INC)-[A-Za-z0-9\-_]+\b/i', '', $cleaned);

        // 5. Clean up multiple spaces, trailing colons or dashes
        $cleaned = preg_replace('/\s{2,}/', ' ', $cleaned);
        $cleaned = preg_replace('/\s*[:\-]\s*$/', '', $cleaned);

        return trim($cleaned);
    }

    /**
     * Helper to create a notification for a user
     */
    public function createNotification(string $userId, string $type, string $title, string $message)
    {
        // Safety guard: if title had a ticket identifier and message does not, ensure message retains (#ID)
        if (preg_match('/\b((?:FGMU|LEAU|SSU)-(?:TIC|PRJ|INC)-[A-Za-z0-9\-_]+)\b/i', $title, $m)) {
            $code = $m[1];
            if (!str_contains($message, $code)) {
                $message .= " (#{$code})";
            }
        } elseif (preg_match('/#([A-Za-z0-9\-_]+)/', $title, $m)) {
            $code = $m[1];
            if (!str_contains($message, "#{$code}")) {
                $message .= " (#{$code})";
            }
        }

        return $this->insert([
            'user_id' => $userId,
            'type'    => $type,
            'title'   => self::cleanTitle($title),
            'message' => $message,
            'is_read' => 0
        ]);
    }
}
