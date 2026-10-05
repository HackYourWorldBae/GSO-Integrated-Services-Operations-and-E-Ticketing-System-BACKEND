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
    private ?string $serviceAccountEmail = null;
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

            $authConfig = null;
            if (!empty($credentialsJson)) {
                $config = json_decode($credentialsJson, true);
                if (json_last_error() !== JSON_ERROR_NONE || empty($config['type'])) {
                    $this->initError = 'Stored Google Service Account JSON is invalid.';
                    return;
                }
                $authConfig = $config;
                $client->setAuthConfig($config);
            } elseif (!empty($customPath) && file_exists($customPath)) {
                $client->setAuthConfig($customPath);
                $fileContents = file_get_contents($customPath);
                $authConfig = json_decode($fileContents, true);
            } elseif (file_exists($defaultPath)) {
                $client->setAuthConfig($defaultPath);
                $fileContents = file_get_contents($defaultPath);
                $authConfig = json_decode($fileContents, true);
            } else {
                $this->initError = 'Google Drive service account credentials not found. Configure in Settings or place google_service_account.json in writable/ directory.';
                return;
            }

            if (is_array($authConfig) && !empty($authConfig['client_email'])) {
                $this->serviceAccountEmail = $authConfig['client_email'];
            }

            $this->client = $client;
            $this->service = new GoogleDrive($client);
            $this->folderId = $this->settingModel->getSetting('google_drive_folder_id', env('GOOGLE_DRIVE_FOLDER_ID'));
            $this->initialized = true;
        } catch (Throwable $e) {
            $this->initError = $this->formatGoogleDriveError($e);
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
     * Get the service account email if credentials have been parsed.
     */
    public function getServiceAccountEmail(): ?string
    {
        return $this->serviceAccountEmail;
    }

    /**
     * Get the currently configured target folder ID.
     */
    public function getFolderId(): ?string
    {
        return $this->folderId;
    }

    /**
     * Test connection to Google Drive.
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success'               => false,
                'message'               => $this->initError ?? 'Google Drive credentials are not configured.',
                'service_account_email' => $this->serviceAccountEmail,
                'folder_id'             => $this->folderId ?: '',
            ];
        }

        try {
            $optParams = [
                'pageSize'                  => 5,
                'fields'                    => 'files(id, name)',
                'supportsAllDrives'         => true,
                'includeItemsFromAllDrives' => true,
            ];

            if (!empty($this->folderId)) {
                $optParams['q'] = sprintf("'%s' in parents and trashed = false", addslashes($this->folderId));
            }

            $results = $this->service->files->listFiles($optParams);
            $fileCount = count($results->getFiles());

            return [
                'success'               => true,
                'message'               => 'Connected to Google Drive successfully.',
                'folder_id'             => $this->folderId ?: '',
                'service_account_email' => $this->serviceAccountEmail,
                'files_in_folder'       => $fileCount,
            ];
        } catch (Throwable $e) {
            return [
                'success'               => false,
                'message'               => $this->formatGoogleDriveError($e),
                'service_account_email' => $this->serviceAccountEmail,
                'folder_id'             => $this->folderId ?: '',
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
                'success'  => false,
                'file_id'  => null,
                'web_link' => null,
                'error'    => $this->initError ?? 'Google Drive is not configured.',
            ];
        }

        if (empty($this->folderId)) {
            $emailHint = $this->serviceAccountEmail ? " ({$this->serviceAccountEmail})" : '';
            return [
                'success'  => false,
                'file_id'  => null,
                'web_link' => null,
                'error'    => "Google Drive Folder ID is required. Service accounts do not have private drive storage. Please create a folder in your Google Drive, share it with your service account{$emailHint} as 'Editor', and configure the Folder ID in Drive Settings.",
            ];
        }

        if (!file_exists($localFilePath)) {
            return [
                'success'  => false,
                'file_id'  => null,
                'web_link' => null,
                'error'    => "Local file does not exist: {$localFilePath}",
            ];
        }

        try {
            $fileName = !empty($customFileName) ? $customFileName : basename($localFilePath);

            $fileMetadata = new DriveFile([
                'name'        => $fileName,
                'description' => 'GSO E-Ticketing System Database Backup',
                'parents'     => [$this->folderId],
            ]);

            $content = file_get_contents($localFilePath);

            $uploadedFile = $this->service->files->create($fileMetadata, [
                'data'              => $content,
                'mimeType'          => 'application/sql',
                'uploadType'        => 'multipart',
                'fields'            => 'id, webViewLink, webContentLink, size',
                'supportsAllDrives' => true,
            ]);

            return [
                'success'  => true,
                'file_id'  => $uploadedFile->id,
                'web_link' => $uploadedFile->webViewLink,
                'error'    => null,
            ];
        } catch (Throwable $e) {
            return [
                'success'  => false,
                'file_id'  => null,
                'web_link' => null,
                'error'    => $this->formatGoogleDriveError($e),
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
            $response = $this->service->files->get($driveFileId, [
                'alt'               => 'media',
                'supportsAllDrives' => true,
            ]);
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
                'error'   => $this->formatGoogleDriveError($e),
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
            $this->service->files->delete($driveFileId, [
                'supportsAllDrives' => true,
            ]);
            return true;
        } catch (Throwable $e) {
            log_message('error', 'Google Drive delete failed: ' . $this->formatGoogleDriveError($e));
            return false;
        }
    }

    /**
     * Parse Google API exception into a clear, actionable human message.
     */
    public function formatGoogleDriveError(Throwable $e): string
    {
        $rawMessage = $e->getMessage();
        $code = (int) $e->getCode();
        $emailHint = $this->serviceAccountEmail ? " ({$this->serviceAccountEmail})" : '';

        // Check if raw message contains JSON
        $decoded = json_decode($rawMessage, true);
        $extractedMessage = '';
        $reason = '';

        if (is_array($decoded) && isset($decoded['error'])) {
            $err = $decoded['error'];
            $code = (int) ($err['code'] ?? $code);
            $extractedMessage = $err['message'] ?? '';
            if (!empty($err['errors'][0]['reason'])) {
                $reason = $err['errors'][0]['reason'];
            }
        } else {
            $extractedMessage = $rawMessage;
        }

        // Specific handling for Service Account storageQuotaExceeded
        if ($reason === 'storageQuotaExceeded'
            || stripos($rawMessage, 'storageQuotaExceeded') !== false
            || stripos($rawMessage, 'Service Accounts do not have storage') !== false) {
            return "Google Drive storage quota error: Service Accounts do not have private drive storage. Please create a folder in your Google Drive, share it with your service account{$emailHint} with 'Editor' permissions, and paste its Folder ID into Drive Settings.";
        }

        // Specific handling for Folder/File not found (404)
        if ($code === 404 || stripos($rawMessage, 'File not found') !== false) {
            return "Google Drive target folder was not found (Folder ID: " . ($this->folderId ?: 'None') . "). Please verify the Folder ID exists in your Google Drive and is shared with your service account{$emailHint}.";
        }

        // Specific handling for Forbidden / permission issues (403)
        if ($code === 403 || stripos($rawMessage, 'insufficientFilePermissions') !== false) {
            return "Google Drive permission denied. Please verify your service account{$emailHint} has been added as an 'Editor' to the backup folder.";
        }

        // Specific handling for invalid credentials / unauthorized (401)
        if ($code === 401 || stripos($rawMessage, 'invalid_grant') !== false || stripos($rawMessage, 'unauthorized') !== false) {
            return "Google Cloud authentication failed. Please re-upload or update your Service Account JSON credentials.";
        }

        return !empty($extractedMessage) ? "Google Drive error: {$extractedMessage}" : "Google Drive error: {$rawMessage}";
    }
}
