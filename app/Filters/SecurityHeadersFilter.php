<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * SecurityHeadersFilter
 *
 * Attaches industry-standard HTTP security headers to every response
 * to defend against XSS, clickjacking, MIME-sniffing, and SSL stripping.
 *
 * Implements OWASP REST API Security Best Practices.
 */
class SecurityHeadersFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // No pre-request action required
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // 1. Prevent MIME-sniffing vulnerabilities
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        // 2. Prevent clickjacking (don't allow framing of API responses)
        $response->setHeader('X-Frame-Options', 'DENY');

        // 3. Enable legacy browser XSS filtering
        $response->setHeader('X-XSS-Protection', '1; mode=block');

        // 4. Strict Referrer Policy to prevent leaking sensitive query strings across domains
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        // 5. Restrict permissions (disable camera, mic, geolocation for API endpoints)
        $response->setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // 6. Enforce HSTS if running over HTTPS or in production environment
        if ($request->isSecure() || getenv('CI_ENVIRONMENT') === 'production') {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }
}
