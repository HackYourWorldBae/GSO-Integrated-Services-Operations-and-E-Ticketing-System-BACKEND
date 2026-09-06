<?php

namespace App\Controllers;

use App\Libraries\RequestContext;
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
     * Populated by JwtAuthFilter via the RequestContext static registry.
     */
    protected function currentUser(): array
    {
        return RequestContext::getJwtPayload();
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

    /**
     * Check whether the current user is a staff/operational role.
     */
    protected function isStaffRole(): bool
    {
        return in_array($this->currentUserRole(), ['admin', 'dispatcher', 'director', 'worker'], true);
    }

    /**
     * Map of unit code strings to internal numeric IDs.
     */
    protected const GLOBAL_UNIT_MAP = [
        'FGMU' => 1,
        'LEAU' => 2,
        'SSU'  => 3,
    ];

    /**
     * Resolve a unit code or integer to a validated unit ID.
     */
    protected function resolveUnitId(int|string $unit): ?int
    {
        if (is_numeric($unit)) {
            return (int) $unit;
        }
        return self::GLOBAL_UNIT_MAP[strtoupper(trim((string) $unit))] ?? null;
    }

    /**
     * Enforces tenant/unit scoping.
     * Directors have university-wide jurisdiction.
     * Admins and dispatchers are scoped strictly to their assigned unit_id.
     *
     * @param int|string $targetUnit Unit ID or Unit Code (e.g. 'FGMU', 1)
     * @param string $customMessage Optional custom error message
     * @return ResponseInterface|null Returns a 403 response if forbidden, or null if allowed.
     */
    protected function assertUnitAccess(int|string $targetUnit, string $customMessage = ''): ?ResponseInterface
    {
        $targetUnitId = $this->resolveUnitId($targetUnit);
        if ($targetUnitId === null) {
            return $this->errorResponse("Invalid unit specification: {$targetUnit}.", [], ResponseInterface::HTTP_BAD_REQUEST);
        }

        $userRole   = $this->currentUserRole();
        $userUnitId = $this->currentUserUnitId();

        // Directors have campus-wide oversight
        if (in_array($userRole, ['director', 'superadmin'], true)) {
            return null;
        }

        // Admins and Dispatchers must match their assigned unit_id
        if (in_array($userRole, ['admin', 'dispatcher'], true)) {
            if ($userUnitId === null || $userUnitId !== $targetUnitId) {
                $msg = !empty($customMessage) 
                    ? $customMessage 
                    : "Jurisdiction error: Your account is scoped to unit #{$userUnitId}, cannot manage unit #{$targetUnitId}.";
                return $this->forbiddenResponse($msg);
            }
            return null;
        }

        // Other roles (e.g. student, employee) do not have unit management access
        return $this->forbiddenResponse('You do not have permission to manage this unit.');
    }
}

