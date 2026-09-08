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

        // Super Admin has campus-wide authority across all operational roles
        if ($currentRole === 'superadmin') {
            return null;
        }

        $permissionModel = new \App\Models\RolePermissionModel();

        if (!in_array($currentRole, $arguments, true)) {
            // Check dynamic capability matrix for cross-role delegations
            $hasDynamicAccess = false;

            // Unit Head 'admin' accessing dispatcher endpoints
            if ($currentRole === 'admin' && in_array('dispatcher', $arguments, true)) {
                $hasDynamicAccess = $permissionModel->hasPermission('admin', 'tickets.dispatch')
                                 || $permissionModel->hasPermission('admin', 'tickets.assign_worker');
            }

            // Dispatcher accessing admin endpoints (e.g. personnel management, ticket queue/approval)
            if ($currentRole === 'dispatcher' && in_array('admin', $arguments, true)) {
                $hasDynamicAccess = $permissionModel->hasPermission('dispatcher', 'personnel.manage')
                                 || $permissionModel->hasPermission('dispatcher', 'tickets.approve_decline');
            }

            // Admin or Dispatcher accessing Director analytics
            if (in_array($currentRole, ['admin', 'dispatcher'], true) && in_array('director', $arguments, true)) {
                $hasDynamicAccess = $permissionModel->hasPermission($currentRole, 'reports.view');
            }

            // Admin or Director accessing Superadmin account management
            if (in_array($currentRole, ['admin', 'director'], true) && in_array('superadmin', $arguments, true)) {
                $hasDynamicAccess = $permissionModel->hasPermission($currentRole, 'users.provision')
                                 || $permissionModel->hasPermission($currentRole, 'system.matrix_control');
            }

            if (!$hasDynamicAccess) {
                return Services::response()
                    ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                    ->setJSON([
                        'status'  => false,
                        'message' => "Access denied. Required role(s): " . implode(', ', $arguments) . ". Your role: {$currentRole}.",
                        'code'    => 'FORBIDDEN',
                    ]);
            }
        }

        // Check explicit feature restrictions if disabled in the matrix
        $uriPath = $request->getUri()->getPath();
        if (str_contains($uriPath, 'personnel') && !$permissionModel->hasPermission($currentRole, 'personnel.manage')) {
            return Services::response()
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON([
                    'status'  => false,
                    'message' => 'Personnel management capability is disabled for your role.',
                    'code'    => 'FEATURE_DISABLED',
                ]);
        }

        return null; // Role is authorized — allow through.
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
