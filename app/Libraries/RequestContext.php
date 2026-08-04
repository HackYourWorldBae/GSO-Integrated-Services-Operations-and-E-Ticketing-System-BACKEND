<?php

namespace App\Libraries;

/**
 * RequestContext
 *
 * A static registry that safely stores per-request data (JWT payload)
 * without relying on dynamic property assignment on CodeIgniter's
 * IncomingRequest object, which is deprecated in PHP 8.2.
 *
 * Under standard PHP-FPM/Apache concurrency, each worker process handles
 * exactly one request at a time, so a static store is perfectly safe —
 * there is no cross-request contamination.
 *
 * Usage:
 *   // In JwtAuthFilter (write):
 *   RequestContext::setJwtPayload($payload);
 *
 *   // In BaseController (read):
 *   $payload = RequestContext::getJwtPayload();
 */
class RequestContext
{
    /** @var array<string, mixed>|null */
    private static ?array $jwtPayload = null;

    /**
     * Store the decoded JWT payload for the current request lifecycle.
     *
     * @param array<string, mixed> $payload
     */
    public static function setJwtPayload(array $payload): void
    {
        self::$jwtPayload = $payload;
    }

    /**
     * Retrieve the decoded JWT payload set by JwtAuthFilter.
     * Returns an empty array if no payload has been set (unauthenticated route).
     *
     * @return array<string, mixed>
     */
    public static function getJwtPayload(): array
    {
        return self::$jwtPayload ?? [];
    }

    /**
     * Clear the stored payload. Called automatically at request teardown
     * if needed, or useful in tests between requests.
     */
    public static function clear(): void
    {
        self::$jwtPayload = null;
    }
}
