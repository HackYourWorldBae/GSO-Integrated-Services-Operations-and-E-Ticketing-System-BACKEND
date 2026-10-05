<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Libraries\BackupService;
use App\Libraries\GoogleDriveService;
use App\Models\SystemBackupModel;
use App\Models\SystemSettingModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * BackupController
 *
 * REST API Controller for managing database backups, on-demand dumps,
 * Google Drive synchronization, and safe database restorations.
 * Restricted to superadmin.
 */
class BackupController extends BaseController
{
    private BackupService $backupService;
    private GoogleDriveService $driveService;
    private SystemBackupModel $backupModel;
    private SystemSettingModel $settingModel;

    public function __construct()
    {
        $this->backupService = new BackupService();
        $this->driveService  = new GoogleDriveService();
        $this->backupModel   = new SystemBackupModel();
        $this->settingModel  = new SystemSettingModel();
    }

    /**
     * List all database backups, aggregate statistics, and Google Drive connection status.
     * GET /api/v1/superadmin/backups
     */
    public function index(): ResponseInterface
    {
        try {
            $backups = $this->backupModel->getBackupsWithUser();
            $stats   = $this->backupModel->getBackupStats();

            // Enrich each backup with file presence check
            foreach ($backups as &$b) {
                $b['local_exists'] = file_exists($b['file_path']);
            }
            unset($b);

            $gdriveTest  = $this->driveService->testConnection();
            $folderId    = $this->settingModel->getSetting('google_drive_folder_id', env('GOOGLE_DRIVE_FOLDER_ID') ?: '');
            $authType    = $this->driveService->getAuthType();
            $backendUrl  = rtrim(env('app.baseURL', config('App')->baseURL ?? 'http://localhost:8080'), '/');
            $redirectUri = $backendUrl . '/api/v1/superadmin/backups/google-oauth-callback';

            return $this->successResponse('Backups retrieved successfully.', [
                'backups'      => $backups,
                'stats'        => $stats,
                'google_drive' => [
                    'is_configured'         => $this->driveService->isConfigured(),
                    'connected'             => $gdriveTest['success'] ?? false,
                    'message'               => $gdriveTest['message'] ?? 'Not connected',
                    'auth_type'             => $authType,
                    'folder_id'             => $folderId,
                    'service_account_email' => $this->driveService->getServiceAccountEmail(),
                    'account_email'         => $this->driveService->getAccountEmail(),
                    'account_name'          => $this->driveService->getAccountName(),
                    'storage_limit'         => $this->driveService->getStorageLimit(),
                    'storage_usage'         => $this->driveService->getStorageUsage(),
                    'client_id'             => $this->settingModel->getSetting('google_drive_client_id') ?: '',
                    'redirect_uri'          => $redirectUri,
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'BackupController::index error: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve backups: ' . $e->getMessage(), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create an on-demand database backup and sync to Google Drive.
     * POST /api/v1/superadmin/backups
     */
    public function create(): ResponseInterface
    {
        try {
            $userId = $this->currentUserId();
            $body   = $this->request->getJSON(true) ?? [];
            $notes  = trim($body['notes'] ?? 'Manual on-demand backup');

            $result = $this->backupService->createBackup($userId, 'manual', $notes);

            if (!$result['success']) {
                return $this->errorResponse($result['message'], [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
            }

            return $this->successResponse($result['message'], [
                'backup' => $result['backup'],
            ], ResponseInterface::HTTP_CREATED);
        } catch (Throwable $e) {
            log_message('error', 'BackupController::create error: ' . $e->getMessage());
            return $this->errorResponse('Failed to initiate backup: ' . $e->getMessage(), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Restore database from an existing backup entry.
     * POST /api/v1/superadmin/backups/(:num)/restore
     */
    public function restore(int $id): ResponseInterface
    {
        try {
            $body = $this->request->getJSON(true) ?? [];
            $confirmation = trim($body['confirmation'] ?? '');

            if ($confirmation !== 'CONFIRM RESTORE') {
                return $this->errorResponse(
                    'Restoration aborted. You must type CONFIRM RESTORE to execute this operation.',
                    [],
                    ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $userId = $this->currentUserId();
            $result = $this->backupService->restoreBackup($id, $userId);

            if (!$result['success']) {
                return $this->errorResponse($result['message'], [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
            }

            return $this->successResponse($result['message']);
        } catch (Throwable $e) {
            log_message('error', 'BackupController::restore error: ' . $e->getMessage());
            return $this->errorResponse('Restoration failed: ' . $e->getMessage(), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Restore database from an uploaded SQL dump file.
     * POST /api/v1/superadmin/backups/restore-upload
     */
    public function restoreUpload(): ResponseInterface
    {
        try {
            $confirmation = trim($this->request->getPost('confirmation') ?? '');
            if ($confirmation !== 'CONFIRM RESTORE') {
                return $this->errorResponse(
                    'Restoration aborted. You must type CONFIRM RESTORE to execute this operation.',
                    [],
                    ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $file = $this->request->getFile('backup_file');
            if (!$file || !$file->isValid()) {
                return $this->errorResponse(
                    $file ? $file->getErrorString() : 'No SQL file uploaded.',
                    [],
                    ResponseInterface::HTTP_BAD_REQUEST
                );
            }

            $extension = strtolower($file->getClientExtension());
            if ($extension !== 'sql') {
                return $this->errorResponse(
                    'Invalid file type. Only standard .sql dump files are accepted.',
                    [],
                    ResponseInterface::HTTP_BAD_REQUEST
                );
            }

            $userId = $this->currentUserId();
            $result = $this->backupService->restoreFromUpload(
                $file->getTempName(),
                $file->getClientName(),
                $userId
            );

            if (!$result['success']) {
                return $this->errorResponse($result['message'], [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
            }

            return $this->successResponse($result['message']);
        } catch (Throwable $e) {
            log_message('error', 'BackupController::restoreUpload error: ' . $e->getMessage());
            return $this->errorResponse('Upload restoration failed: ' . $e->getMessage(), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download backup file (.sql).
     * GET /api/v1/superadmin/backups/(:num)/download
     */
    public function download(int $id): ResponseInterface
    {
        try {
            $backup = $this->backupModel->find($id);
            if (!$backup) {
                return $this->notFoundResponse('Backup record');
            }

            $filePath = $backup['file_path'];

            // If local file is missing but exists in Google Drive, download it first
            if (!file_exists($filePath)) {
                if (!empty($backup['google_drive_file_id']) && $this->driveService->isConfigured()) {
                    $dl = $this->driveService->downloadFile($backup['google_drive_file_id'], $filePath);
                    if (!$dl['success']) {
                        return $this->errorResponse('Failed to download from Google Drive: ' . $dl['error'], [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
                    }
                } else {
                    return $this->errorResponse('Backup file is not available on disk or cloud.', [], ResponseInterface::HTTP_NOT_FOUND);
                }
            }

            return $this->response->download($filePath, null)->setFileName($backup['file_name']);
        } catch (Throwable $e) {
            log_message('error', 'BackupController::download error: ' . $e->getMessage());
            return $this->errorResponse('Download failed: ' . $e->getMessage(), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Retry/Sync backup to Google Drive.
     * POST /api/v1/superadmin/backups/(:num)/sync-gdrive
     */
    public function syncGoogleDrive(int $id): ResponseInterface
    {
        try {
            $result = $this->backupService->syncToGoogleDrive($id);
            if (!$result['success']) {
                return $this->errorResponse($result['message'], [], ResponseInterface::HTTP_BAD_REQUEST);
            }

            return $this->successResponse($result['message'], [
                'file_id'  => $result['file_id'] ?? null,
                'web_link' => $result['web_link'] ?? null,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Google Drive sync failed: ' . $e->getMessage(), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete backup locally and from Google Drive.
     * DELETE /api/v1/superadmin/backups/(:num)
     */
    public function delete(int $id): ResponseInterface
    {
        try {
            $userId = $this->currentUserId();
            $result = $this->backupService->deleteBackup($id, $userId);

            if (!$result['success']) {
                return $this->errorResponse($result['message'], [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
            }

            return $this->successResponse($result['message']);
        } catch (Throwable $e) {
            return $this->errorResponse('Deletion failed: ' . $e->getMessage(), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Test Google Drive connection.
     * GET /api/v1/superadmin/backups/gdrive-status
     */
    public function getGoogleDriveStatus(): ResponseInterface
    {
        $status = $this->driveService->testConnection();
        return $this->successResponse('Google Drive status check completed.', $status);
    }

    /**
     * Generate Google OAuth Authorization URL for Superadmin to authenticate personal Google Drive.
     * GET /api/v1/superadmin/backups/gdrive-oauth-url
     */
    public function getGoogleOAuthUrl(): ResponseInterface
    {
        try {
            $clientId     = trim((string) ($this->request->getGet('client_id') ?? ''));
            $clientSecret = trim((string) ($this->request->getGet('client_secret') ?? ''));
            $folderId     = trim((string) ($this->request->getGet('folder_id') ?? ''));

            if (empty($clientId)) {
                $clientId = (string) ($this->settingModel->getSetting('google_drive_client_id') ?? '');
            }
            if (empty($clientSecret)) {
                $clientSecret = (string) ($this->settingModel->getSetting('google_drive_client_secret') ?? '');
            }
            if (empty($folderId)) {
                $folderId = (string) ($this->settingModel->getSetting('google_drive_folder_id') ?? '');
            }

            if (empty($clientId) || empty($clientSecret)) {
                return $this->errorResponse('Google OAuth Client ID and Client Secret are required before authorizing.', [], ResponseInterface::HTTP_BAD_REQUEST);
            }

            // Persist clientId and clientSecret so callback can use them
            $this->settingModel->setSetting('google_drive_client_id', $clientId, 'Google OAuth 2.0 Client ID');
            $this->settingModel->setSetting('google_drive_client_secret', $clientSecret, 'Google OAuth 2.0 Client Secret');
            if (!empty($folderId)) {
                $this->settingModel->setSetting('google_drive_folder_id', $folderId, 'Target Google Drive Folder ID');
            }

            $backendUrl  = rtrim(env('app.baseURL', config('App')->baseURL ?? 'http://localhost:8080'), '/');
            $redirectUri = $backendUrl . '/api/v1/superadmin/backups/google-oauth-callback';

            $stateData = json_encode([
                'folder_id' => $folderId,
                'time'      => time(),
            ]);
            $state = base64_encode($stateData);

            $authUrl = $this->driveService->createOAuthAuthUrl($clientId, $clientSecret, $redirectUri, $state);

            return $this->successResponse('OAuth URL generated successfully.', [
                'auth_url'     => $authUrl,
                'redirect_uri' => $redirectUri,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Failed generating OAuth URL: ' . $e->getMessage(), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Google OAuth 2.0 Callback endpoint.
     * GET /api/v1/superadmin/backups/google-oauth-callback
     */
    public function googleOAuthCallback(): ResponseInterface
    {
        $frontendUrl = rtrim(env('APP_FRONTEND_URL', 'http://localhost:5173'), '/');

        $error = $this->request->getGet('error');
        if (!empty($error)) {
            $desc = $this->request->getGet('error_description') ?: $error;
            return redirect()->to($frontendUrl . '/superadmin/backups?oauth_error=' . urlencode($desc));
        }

        $code  = $this->request->getGet('code');
        $state = $this->request->getGet('state');

        if (empty($code)) {
            return redirect()->to($frontendUrl . '/superadmin/backups?oauth_error=' . urlencode('Missing OAuth authorization code from Google.'));
        }

        try {
            $clientId     = (string) $this->settingModel->getSetting('google_drive_client_id');
            $clientSecret = (string) $this->settingModel->getSetting('google_drive_client_secret');
            $backendUrl   = rtrim(env('app.baseURL', config('App')->baseURL ?? 'http://localhost:8080'), '/');
            $redirectUri  = $backendUrl . '/api/v1/superadmin/backups/google-oauth-callback';

            if (empty($clientId) || empty($clientSecret)) {
                return redirect()->to($frontendUrl . '/superadmin/backups?oauth_error=' . urlencode('Stored OAuth Client ID or Secret was not found.'));
            }

            $tokens = $this->driveService->exchangeOAuthCode($clientId, $clientSecret, $redirectUri, $code);

            if (isset($tokens['error'])) {
                $errDesc = $tokens['error_description'] ?? $tokens['error'];
                return redirect()->to($frontendUrl . '/superadmin/backups?oauth_error=' . urlencode($errDesc));
            }

            if (!empty($tokens['refresh_token'])) {
                $this->settingModel->setSetting('google_drive_refresh_token', $tokens['refresh_token'], 'Google Drive OAuth 2.0 Refresh Token');
            } elseif (!$this->settingModel->getSetting('google_drive_refresh_token')) {
                return redirect()->to($frontendUrl . '/superadmin/backups?oauth_error=' . urlencode('Google did not return a refresh token. Please re-authorize with consent prompt.'));
            }

            $this->settingModel->setSetting('google_drive_auth_type', 'oauth', 'Active Google Drive Authentication Method');

            // Parse state for folder_id if present
            if (!empty($state)) {
                $decodedState = json_decode(base64_decode($state), true);
                if (is_array($decodedState) && !empty($decodedState['folder_id'])) {
                    $this->settingModel->setSetting('google_drive_folder_id', trim($decodedState['folder_id']), 'Target Google Drive Folder ID');
                }
            }

            // Test connection to populate account email
            $newService   = new GoogleDriveService();
            $test         = $newService->testConnection();
            $accountEmail = $test['account_email'] ?? '';

            $redirectParam = 'oauth_success=1';
            if (!empty($accountEmail)) {
                $redirectParam .= '&account_email=' . urlencode($accountEmail);
            }

            return redirect()->to($frontendUrl . '/superadmin/backups?' . $redirectParam);
        } catch (Throwable $e) {
            return redirect()->to($frontendUrl . '/superadmin/backups?oauth_error=' . urlencode($e->getMessage()));
        }
    }

    /**
     * Update Google Drive Configuration (OAuth or Service Account).
     * POST /api/v1/superadmin/backups/gdrive-config
     */
    public function updateGoogleDriveConfig(): ResponseInterface
    {
        try {
            $authType     = $this->request->getPost('auth_type');
            $folderId     = $this->request->getPost('folder_id');
            $clientId     = $this->request->getPost('client_id');
            $clientSecret = $this->request->getPost('client_secret');
            $refreshToken = $this->request->getPost('refresh_token');
            $jsonRaw      = $this->request->getPost('credentials_json');

            // Also support JSON request payload
            if ($folderId === null && $jsonRaw === null && $clientId === null) {
                $body         = $this->request->getJSON(true) ?? [];
                $authType     = $body['auth_type'] ?? null;
                $folderId     = $body['folder_id'] ?? null;
                $clientId     = $body['client_id'] ?? null;
                $clientSecret = $body['client_secret'] ?? null;
                $refreshToken = $body['refresh_token'] ?? null;
                $jsonRaw      = $body['credentials_json'] ?? null;
            }

            // Check if file was uploaded
            $uploadedKeyFile = $this->request->getFile('credentials_file');
            if ($uploadedKeyFile && $uploadedKeyFile->isValid()) {
                $jsonRaw = file_get_contents($uploadedKeyFile->getTempName());
            }

            // Auto-detect JSON format (Service Account vs OAuth Client JSON)
            if (!empty($jsonRaw)) {
                $decoded = json_decode($jsonRaw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    if (!empty($decoded['type']) && $decoded['type'] === 'service_account') {
                        $authType = 'service_account';
                        $this->settingModel->setSetting(
                            'google_drive_credentials_json',
                            $jsonRaw,
                            'Google Cloud Service Account JSON credentials'
                        );
                        file_put_contents(WRITEPATH . 'google_service_account.json', $jsonRaw);
                    } elseif (!empty($decoded['web']) || !empty($decoded['installed'])) {
                        $oauthData    = $decoded['web'] ?? $decoded['installed'];
                        $authType     = 'oauth';
                        $clientId     = $oauthData['client_id'] ?? $clientId;
                        $clientSecret = $oauthData['client_secret'] ?? $clientSecret;
                    }
                }
            }

            if (!empty($authType)) {
                $this->settingModel->setSetting('google_drive_auth_type', $authType, 'Active Google Drive Authentication Method');
            }

            if ($folderId !== null) {
                $this->settingModel->setSetting(
                    'google_drive_folder_id',
                    trim($folderId),
                    'Target Google Drive folder ID for backup files'
                );
            }

            if (!empty($clientId)) {
                $this->settingModel->setSetting('google_drive_client_id', trim($clientId), 'Google OAuth 2.0 Client ID');
            }

            if (!empty($clientSecret)) {
                $this->settingModel->setSetting('google_drive_client_secret', trim($clientSecret), 'Google OAuth 2.0 Client Secret');
            }

            if (!empty($refreshToken)) {
                $this->settingModel->setSetting('google_drive_refresh_token', trim($refreshToken), 'Google OAuth 2.0 Refresh Token');
                $this->settingModel->setSetting('google_drive_auth_type', 'oauth', 'Active Google Drive Authentication Method');
            }

            // Re-instantiate service to test
            $newService = new GoogleDriveService();
            $testResult = $newService->testConnection();

            return $this->successResponse('Google Drive configuration updated successfully.', [
                'test' => $testResult,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Failed to update Google Drive configuration: ' . $e->getMessage(), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
