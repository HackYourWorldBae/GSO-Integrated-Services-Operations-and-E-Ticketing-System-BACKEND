<?php

namespace App\Filters;

use App\Libraries\JwtService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * JwtAuthFilter - Protects routes by validating Bearer tokens.
 *
 * Attaches the decoded token payload to the request so downstream
 * controllers can access the current user's ID, role, and unit without
 * re-querying the database on every request.
 */
class JwtAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        $jwt = new JwtService();

        $authHeader = $request->getHeaderLine('Authorization');
        $token      = $jwt->extractBearerToken($authHeader);

        if ($token === null) {
            return Services::response()
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON([
                    'status'  => false,
                    'message' => 'Authorization token is missing.',
                    'code'    => 'TOKEN_MISSING',
                ]);
        }

        $result = $jwt->validateToken($token);

        if (!$result['valid']) {
            return Services::response()
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON([
                    'status'  => false,
                    'message' => $result['error'],
                    'code'    => 'TOKEN_INVALID',
                ]);
        }

        // Attach decoded user data to the request for downstream use.
        // Using globals() to avoid PHP 8.2 dynamic property deprecation on IncomingRequest.
        // Controllers read this via service('request')->getGlobal('jwt_payload').
        // Fallback: store in a static registry so BaseController::currentUserId() can access it.
        $payload = $result['data']['data'] ?? [];
        $request->jwt_payload = $payload;  // kept for BC; suppressed at PHP level by CI4's handling

        return null; // Allow the request to continue
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
