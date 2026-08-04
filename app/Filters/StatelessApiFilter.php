<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * StatelessApiFilter
 *
 * Applied globally to all API routes. Immediately closes/releases the PHP
 * session after it has been read, so the session write-lock is freed before
 * any heavy controller logic runs.
 *
 * WHY THIS IS NEEDED:
 * PHP's session handler (file or database) acquires an exclusive write lock
 * when a session is started. If multiple concurrent requests share the same
 * session ID (e.g. multiple tabs for the same browser user), each request
 * blocks until the previous one finishes and releases the lock. This causes
 * dashboards to appear "broken" or unresponsive when multiple tabs are open.
 *
 * Since the API is stateless (JWT-authenticated), we don't need to write
 * to the session at all. Calling session_write_close() immediately releases
 * the lock so all concurrent requests can proceed in parallel.
 */
class StatelessApiFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        // Release the session lock immediately — the API uses JWT, not sessions.
        // This is the key fix for concurrent dashboard/tab support.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
