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

            $gdriveTest = $this->driveService->testConnection();
            $folderId   = $this->settingModel->getSetting('google_drive_folder_id', env('GOOGLE_DRIVE_FOLDER_ID') ?: '');

            return $this->successResponse('Backups retrieved successfully.', [
                'backups'      => $backups,
                'stats'        => $stats,
                'google_drive' => [
                    'is_configured' => $this->driveService->isConfigured(),
                    'connected'     => $gdriveTest['success'] ?? false,
                    'message'       => $gdriveTest['message'] ?? 'Not connected',
                    'folder_id'     => $folderId,
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
     * Update Google Drive Configuration (Folder ID and/or Service Account JSON).
     * POST /api/v1/superadmin/backups/gdrive-config
     */
    public function updateGoogleDriveConfig(): ResponseInterface
    {
        try {
            // Check for JSON upload or raw JSON / folder_id input
            $folderId = $this->request->getPost('folder_id');
            $jsonRaw  = $this->request->getPost('credentials_json');

            // Also support JSON request payload
            if ($folderId === null && $jsonRaw === null) {
                $body     = $this->request->getJSON(true) ?? [];
                $folderId = $body['folder_id'] ?? null;
                $jsonRaw  = $body['credentials_json'] ?? null;
            }

            // Check if file was uploaded
            $uploadedKeyFile = $this->request->getFile('credentials_file');
            if ($uploadedKeyFile && $uploadedKeyFile->isValid()) {
                $jsonRaw = file_get_contents($uploadedKeyFile->getTempName());
            }

            if ($folderId !== null) {
                $this->settingModel->setSetting(
                    'google_drive_folder_id',
                    trim($folderId),
                    'Target Google Drive folder ID for backup files'
                );
            }

            if (!empty($jsonRaw)) {
                $decoded = json_decode($jsonRaw, true);
                if (json_last_error() !== JSON_ERROR_NONE || empty($decoded['client_email'])) {
                    return $this->errorResponse('Invalid Service Account JSON format.', [], ResponseInterface::HTTP_BAD_REQUEST);
                }

                $this->settingModel->setSetting(
                    'google_drive_credentials_json',
                    $jsonRaw,
                    'Google Cloud Service Account JSON credentials'
                );

                // Also write to default file for CLI/service access
                file_put_contents(WRITEPATH . 'google_service_account.json', $jsonRaw);
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
