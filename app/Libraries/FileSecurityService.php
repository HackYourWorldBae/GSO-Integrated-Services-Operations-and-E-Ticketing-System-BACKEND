<?php

namespace App\Libraries;

use RuntimeException;

/**
 * FileSecurityService
 *
 * Provides:
 * 1. Deep malicious payload and hidden script inspection (magic bytes, PE/ELF executable headers, embedded PHP/JS/macros).
 * 2. AES-256-GCM authenticated encryption at rest for document attachments.
 * 3. On-the-fly authenticated decryption for authorized file download streams.
 */
class FileSecurityService
{
    private string $encryptionKey;

    public function __construct()
    {
        // Resolve a 256-bit (32-byte) binary key from env or fallback to hashed JWT secret
        $rawKey = env('encryption.key') ?: getenv('encryption.key');
        if (!empty($rawKey)) {
            $this->encryptionKey = hash('sha256', $rawKey, true);
        } else {
            $fallbackSecret = env('JWT_SECRET') ?: getenv('JWT_SECRET') ?: 'BSU_GSO_MASTER_SECRET_KEY_DEFAULT_2026';
            $this->encryptionKey = hash('sha256', $fallbackSecret . '_gso_file_key', true);
        }
    }

    /**
     * Inspects an uploaded file for malicious scripts, executable signatures, or disguised extensions.
     *
     * @param string $filePath Full temporary or stored file path
     * @param string $clientName Original file name from client
     * @param string $clientMime Client-reported MIME type
     * @return array ['safe' => bool, 'reason' => string|null]
     */
    public function inspectFile(string $filePath, string $clientName, string $clientMime = ''): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return ['safe' => false, 'reason' => 'Uploaded file is inaccessible.'];
        }

        // 1. Strict extension blacklist
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        $dangerousExts = [
            'php', 'php3', 'php4', 'php5', 'phtml', 'phar',
            'exe', 'bat', 'cmd', 'sh', 'bash', 'bin', 'dll', 'so',
            'ps1', 'vbs', 'js', 'mjs', 'scr', 'com', 'jar', 'msi',
            'py', 'pl', 'cgi', 'htaccess', 'env', 'config'
        ];

        if (in_array($ext, $dangerousExts, true)) {
            return ['safe' => false, 'reason' => "The extension .{$ext} is strictly prohibited for security reasons."];
        }

        // 2. Magic byte / True MIME verification
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $trueMime = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/jpg', 'image/webp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip', 'application/x-zip-compressed',
            'text/plain', 'text/csv'
        ];

        if (!in_array($trueMime, $allowedMimes, true)) {
            // Also check if text/plain or octet-stream for legitimate office documents
            $allowedSpecialExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'webp', 'csv', 'txt'];
            if (!in_array($ext, $allowedSpecialExts, true)) {
                return ['safe' => false, 'reason' => "Invalid or unverified file type ({$trueMime})."];
            }
        }

        // 3. Binary header inspection (Detect Windows PE / Linux ELF / Mach-O executables)
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return ['safe' => false, 'reason' => 'Unable to read file header.'];
        }

        $headerBytes = fread($handle, 16);
        // Windows MZ executable signature
        if (strncmp($headerBytes, "MZ", 2) === 0) {
            fclose($handle);
            return ['safe' => false, 'reason' => 'Disguised executable binary detected (MZ header).'];
        }
        // Linux ELF binary signature
        if (strncmp($headerBytes, "\x7fELF", 4) === 0) {
            fclose($handle);
            return ['safe' => false, 'reason' => 'Disguised Linux executable detected (ELF header).'];
        }
        // Mach-O binary signatures
        $machoHeaders = ["\xfe\xed\xfa\xce", "\xfe\xed\xfa\xcf", "\xce\xfa\xed\xfe", "\xcf\xfa\xed\xfe"];
        foreach ($machoHeaders as $sig) {
            if (strncmp($headerBytes, $sig, 4) === 0) {
                fclose($handle);
                return ['safe' => false, 'reason' => 'Disguised executable binary detected (Mach-O header).'];
            }
        }

        // 4. Content scanning for embedded scripts (up to first 2MB for performance)
        rewind($handle);
        $chunk = fread($handle, 2 * 1024 * 1024);
        fclose($handle);

        $lowerChunk = strtolower($chunk);

        // Disallow PHP opening tags in any uploaded document
        if (str_contains($lowerChunk, '<?php') || str_contains($lowerChunk, '<?=')) {
            return ['safe' => false, 'reason' => 'Embedded server-side script tag (PHP) detected.'];
        }

        // Disallow dangerous script tags in SVG or disguised HTML/XML
        $scriptPatterns = [
            '<script', '</script>', 'javascript:',
            'onload=', 'onerror=', 'onmouseover=',
            'eval(', 'passthru(', 'shell_exec(', 'proc_open(',
            'system(', 'popen('
        ];

        foreach ($scriptPatterns as $pattern) {
            if (str_contains($lowerChunk, $pattern)) {
                return ['safe' => false, 'reason' => "Potentially malicious script payload detected ({$pattern})."];
            }
        }

        return ['safe' => true, 'reason' => null];
    }

    /**
     * Encrypts a file using AES-256-GCM. Overwrites or writes to target path.
     *
     * @param string $sourcePath Path to raw source file
     * @param string $destPath Path to output ciphertext file
     * @return array ['iv' => string, 'tag' => string] (hex-encoded)
     */
    public function encryptFile(string $sourcePath, string $destPath): array
    {
        $plaintext = file_get_contents($sourcePath);
        if ($plaintext === false) {
            throw new RuntimeException("Failed to read file for encryption: {$sourcePath}");
        }

        $iv = openssl_random_pseudo_bytes(12); // Standard 96-bit IV for AES-GCM
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new RuntimeException("AES-256 encryption failed: " . openssl_error_string());
        }

        if (file_put_contents($destPath, $ciphertext) === false) {
            throw new RuntimeException("Failed to write encrypted file to {$destPath}");
        }

        return [
            'iv'  => bin2hex($iv),
            'tag' => bin2hex($tag)
        ];
    }

    /**
     * Decrypts an AES-256-GCM encrypted file.
     *
     * @param string $sourcePath Path to ciphertext file
     * @param string $ivHex Hex-encoded IV
     * @param string $tagHex Hex-encoded authentication tag
     * @return string|null Decrypted plaintext bytes, or null on verification failure
     */
    public function decryptFile(string $sourcePath, string $ivHex, string $tagHex): ?string
    {
        if (!file_exists($sourcePath)) {
            return null;
        }

        $ciphertext = file_get_contents($sourcePath);
        if ($ciphertext === false) {
            return null;
        }

        $iv  = hex2bin($ivHex);
        $tag = hex2bin($tagHex);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext !== false ? $plaintext : null;
    }

    /**
     * Encrypts a file using AES-256-GCM with a self-contained header.
     * Header format: "GSOENC\x01" (7 bytes) + IV (12 bytes) + Tag (16 bytes) + Ciphertext.
     */
    public function encryptSelfContained(string $sourcePath, string $destPath): void
    {
        $plaintext = file_get_contents($sourcePath);
        if ($plaintext === false) {
            throw new \RuntimeException("Failed to read file for encryption: {$sourcePath}");
        }

        $iv  = openssl_random_pseudo_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException("AES-256 encryption failed: " . openssl_error_string());
        }

        $payload = "GSOENC\x01" . $iv . $tag . $ciphertext;
        if (file_put_contents($destPath, $payload) === false) {
            throw new \RuntimeException("Failed to write encrypted file to {$destPath}");
        }
    }

    /**
     * Decrypts a file encrypted with encryptSelfContained, or returns raw content if unencrypted.
     */
    public function decryptSelfContained(string $sourcePath): ?string
    {
        if (!file_exists($sourcePath)) {
            return null;
        }

        $content = file_get_contents($sourcePath);
        if ($content === false) {
            return null;
        }

        if (strncmp($content, "GSOENC\x01", 7) === 0) {
            $iv         = substr($content, 7, 12);
            $tag        = substr($content, 19, 16);
            $ciphertext = substr($content, 35);

            $plaintext = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $this->encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            return $plaintext !== false ? $plaintext : null;
        }

        return $content;
    }
}
