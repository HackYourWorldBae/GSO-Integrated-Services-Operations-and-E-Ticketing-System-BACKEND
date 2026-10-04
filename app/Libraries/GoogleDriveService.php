<?php

namespace App\Libraries;

use App\Models\SystemSettingModel;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Drive\DriveFile;
use Throwable;

/**
 * GoogleDriveService
 *
 * Handles Google Drive cloud backups, downloads, and connection verification
 * using a Google Cloud Service Account.
 */
class GoogleDriveService
{
    private SystemSettingModel $settingModel;
    private ?GoogleClient $client = null;
    private ?GoogleDrive $service = null;
    private ?string $folderId = null;
    private bool $initialized = false;
    private ?string $initError = null;

    public function __construct()
    {
        $this->settingModel = new SystemSettingModel();
        $this->initializeClient();
    }

    /**
     * Attempt to initialize the Google Client using configured credentials.
     */
    private function initializeClient(): void
    {
        try {
            if (!class_exists(GoogleClient::class)) {
                $this->initError = 'Google API Client library is not installed.';
                return;
            }

            // Retrieve credentials either from setting or file
            $credentialsJson = $this->settingModel->getSetting('google_drive_credentials_json');
            $customPath      = $this->settingModel->getSetting('google_drive_credentials_path');
            $defaultPath     = WRITEPATH . 'google_service_account.json';

            $client = new GoogleClient();
            $client->setApplicationName('GSO E-Ticketing System Backup');
            $client->addScope(GoogleDrive::DRIVE);

            if (!empty($credentialsJson)) {
                $config = json_decode($credentialsJson, true);
                if (json_last_error() !== JSON_ERROR_NONE || empty($config['type'])) {
                    $this->initError = 'Stored Google Service Account JSON is invalid.';
                    return;
                }
                $client->setAuthConfig($config);
            } elseif (!empty($customPath) && file_exists($customPath)) {
                $client->setAuthConfig($customPath);
            } elseif (file_exists($defaultPath)) {
                $client->setAuthConfig($defaultPath);
            } else {
                $this->initError = 'Google Drive service account credentials not found. Configure in Settings or place google_service_account.json in writable/ directory.';
                return;
            }

            $this->client = $client;
            $this->service = new GoogleDrive($client);
            $this->folderId = $this->settingModel->getSetting('google_drive_folder_id', env('GOOGLE_DRIVE_FOLDER_ID'));
            $this->initialized = true;
        } catch (Throwable $e) {
            $this->initError = $e->getMessage();
            $this->initialized = false;
        }
    }

    /**
     * Check if Google Drive is configured and ready.
     */
    public function isConfigured(): bool
    {
        return $this->initialized && $this->service !== null;
    }

    /**
     * Get error message if initialization failed.
     */
    public function getInitError(): ?string
    {
        return $this->initError;
    }

    /**
     * Test connection to Google Drive.
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => $this->initError ?? 'Google Drive credentials are not configured.',
            ];
        }

        try {
            $optParams = [
                'pageSize' => 5,
                'fields'   => 'files(id, name)',
            ];

            if (!empty($this->folderId)) {
                $optParams['q'] = sprintf("'%s' in parents and trashed = false", addslashes($this->folderId));
            }

            $results = $this->service->files->listFiles($optParams);
            $fileCount = count($results->getFiles());

            return [
                'success'   => true,
                'message'   => 'Connected to Google Drive successfully.',
                'folder_id' => $this->folderId ?: 'Root Drive Folder',
                'files_in_folder' => $fileCount,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Google Drive API error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Upload a local backup file to Google Drive.
     *
     * @param string $localFilePath Absolute path to SQL file
     * @param string $customFileName Display name for the file in Drive
     * @return array ['success' => bool, 'file_id' => ?string, 'web_link' => ?string, 'error' => ?string]
     */
    public function uploadFile(string $localFilePath, string $customFileName = ''): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'file_id' => null,
                'web_link' => null,
                'error'   => $this->initError ?? 'Google Drive is not configured.',
            ];
        }

        if (!file_exists($localFilePath)) {
            return [
                'success' => false,
                'file_id' => null,
                'web_link' => null,
                'error'   => "Local file does not exist: {$localFilePath}",
            ];
        }

        try {
            $fileName = !empty($customFileName) ? $customFileName : basename($localFilePath);

            $fileMetadata = new DriveFile([
                'name'        => $fileName,
                'description' => 'GSO E-Ticketing System Database Backup',
            ]);

            if (!empty($this->folderId)) {
                $fileMetadata->setParents([$this->folderId]);
            }

            $content = file_get_contents($localFilePath);

            $uploadedFile = $this->service->files->create($fileMetadata, [
                'data'       => $content,
                'mimeType'   => 'application/sql',
                'uploadType' => 'multipart',
                'fields'     => 'id, webViewLink, webContentLink, size',
            ]);

            return [
                'success'   => true,
                'file_id'   => $uploadedFile->id,
                'web_link'  => $uploadedFile->webViewLink,
                'error'     => null,
            ];
        } catch (Throwable $e) {
            return [
                'success'   => false,
                'file_id'   => null,
                'web_link'  => null,
                'error'     => $e->getMessage(),
            ];
        }
    }

    /**
     * Download a file from Google Drive to a local destination.
     *
     * @param string $driveFileId The Google Drive file ID
     * @param string $destinationPath Local absolute path to save the downloaded file
     * @return array ['success' => bool, 'bytes' => int, 'error' => ?string]
     */
    public function downloadFile(string $driveFileId, string $destinationPath): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'bytes'   => 0,
                'error'   => $this->initError ?? 'Google Drive is not configured.',
            ];
        }

        try {
            $response = $this->service->files->get($driveFileId, ['alt' => 'media']);
            $fileContent = $response->getBody()->getContents();

            $destDir = dirname($destinationPath);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            $written = file_put_contents($destinationPath, $fileContent);
            if ($written === false) {
                return [
                    'success' => false,
                    'bytes'   => 0,
                    'error'   => "Failed to write content to {$destinationPath}",
                ];
            }

            return [
                'success' => true,
                'bytes'   => $written,
                'error'   => null,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'bytes'   => 0,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Delete a file from Google Drive.
     */
    public function deleteFile(string $driveFileId): bool
    {
        if (!$this->isConfigured() || empty($driveFileId)) {
            return false;
        }

        try {
            $this->service->files->delete($driveFileId);
            return true;
        } catch (Throwable $e) {
            log_message('error', 'Google Drive delete failed: ' . $e->getMessage());
            return false;
        }
    }
}
