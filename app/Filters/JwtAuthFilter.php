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
        // Controllers read this via $this->request->jwt_payload.
        $request->jwt_payload = $result['data']['data'] ?? [];

        return null; // Allow the request to continue
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
