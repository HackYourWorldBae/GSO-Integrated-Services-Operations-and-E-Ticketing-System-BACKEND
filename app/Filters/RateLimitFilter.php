<?php

namespace App\Filters;

use App\Libraries\RequestContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * RateLimitFilter
 *
 * Protects API endpoints against brute-force attacks and volumetric DDoS
 * using CodeIgniter 4's built-in Throttler service.
 *
 * Bucket key strategy:
 *  - Authenticated routes: keyed by `userId + URI path` so each user gets
 *    their own independent limit regardless of shared NAT/proxy IPs.
 *  - Unauthenticated routes (login, etc.): keyed by `IP + URI path` as
 *    there is no authenticated identity to key on yet.
 *
 * Usage arguments in Routes.php:
 *   ['filter' => 'throttle:60,60']  -> Allow 60 requests per 60 seconds
 *   ['filter' => 'throttle:10,60']  -> Allow 10 requests per 60 seconds (auth routes)
 */
class RateLimitFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        $throttler = Services::throttler();

        // Default limit: 60 requests per 60 seconds
        $capacity = !empty($arguments[0]) ? (int) $arguments[0] : 60;
        $seconds  = !empty($arguments[1]) ? (int) $arguments[1] : 60;

        // Key by authenticated userId when available; fall back to IP for
        // unauthenticated routes (login endpoint, etc.) where JWT hasn't been
        // validated yet. This prevents one user from consuming another user's quota
        // when they share a NAT IP.
        $payload   = RequestContext::getJwtPayload();
        $identity  = !empty($payload['id']) ? 'user_' . $payload['id'] : $request->getIPAddress();
        $key       = md5($identity . '_' . $request->getUri()->getPath());

        if ($throttler->check($key, $capacity, $seconds) === false) {
            $response = Services::response();
            $response->setStatusCode(ResponseInterface::HTTP_TOO_MANY_REQUESTS);
            $response->setJSON([
                'status'     => 'error',
                'message'    => 'Too many requests. Please slow down and try again later.',
                'error_code' => 'RATE_LIMIT_EXCEEDED',
            ]);

            return $response;
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        // No post-request action required
        return null;
    }
}
