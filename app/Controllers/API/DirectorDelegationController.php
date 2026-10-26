<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Libraries\JwtService;
use App\Libraries\RequestContext;
use App\Models\AccountActivityLogModel;
use App\Models\RolePermissionModel;
use App\Models\UserModel;
use App\Models\UserSessionModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * DirectorDelegationController
 *
 * Dedicated controller for Executive Director delegation & Acting Unit Head coverage.
 * Handles temporary sub-unit assumption, credential re-issuance, and reversion.
 */
class DirectorDelegationController extends BaseController
{
    private UserModel $userModel;
    private UserSessionModel $userSessionModel;
    private AccountActivityLogModel $activityLogModel;
    private JwtService $jwt;

    public function __construct()
    {
        $this->userModel        = new UserModel();
        $this->userSessionModel = new UserSessionModel();
        $this->activityLogModel = new AccountActivityLogModel();
        $this->jwt              = new JwtService();
    }

    /**
     * Get sub-units status and unit heads for Director delegation / coverage.
     * GET /api/v1/auth/delegation/units
     * Allowed: director, superadmin, or active acting head.
     */
    public function getDelegationUnits(): ResponseInterface
    {
        $userId = $this->currentUserId();
        $db = \Config\Database::connect();

        $currentUser = $this->userModel->find($userId);
        if (!$currentUser) {
            return $this->errorResponse('User identity could not be resolved.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $jwtPayload = RequestContext::getJwtPayload();
        $actualRole = $currentUser['role'] ?? null;
        $isDirector = in_array($actualRole, ['director', 'superadmin'], true) || (!empty($jwtPayload['original_role']) && $jwtPayload['original_role'] === 'director');

        if (!$isDirector) {
            return $this->forbiddenResponse('Only the Executive Director can access unit head delegation controls.');
        }

        try {
            $units = $db->table('units')
                ->select('id, code, name, description')
                ->whereIn('code', ['FGMU', 'LEAU', 'SSU'])
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', '[DirectorDelegationController::getDelegationUnits] ' . $e->getMessage());
            $units = [];
        }

        if (empty($units)) {
            $units = [
                ['id' => 1, 'code' => 'FGMU', 'name' => 'Facilities and Grounds Management Unit', 'description' => 'Structure and grounds repairs'],
                ['id' => 2, 'code' => 'LEAU', 'name' => 'Landscape and Environment Aesthetics Unit', 'description' => 'Landscaping and campus aesthetics'],
                ['id' => 3, 'code' => 'SSU', 'name' => 'Security Service Unit', 'description' => 'Security and campus incident reporting'],
            ];
        }

        $result = [];
        $currentActingUnit = $jwtPayload['acting_unit_code'] ?? null;

        foreach ($units as $u) {
            $unitHead = null;
            try {
                $headUser = $this->userModel->where('role', 'admin')->where('unit_id', $u['id'])->first();
                if ($headUser) {
                    $unitHead = [
                        'id'         => $headUser['id'],
                        'full_name'  => trim(($headUser['first_name'] ?? '') . ' ' . ($headUser['last_name'] ?? '')),
                        'email'      => $headUser['email'],
                        'has_active_session' => $this->userSessionModel->hasActiveSession($headUser['id']),
                    ];
                }
            } catch (\Throwable $e) {
                log_message('error', '[DirectorDelegationController::getDelegationUnits] Error fetching head for unit ' . $u['code'] . ': ' . $e->getMessage());
            }

            $result[] = [
                'unit_id'          => (int) $u['id'],
                'unit_code'        => $u['code'],
                'unit_name'        => $u['name'],
                'description'      => $u['description'] ?? '',
                'unit_head'        => $unitHead,
                'is_currently_acting' => ($currentActingUnit === $u['code']),
            ];
        }

        return $this->successResponse('Delegation units retrieved.', [
            'units'               => $result,
            'is_acting_head'      => !empty($jwtPayload['is_acting_head']),
            'current_acting_unit' => $currentActingUnit,
        ]);
    }

    /**
     * Assume Acting Unit Head coverage for a specific sub-unit.
     * POST /api/v1/auth/assume-unit-head
     * Body: { "unit_code": "FGMU" | "LEAU" | "SSU" }
     */
    public function assumeUnitHead(): ResponseInterface
    {
        try {
            $userId = $this->currentUserId();
            $db = \Config\Database::connect();

            $currentUser = $this->userModel->find($userId);
            if (!$currentUser) {
                return $this->errorResponse('User identity could not be resolved.', [], ResponseInterface::HTTP_UNAUTHORIZED);
            }

            $jwtPayload = RequestContext::getJwtPayload();
            $actualRole = $currentUser['role'] ?? null;
            $isDirector = in_array($actualRole, ['director', 'superadmin'], true) || (!empty($jwtPayload['original_role']) && $jwtPayload['original_role'] === 'director');

            if (!$isDirector) {
                return $this->forbiddenResponse('Only the Executive Director can assume unit head coverage.');
            }

            $body = $this->request->getJSON(true) ?? [];
            $unitCode = strtoupper(trim((string) ($body['unit_code'] ?? '')));

            if (!in_array($unitCode, ['FGMU', 'LEAU', 'SSU'], true)) {
                return $this->errorResponse('Invalid unit code. Supported units: FGMU, LEAU, SSU.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }

            $staticUnits = [
                'FGMU' => ['id' => 1, 'name' => 'Facilities and Grounds Management Unit'],
                'LEAU' => ['id' => 2, 'name' => 'Landscape and Environment Aesthetics Unit'],
                'SSU'  => ['id' => 3, 'name' => 'Security Service Unit'],
            ];

            $unitRow = null;
            try {
                $unitRow = $db->table('units')->where('code', $unitCode)->get()->getRowArray();
            } catch (\Throwable $e) {
                log_message('error', '[DirectorDelegationController::assumeUnitHead] Error querying units table: ' . $e->getMessage());
            }

            $targetUnitId   = (int) ($unitRow['id'] ?? $staticUnits[$unitCode]['id']);
            $targetUnitName = $unitRow['name'] ?? $staticUnits[$unitCode]['name'];

            $headUsers = [];
            try {
                $headUsers = $this->userModel->where('role', 'admin')->where('unit_id', $targetUnitId)->findAll() ?: [];
            } catch (\Throwable $e) {
                log_message('error', '[DirectorDelegationController::assumeUnitHead] Error finding head users: ' . $e->getMessage());
            }

            $terminatedSessionsCount = 0;
            foreach ($headUsers as $h) {
                try {
                    if ($this->userSessionModel->hasActiveSession($h['id'])) {
                        $this->userSessionModel->terminateSession($h['id']);
                        $terminatedSessionsCount++;

                        $this->activityLogModel->logEvent([
                            'event_type'     => 'SESSION_SUPERSEDED',
                            'severity'       => 'warning',
                            'actor_id'       => $currentUser['id'],
                            'target_user_id' => $h['id'],
                            'details'        => "Session for Unit Head {$h['email']} invalidated because Executive Director assumed acting leadership for {$unitCode}.",
                            'metadata'       => [
                                'unit_code'    => $unitCode,
                                'acting_actor' => 'director',
                            ],
                        ]);
                    }
                } catch (\Throwable $e) {
                    log_message('error', "[DirectorDelegationController::assumeUnitHead] Could not terminate session for head {$h['id']}: " . $e->getMessage());
                }
            }

            $sessionId = bin2hex(random_bytes(16));
            $ipAddress = $this->request->getIPAddress();
            $userAgent = substr($this->request->getUserAgent()->getAgentString() ?? '', 0, 255);
            $this->userSessionModel->registerSession($currentUser['id'], $sessionId, $ipAddress, $userAgent);

            $tokenPayload = [
                'id'               => $currentUser['id'],
                'role'             => 'admin',
                'unit_id'          => $targetUnitId,
                'is_acting_head'   => true,
                'original_role'    => 'director',
                'acting_unit_code' => $unitCode,
                'acting_unit_name' => $targetUnitName,
                'sid'              => $sessionId,
            ];

            $accessToken  = $this->jwt->generateAccessToken($tokenPayload);
            $refreshToken = $this->jwt->generateRefreshToken($tokenPayload);

            $directorName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
            if (empty($directorName)) {
                $directorName = 'Executive Director';
            }

            $this->activityLogModel->logEvent([
                'event_type'     => 'DELEGATION_ACTING_HEAD_ASSUMED',
                'severity'       => 'notice',
                'actor_id'       => $currentUser['id'],
                'target_user_id' => $currentUser['id'],
                'details'        => "Director {$directorName} assumed acting leadership for {$unitCode} ({$targetUnitName}). Terminated {$terminatedSessionsCount} active unit head session(s).",
                'metadata'       => [
                    'unit_code'                 => $unitCode,
                    'unit_id'                   => $targetUnitId,
                    'terminated_sessions_count' => $terminatedSessionsCount,
                ],
                'ip_address'     => $ipAddress,
                'user_agent'     => $userAgent,
            ]);

            $expiresIn = $this->jwt->getExpiresIn();
            $isSecure  = $this->request->isSecure();
            $this->response->setCookie([
                'name'     => 'gso_jwt_token',
                'value'    => $accessToken,
                'expire'   => $expiresIn,
                'domain'   => '',
                'path'     => '/',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            $safeUser = $this->userModel->getSafeUser($currentUser['id']) ?? $currentUser;
            $safeUser['role']             = 'admin';
            $safeUser['unit_id']          = $targetUnitId;
            $safeUser['is_acting_head']   = true;
            $safeUser['original_role']    = 'director';
            $safeUser['acting_unit_code'] = $unitCode;
            $safeUser['acting_unit_name'] = $targetUnitName;

            $rolePermissionModel = new RolePermissionModel();
            try {
                $safeUser['permissions'] = $rolePermissionModel->getPermissionsForRole('admin') ?: [];
            } catch (\Throwable $e) {
                $safeUser['permissions'] = ['tickets.view_all', 'tickets.approve_decline', 'tickets.dispatch', 'tickets.assign_worker'];
            }

            return $this->successResponse("Assumed {$unitCode} Head workspace successfully.", [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type'    => 'Bearer',
                'expires_in'    => $expiresIn,
                'user'          => $safeUser,
                'redirect_url'  => "/admin/" . strtolower($unitCode),
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[DirectorDelegationController::assumeUnitHead] Fatal exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->errorResponse(
                'An error occurred while switching to unit head coverage: ' . $e->getMessage(),
                [],
                ResponseInterface::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Revert from Acting Unit Head back to Executive Director dashboard.
     * POST /api/v1/auth/revert-unit-head
     */
    public function revertUnitHead(): ResponseInterface
    {
        try {
            $userId = $this->currentUserId();

            $currentUser = $this->userModel->find($userId);
            if (!$currentUser) {
                return $this->errorResponse('User identity could not be resolved.', [], ResponseInterface::HTTP_UNAUTHORIZED);
            }

            $jwtPayload = RequestContext::getJwtPayload();
            $actualRole = $currentUser['role'] ?? null;
            $isDirector = in_array($actualRole, ['director', 'superadmin'], true) || (!empty($jwtPayload['original_role']) && $jwtPayload['original_role'] === 'director');

            if (!$isDirector) {
                return $this->forbiddenResponse('Only the Executive Director can revert to Director dashboard.');
            }

            $actingUnit = $jwtPayload['acting_unit_code'] ?? 'Sub-Unit';
            $directorName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
            if (empty($directorName)) {
                $directorName = 'Executive Director';
            }

            $sessionId = bin2hex(random_bytes(16));
            $ipAddress = $this->request->getIPAddress();
            $userAgent = substr($this->request->getUserAgent()->getAgentString() ?? '', 0, 255);
            $this->userSessionModel->registerSession($currentUser['id'], $sessionId, $ipAddress, $userAgent);

            $tokenPayload = [
                'id'      => $currentUser['id'],
                'role'    => 'director',
                'unit_id' => null,
                'sid'     => $sessionId,
            ];

            $accessToken  = $this->jwt->generateAccessToken($tokenPayload);
            $refreshToken = $this->jwt->generateRefreshToken($tokenPayload);

            $this->activityLogModel->logEvent([
                'event_type'     => 'DELEGATION_ACTING_HEAD_RELEASED',
                'severity'       => 'info',
                'actor_id'       => $currentUser['id'],
                'target_user_id' => $currentUser['id'],
                'details'        => "Director {$directorName} concluded acting coverage for {$actingUnit} and returned to Executive Director dashboard.",
                'metadata'       => [
                    'reverted_from_unit' => $actingUnit,
                ],
                'ip_address'     => $ipAddress,
                'user_agent'     => $userAgent,
            ]);

            $expiresIn = $this->jwt->getExpiresIn();
            $isSecure  = $this->request->isSecure();
            $this->response->setCookie([
                'name'     => 'gso_jwt_token',
                'value'    => $accessToken,
                'expire'   => $expiresIn,
                'domain'   => '',
                'path'     => '/',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            $safeUser = $this->userModel->getSafeUser($currentUser['id']) ?? $currentUser;
            $safeUser['role']             = 'director';
            $safeUser['unit_id']          = null;
            $safeUser['is_acting_head']   = false;
            $safeUser['original_role']    = null;
            $safeUser['acting_unit_code'] = null;
            $safeUser['acting_unit_name'] = null;

            $rolePermissionModel = new RolePermissionModel();
            try {
                $safeUser['permissions'] = $rolePermissionModel->getPermissionsForRole('director') ?: [];
            } catch (\Throwable $e) {
                $safeUser['permissions'] = ['tickets.view_all', 'reports.view'];
            }

            return $this->successResponse('Successfully returned to Director Dashboard.', [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type'    => 'Bearer',
                'expires_in'    => $expiresIn,
                'user'          => $safeUser,
                'redirect_url'  => '/director/dashboard',
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[DirectorDelegationController::revertUnitHead] Fatal exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->errorResponse(
                'An error occurred while returning to Director dashboard: ' . $e->getMessage(),
                [],
                ResponseInterface::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
