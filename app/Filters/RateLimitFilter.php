<?php

namespace App\Filters;

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
 * Usage arguments in Routes.php:
 *   ['filter' => 'throttle:60,60']  -> Allow 60 requests per 60 seconds per IP
 *   ['filter' => 'throttle:10,60']  -> Allow 10 requests per 60 seconds (for login/auth)
 */
class RateLimitFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $throttler = Services::throttler();

        // Default limit: 60 requests per 60 seconds
        $capacity = !empty($arguments[0]) ? (int) $arguments[0] : 60;
        $seconds  = !empty($arguments[1]) ? (int) $arguments[1] : 60;

        // Generate a unique bucket key based on IP address + URI path
        $ip  = $request->getIPAddress();
        $key = md5($ip . '_' . $request->getUri()->getPath());

        // Check if the request exceeds the allowed capacity
        if ($throttler->check($key, $capacity, $seconds) === false) {
            $response = Services::response();
            $response->setStatusCode(ResponseInterface::HTTP_TOO_MANY_REQUESTS);
            $response->setJSON([
                'status'  => 'error',
                'message' => 'Too many requests. Please slow down and try again later.',
                'error_code' => 'RATE_LIMIT_EXCEEDED',
            ]);

            return $response;
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No post-request action required
    }
}
