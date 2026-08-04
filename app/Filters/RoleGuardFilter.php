<?php

namespace App\Filters;

use App\Libraries\RequestContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * RoleGuardFilter - Enforces role-based access control on protected routes.
 *
 * Usage in Routes.php:
 *   $routes->get('/admin/...', '...', ['filter' => 'role:admin,dispatcher']);
 *
 * Must be applied AFTER JwtAuthFilter, which populates RequestContext with the
 * decoded JWT payload via RequestContext::setJwtPayload().
 */
class RoleGuardFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        // $arguments holds the allowed roles: ['admin', 'dispatcher'] etc.
        if (empty($arguments)) {
            return null; // No role restriction specified, allow through.
        }

        // Read from RequestContext — populated by JwtAuthFilter before this runs.
        $payload     = RequestContext::getJwtPayload();
        $currentRole = $payload['role'] ?? null;

        if ($currentRole === null) {
            return Services::response()
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON([
                    'status'  => false,
                    'message' => 'User identity could not be resolved.',
                    'code'    => 'NO_IDENTITY',
                ]);
        }

        if (!in_array($currentRole, $arguments, true)) {
            return Services::response()
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON([
                    'status'  => false,
                    'message' => "Access denied. Required role(s): " . implode(', ', $arguments) . ". Your role: {$currentRole}.",
                    'code'    => 'FORBIDDEN',
                ]);
        }

        return null; // Role is authorized — allow through.
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
