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
 * Handles Google Drive cloud backups, downloads, and connection verification.
 * Supports both:
 * 1. OAuth 2.0 (Direct personal @gmail.com accounts using user storage quota)
 * 2. Google Cloud Service Accounts (for Google Workspace Shared Drives)
 */
class GoogleDriveService
{
    private SystemSettingModel $settingModel;
    private ?GoogleClient $client = null;
    private ?GoogleDrive $service = null;
    private ?string $folderId = null;
    private string $authType = 'service_account'; // 'oauth' | 'service_account'
    private ?string $serviceAccountEmail = null;
    private ?string $accountEmail = null;
    private ?string $accountName = null;
    private ?int $storageLimit = null;
    private ?int $storageUsage = null;
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

            $authType        = $this->settingModel->getSetting('google_drive_auth_type');
            $refreshToken    = $this->settingModel->getSetting('google_drive_refresh_token');
            $clientId        = $this->settingModel->getSetting('google_drive_client_id');
            $clientSecret    = $this->settingModel->getSetting('google_drive_client_secret');
            $credentialsJson = $this->settingModel->getSetting('google_drive_credentials_json');

            // Auto-detect authType if not explicitly set
            if (empty($authType)) {
                $authType = (!empty($refreshToken) && !empty($clientId)) ? 'oauth' : 'service_account';
            }
            $this->authType = $authType;
            $this->folderId = $this->settingModel->getSetting('google_drive_folder_id', env('GOOGLE_DRIVE_FOLDER_ID'));

            if ($this->authType === 'oauth') {
                $this->initializeOAuthClient($clientId, $clientSecret, $refreshToken);
            } else {
                $this->initializeServiceAccountClient($credentialsJson);
            }
        } catch (Throwable $e) {
            $this->initError = $this->formatGoogleDriveError($e);
            $this->initialized = false;
        }
    }

    /**
     * Initialize Google Client using OAuth 2.0 Refresh Token.
     */
    private function initializeOAuthClient(?string $clientId, ?string $clientSecret, ?string $refreshToken): void
    {
        if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
            $this->initError = 'Google Drive OAuth credentials incomplete. Please configure Client ID, Client Secret, and authorize your Google account.';
            return;
        }

        $client = new GoogleClient();
        $client->setApplicationName('GSO E-Ticketing System Backup');
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setAccessType('offline');
        $client->addScope([
            GoogleDrive::DRIVE,
            'openid',
            'email',
            'profile',
        ]);

        $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (isset($token['error'])) {
            $this->initError = 'Google OAuth token refresh failed: ' . ($token['error_description'] ?? $token['error']);
            return;
        }

        $client->setAccessToken($token);
        $this->client = $client;
        $this->service = new GoogleDrive($client);
        $this->accountEmail = $this->settingModel->getSetting('google_drive_account_email');
        $this->initialized = true;

        // Fetch user email and quota
        try {
            $about = $this->service->about->get([
                'fields' => 'user(emailAddress, displayName), storageQuota',
            ]);
            if ($about->getUser()) {
                $this->accountEmail = $about->getUser()->getEmailAddress();
                $this->accountName  = $about->getUser()->getDisplayName();
                if (!empty($this->accountEmail)) {
                    $this->settingModel->setSetting('google_drive_account_email', $this->accountEmail);
                }
            }
            if ($about->getStorageQuota()) {
                $this->storageLimit = (int) $about->getStorageQuota()->getLimit();
                $this->storageUsage = (int) $about->getStorageQuota()->getUsage();
            }
        } catch (Throwable $ignore) {
        }
    }

    /**
     * Initialize Google Client using a Service Account JSON.
     */
    private function initializeServiceAccountClient(?string $credentialsJson): void
    {
        $customPath  = $this->settingModel->getSetting('google_drive_credentials_path');
        $defaultPath = WRITEPATH . 'google_service_account.json';

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
            $authConfig = json_decode(file_get_contents($customPath), true);
        } elseif (file_exists($defaultPath)) {
            $client->setAuthConfig($defaultPath);
            $authConfig = json_decode(file_get_contents($defaultPath), true);
        } else {
            $this->initError = 'Google Drive credentials not found. Configure OAuth 2.0 or Service Account in Drive Settings.';
            return;
        }

        if (is_array($authConfig) && !empty($authConfig['client_email'])) {
            $this->serviceAccountEmail = $authConfig['client_email'];
        }

        $this->client = $client;
        $this->service = new GoogleDrive($client);
        $this->initialized = true;
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
     * Get active authentication type ('oauth' or 'service_account').
     */
    public function getAuthType(): string
    {
        return $this->authType;
    }

    /**
     * Get the service account email if credentials have been parsed.
     */
    public function getServiceAccountEmail(): ?string
    {
        return $this->serviceAccountEmail;
    }

    /**
     * Get user email for OAuth authentication.
     */
    public function getAccountEmail(): ?string
    {
        return $this->accountEmail;
    }

    /**
     * Get user display name for OAuth authentication.
     */
    public function getAccountName(): ?string
    {
        return $this->accountName;
    }

    /**
     * Get storage quota limit in bytes (OAuth).
     */
    public function getStorageLimit(): ?int
    {
        return $this->storageLimit;
    }

    /**
     * Get storage quota usage in bytes (OAuth).
     */
    public function getStorageUsage(): ?int
    {
        return $this->storageUsage;
    }

    /**
     * Get the currently configured target folder ID.
     */
    public function getFolderId(): ?string
    {
        return $this->folderId;
    }

    /**
     * Generate an OAuth Authorization URL for the user to log into Google.
     */
    public function createOAuthAuthUrl(string $clientId, string $clientSecret, string $redirectUri, string $state = ''): string
    {
        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->addScope([
            GoogleDrive::DRIVE,
            'openid',
            'email',
            'profile',
        ]);
        if (!empty($state)) {
            $client->setState($state);
        }
        return $client->createAuthUrl();
    }

    /**
     * Exchange an OAuth authorization code for tokens.
     */
    public function exchangeOAuthCode(string $clientId, string $clientSecret, string $redirectUri, string $code): array
    {
        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        return $client->fetchAccessTokenWithAuthCode($code);
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
                'auth_type'             => $this->authType,
                'service_account_email' => $this->serviceAccountEmail,
                'account_email'         => $this->accountEmail,
                'account_name'          => $this->accountName,
                'storage_limit'         => $this->storageLimit,
                'storage_usage'         => $this->storageUsage,
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

            // Try to refresh about/quota info
            try {
                $about = $this->service->about->get([
                    'fields' => 'user(emailAddress, displayName), storageQuota',
                ]);
                if ($about->getUser()) {
                    $this->accountEmail = $about->getUser()->getEmailAddress();
                    $this->accountName  = $about->getUser()->getDisplayName();
                }
                if ($about->getStorageQuota()) {
                    $this->storageLimit = (int) $about->getStorageQuota()->getLimit();
                    $this->storageUsage = (int) $about->getStorageQuota()->getUsage();
                }
            } catch (Throwable $ignore) {
            }

            $successMsg = $this->authType === 'oauth'
                ? 'Connected to personal Google Drive (' . ($this->accountEmail ?: 'OAuth') . ') successfully.'
                : 'Connected to Google Drive successfully.';

            return [
                'success'               => true,
                'message'               => $successMsg,
                'auth_type'             => $this->authType,
                'folder_id'             => $this->folderId ?: '',
                'service_account_email' => $this->serviceAccountEmail,
                'account_email'         => $this->accountEmail,
                'account_name'          => $this->accountName,
                'storage_limit'         => $this->storageLimit,
                'storage_usage'         => $this->storageUsage,
                'files_in_folder'       => $fileCount,
            ];
        } catch (Throwable $e) {
            return [
                'success'               => false,
                'message'               => $this->formatGoogleDriveError($e),
                'auth_type'             => $this->authType,
                'service_account_email' => $this->serviceAccountEmail,
                'account_email'         => $this->accountEmail,
                'account_name'          => $this->accountName,
                'storage_limit'         => $this->storageLimit,
                'storage_usage'         => $this->storageUsage,
                'folder_id'             => $this->folderId ?: '',
            ];
        }
    }

    /**
     * Locate or create a subfolder in Google Drive for clean asset organization (Databases vs Media).
     */
    public function getOrCreateSubfolder(string $folderName, ?string $parentFolderId = null): ?string
    {
        if (!$this->isConfigured() || !$this->service) {
            return null;
        }

        try {
            $parentId = $parentFolderId ?: $this->folderId;

            // Search for existing subfolder under parent
            $query = "mimeType = 'application/vnd.google-apps.folder' and name = '{$folderName}' and trashed = false";
            if (!empty($parentId)) {
                $query .= " and '{$parentId}' in parents";
            }

            $optParams = [
                'q'                         => $query,
                'fields'                    => 'files(id, name)',
                'pageSize'                  => 1,
                'supportsAllDrives'         => true,
                'includeItemsFromAllDrives' => true,
            ];

            $response = $this->service->files->listFiles($optParams);
            $files    = $response->getFiles();

            if (!empty($files) && isset($files[0]->id)) {
                return $files[0]->id;
            }

            // Create subfolder if not found
            $folderMetadata = [
                'name'     => $folderName,
                'mimeType' => 'application/vnd.google-apps.folder',
            ];
            if (!empty($parentId)) {
                $folderMetadata['parents'] = [$parentId];
            }

            $driveFolder = new DriveFile($folderMetadata);
            $created = $this->service->files->create($driveFolder, [
                'fields'            => 'id',
                'supportsAllDrives' => true,
            ]);

            return $created->id;
        } catch (Throwable $e) {
            log_message('warning', '[GoogleDriveService::getOrCreateSubfolder] Subfolder lookup/creation fallback: ' . $e->getMessage());
            return $parentFolderId ?: $this->folderId;
        }
    }

    /**
     * Upload a local backup file (SQL dump or Media ZIP) to Google Drive.
     * Automatically organizes files into 'Databases' and 'Media' subfolders.
     *
     * @param string $localFilePath Absolute path to file
     * @param string $customFileName Display name for the file in Drive
     * @param string $category 'database' | 'media' | 'full'
     * @return array ['success' => bool, 'file_id' => ?string, 'web_link' => ?string, 'error' => ?string]
     */
    public function uploadFile(string $localFilePath, string $customFileName = '', string $category = 'database'): array
    {
        if (!$this->isConfigured()) {
            return [
                'success'  => false,
                'file_id'  => null,
                'web_link' => null,
                'error'    => $this->initError ?? 'Google Drive is not configured.',
            ];
        }

        // Folder ID is mandatory only for Service Accounts due to 0-byte quota in My Drive
        if ($this->authType === 'service_account' && empty($this->folderId)) {
            $emailHint = $this->serviceAccountEmail ? " ({$this->serviceAccountEmail})" : '';
            return [
                'success'  => false,
                'file_id'  => null,
                'web_link' => null,
                'error'    => "Google Drive Folder ID is required for Service Accounts. Service accounts do not have private drive storage. Please create a folder in your Google Drive, share it with your service account{$emailHint} as 'Editor', and configure the Folder ID in Drive Settings.",
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
            $ext      = strtolower(pathinfo($localFilePath, PATHINFO_EXTENSION));

            // Auto-detect category from file extension if default was kept
            if ($category === 'database' && $ext === 'zip') {
                $category = str_contains($fileName, 'media') ? 'media' : 'full';
            }

            // Organize into 'Media' vs 'Databases' subfolder on Google Drive
            $subfolderName  = ($category === 'media') ? 'Media' : 'Databases';
            $targetFolderId = $this->getOrCreateSubfolder($subfolderName, $this->folderId);

            $mimeType = ($ext === 'zip') ? 'application/zip' : 'application/sql';
            $desc     = match ($category) {
                'media' => 'GSO E-Ticketing System Uploaded Media & Documents Archive (ZIP)',
                'full'  => 'GSO E-Ticketing System Full Disaster Recovery Archive (DB + Uploads)',
                default => 'GSO E-Ticketing System Database Snapshot (SQL)',
            };

            $fileMetadataProps = [
                'name'        => $fileName,
                'description' => $desc,
            ];

            if (!empty($targetFolderId)) {
                $fileMetadataProps['parents'] = [$targetFolderId];
            } elseif (!empty($this->folderId)) {
                $fileMetadataProps['parents'] = [$this->folderId];
            }

            $fileMetadata = new DriveFile($fileMetadataProps);
            $content      = file_get_contents($localFilePath);

            $uploadedFile = $this->service->files->create($fileMetadata, [
                'data'              => $content,
                'mimeType'          => $mimeType,
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
     * Download a file from Google Drive to local storage.
     *
     * @param string $driveFileId The Google Drive file ID
     * @param string $destinationPath Absolute local path where file will be written
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
                    'error'   => 'Failed writing Google Drive file to disk.',
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
     *
     * @param string $driveFileId The Google Drive file ID
     * @return bool True if deleted or already gone
     */
    public function deleteFile(string $driveFileId): bool
    {
        if (!$this->isConfigured()) {
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
            if ($this->authType === 'oauth') {
                return "Google Drive storage quota error: Your personal Google Drive storage is full. Please free up space in your Google Account.";
            }
            return "Google Drive storage quota error: Service Accounts do not have private drive storage. Please switch to 'Personal Google Drive (OAuth 2.0)' in Drive Settings, or use a Google Workspace Shared Drive.";
        }

        // Specific handling for Folder/File not found (404)
        if ($code === 404 || stripos($rawMessage, 'File not found') !== false) {
            return "Google Drive target folder was not found (Folder ID: " . ($this->folderId ?: 'None') . "). Please verify the Folder ID exists in your Google Drive.";
        }

        // Specific handling for Forbidden / permission issues (403)
        if ($code === 403 || stripos($rawMessage, 'insufficientFilePermissions') !== false) {
            return "Google Drive permission denied. Please verify your account has 'Editor' permissions.";
        }

        // Specific handling for invalid credentials / unauthorized (401)
        if ($code === 401 || stripos($rawMessage, 'invalid_grant') !== false || stripos($rawMessage, 'unauthorized') !== false) {
            if ($this->authType === 'oauth') {
                return "Google OAuth token expired or revoked. Please re-authorize your Google Account in Drive Settings.";
            }
            return "Google Cloud authentication failed. Please re-upload or update your Service Account JSON credentials.";
        }

        return !empty($extractedMessage) ? "Google Drive error: {$extractedMessage}" : "Google Drive error: {$rawMessage}";
    }
}
