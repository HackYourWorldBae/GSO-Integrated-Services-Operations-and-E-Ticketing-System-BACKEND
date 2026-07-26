<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * BaseController
 *
 * Provides common functionality for all API controllers:
 * - Standardised JSON response methods
 * - Input sanitization
 * - Current-user accessor (populated by JwtAuthFilter)
 *
 * All controllers extend this class instead of CodeIgniter's Controller
 * so they inherit these capabilities automatically.
 */
abstract class BaseController extends Controller
{
    use ResponseTrait;

    /** @var IncomingRequest */
    protected $request;

    /**
     * Helpers auto-loaded for every request handled by child controllers.
     * @var list<string>
     */
    protected $helpers = ['sanitize', 'url', 'text'];

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        helper('sanitize');
    }

    // -------------------------------------------------------------------------
    // Standardised Response Helpers
    // -------------------------------------------------------------------------

    /**
     * Return a 200 OK JSON success response.
     */
    protected function successResponse(string $message, array $data = [], int $httpCode = ResponseInterface::HTTP_OK): ResponseInterface
    {
        return $this->response->setStatusCode($httpCode)->setJSON([
            'status'  => true,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /**
     * Return a JSON error response.
     */
    protected function errorResponse(string $message, array $errors = [], int $httpCode = ResponseInterface::HTTP_BAD_REQUEST): ResponseInterface
    {
        return $this->response->setStatusCode($httpCode)->setJSON([
            'status'  => false,
            'message' => $message,
            'errors'  => $errors,
        ]);
    }

    /**
     * Return a 404 Not Found response.
     */
    protected function notFoundResponse(string $resource = 'Resource'): ResponseInterface
    {
        return $this->errorResponse("{$resource} not found.", [], ResponseInterface::HTTP_NOT_FOUND);
    }

    /**
     * Return a 403 Forbidden response.
     */
    protected function forbiddenResponse(string $message = 'You do not have permission to perform this action.'): ResponseInterface
    {
        return $this->errorResponse($message, [], ResponseInterface::HTTP_FORBIDDEN);
    }

    // -------------------------------------------------------------------------
    // Current User Accessor (set by JwtAuthFilter)
    // -------------------------------------------------------------------------

    /**
     * Get the full JWT payload of the currently authenticated user.
     */
    protected function currentUser(): array
    {
        return $this->request->jwt_payload ?? [];
    }

    /**
     * Get a specific field from the JWT payload.
     */
    protected function currentUserId(): ?string
    {
        return $this->currentUser()['id'] ?? null;
    }

    protected function currentUserRole(): ?string
    {
        return $this->currentUser()['role'] ?? null;
    }

    protected function currentUserUnitId(): ?int
    {
        $unitId = $this->currentUser()['unit_id'] ?? null;
        return $unitId !== null ? (int) $unitId : null;
    }
}
