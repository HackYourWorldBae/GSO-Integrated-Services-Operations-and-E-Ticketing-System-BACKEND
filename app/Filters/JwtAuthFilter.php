<?php

namespace App\Filters;

use App\Libraries\JwtService;
use App\Libraries\RequestContext;
use App\Models\UserSessionModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * JwtAuthFilter - Protects routes by validating HttpOnly cookies and Bearer tokens.
 *
 * 1. Checks HttpOnly cookie `gso_jwt_token` first (primary web client security).
 * 2. Falls back to `Authorization: Bearer <token>` header for non-browser API clients.
 * 3. Enforces "One Session Per User" concurrent session validation.
 * 4. Populates RequestContext static registry for downstream controllers.
 */
class JwtAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        $jwt = new JwtService();

        // 1. Primary: Extract from HttpOnly cookie
        $token = $request->getCookie('gso_jwt_token');

        // 2. Secondary fallback: Extract from Authorization header (API / mobile clients)
        if (empty($token)) {
            $authHeader = $request->getHeaderLine('Authorization');
            $token      = $jwt->extractBearerToken($authHeader);
        }

        if (empty($token)) {
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

        $payload = $result['data']['data'] ?? [];
        $userId  = $payload['id'] ?? null;
        $sid     = $payload['sid'] ?? null;

        // 3. Enforce "One Session Per User"
        if ($userId && $sid) {
            $userSessionModel = new UserSessionModel();
            if (!$userSessionModel->isValidSession($userId, $sid)) {
                $response = Services::response()
                    ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                    ->setJSON([
                        'status'  => false,
                        'message' => 'Your session has ended because this account was logged in from another device or location.',
                        'code'    => 'SESSION_SUPERSEDED',
                    ]);

                // Clear the superseded cookie from the browser
                $response->deleteCookie('gso_jwt_token', '', '/', '');
                return $response;
            }

            // Touch last_activity periodically
            $userSessionModel->touchSession($userId, $sid);
        }

        // Store the decoded JWT payload in the static RequestContext registry.
        RequestContext::setJwtPayload($payload);

        return null; // Allow the request to continue
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
