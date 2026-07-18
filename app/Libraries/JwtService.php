<?php

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;

/**
 * JwtService - Handles all JWT token operations.
 *
 * Uses firebase/php-jwt with HS256 signing. The secret key is loaded from
 * the JWT_SECRET environment variable defined in .env.
 */
class JwtService
{
    private string $secret;
    private string $algorithm = 'HS256';
    private int $expiresIn;    // seconds
    private int $refreshIn;    // seconds for refresh tokens

    public function __construct()
    {
        // Load from .env via CI4's env() helper; fail loudly if missing.
        $secret = env('JWT_SECRET', '');
        if (empty($secret)) {
            throw new \RuntimeException('JWT_SECRET is not configured in your .env file.');
        }

        $this->secret    = $secret;
        $this->expiresIn = (int) env('JWT_EXPIRES_IN', 3600);     // default 1 hour
        $this->refreshIn = (int) env('JWT_REFRESH_IN', 604800);   // default 7 days
    }

    /**
     * Generate a signed access token for an authenticated user.
     *
     * @param array $payload Data to embed (user id, role, unit_id, etc.)
     */
    public function generateAccessToken(array $payload): string
    {
        $now = time();

        $claims = [
            'iss'  => base_url(),            // Issuer
            'aud'  => env('APP_FRONTEND_URL', '*'), // Audience (frontend origin)
            'iat'  => $now,                  // Issued At
            'nbf'  => $now,                  // Not Before
            'exp'  => $now + $this->expiresIn,
            'type' => 'access',
            'data' => $payload,
        ];

        return JWT::encode($claims, $this->secret, $this->algorithm);
    }

    /**
     * Generate a long-lived refresh token.
     */
    public function generateRefreshToken(array $payload): string
    {
        $now = time();

        $claims = [
            'iss'  => base_url(),
            'iat'  => $now,
            'exp'  => $now + $this->refreshIn,
            'type' => 'refresh',
            'data' => $payload,
        ];

        return JWT::encode($claims, $this->secret, $this->algorithm);
    }

    /**
     * Validate and decode a JWT token.
     *
     * @return array{valid: bool, data: array|null, error: string|null}
     */
    public function validateToken(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));
            $payload = (array) $decoded;

            // Convert nested stdClass to array
            if (isset($payload['data'])) {
                $payload['data'] = (array) $payload['data'];
            }

            return ['valid' => true, 'data' => $payload, 'error' => null];
        } catch (ExpiredException $e) {
            return ['valid' => false, 'data' => null, 'error' => 'Token has expired.'];
        } catch (SignatureInvalidException $e) {
            return ['valid' => false, 'data' => null, 'error' => 'Token signature is invalid.'];
        } catch (UnexpectedValueException $e) {
            return ['valid' => false, 'data' => null, 'error' => 'Token is malformed or invalid.'];
        } catch (\Exception $e) {
            return ['valid' => false, 'data' => null, 'error' => 'Token validation failed.'];
        }
    }

    /**
     * Extract the raw Bearer token from the Authorization header.
     * Returns null if the header is absent or malformed.
     */
    public function extractBearerToken(string $authorizationHeader): ?string
    {
        if (empty($authorizationHeader)) {
            return null;
        }

        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorizationHeader, 7));
        return empty($token) ? null : $token;
    }
}
