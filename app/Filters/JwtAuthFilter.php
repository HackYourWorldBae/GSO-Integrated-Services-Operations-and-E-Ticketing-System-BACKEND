<?php

namespace App\Filters;

use App\Libraries\JwtService;
use App\Libraries\RequestContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * JwtAuthFilter - Protects routes by validating Bearer tokens.
 *
 * Decodes the Bearer token and stores the payload in RequestContext so
 * downstream controllers can access the current user's ID, role, and unit
 * without re-querying the database on every request.
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

        // Store the decoded JWT payload in the static RequestContext registry.
        // This replaces the deprecated PHP 8.2 dynamic property assignment
        // ($request->jwt_payload) and is safe under PHP-FPM/Apache concurrency
        // since each worker process handles exactly one request at a time.
        $payload = $result['data']['data'] ?? [];
        RequestContext::setJwtPayload($payload);

        return null; // Allow the request to continue
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
