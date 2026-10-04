<?php

namespace App\Libraries;

use App\Models\SystemBackupModel;
use App\Models\AccountActivityLogModel;
use Config\Database;
use Ifsnop\Mysqldump\Mysqldump;
use mysqli;
use Throwable;

/**
 * BackupService
 *
 * Coordinates MySQL database dumps, Google Drive synchronization,
 * integrity verification, and safe database restoration.
 */
class BackupService
{
    private SystemBackupModel $backupModel;
    private GoogleDriveService $driveService;
    private AccountActivityLogModel $activityLogModel;
    private string $backupDir;

    public function __construct(
        ?SystemBackupModel $backupModel = null,
        ?GoogleDriveService $driveService = null,
        ?AccountActivityLogModel $activityLogModel = null
    ) {
        $this->backupModel      = $backupModel ?? new SystemBackupModel();
        $this->driveService     = $driveService ?? new GoogleDriveService();
        $this->activityLogModel = $activityLogModel ?? new AccountActivityLogModel();
        $this->backupDir        = WRITEPATH . 'backups' . DIRECTORY_SEPARATOR;

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * Create a full MySQL database backup and sync to Google Drive.
     *
     * @param ?string $userId Superadmin user ID performing the backup
     * @param string $type 'manual' or 'scheduled'
     * @param string $notes Optional descriptive note
     * @return array Result summary with backup record
     */
    public function createBackup(?string $userId = null, string $type = 'manual', string $notes = ''): array
    {
        $db = Database::connect();
        $dbName = $db->database;
        $dbHost = $db->hostname;
        $dbUser = $db->username;
        $dbPass = $db->password;
        $dbPort = $db->port ?: 3306;

        $timestamp = date('Ymd_His');
        $fileName  = "backup_{$dbName}_{$timestamp}.sql";
        $filePath  = $this->backupDir . $fileName;

        try {
            // Configure Dump settings
            $dumpSettings = [
                'add-drop-table'             => true,
                'add-locks'                  => false,
                'extended-insert'            => true,
                'disable-foreign-keys-check' => true,
                'single-transaction'         => true,
                'lock-tables'                => false,
                'default-character-set'      => 'utf8mb4',
                // Exclude system_backups table to maintain backup lineage during restore
                'exclude-tables'             => ['system_backups'],
            ];

            $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $dump = new Mysqldump($dsn, $dbUser, $dbPass, $dumpSettings);
            $dump->start($filePath);

            if (!file_exists($filePath) || filesize($filePath) === 0) {
                throw new \RuntimeException('Database dump generated an empty file.');
            }

            $fileSizeBytes = (int) filesize($filePath);

            // Fetch table list for metadata
            $tables = $db->listTables();

            // Insert initial record in database
            $backupRecordId = $this->backupModel->insert([
                'file_name'           => $fileName,
                'file_path'           => $filePath,
                'file_size_bytes'     => $fileSizeBytes,
                'backup_type'         => $type,
                'tables_included'     => json_encode($tables),
                'google_drive_status' => 'pending',
                'status'              => 'completed',
                'notes'               => $notes ?: 'System on-demand database backup',
                'created_by'          => $userId,
            ]);

            // Attempt Cloud Upload to Google Drive
            $driveStatus = 'not_configured';
            $driveFileId = null;
            $driveLink   = null;
            $driveError  = null;

            if ($this->driveService->isConfigured()) {
                $uploadResult = $this->driveService->uploadFile($filePath, $fileName);
                if ($uploadResult['success']) {
                    $driveStatus = 'uploaded';
                    $driveFileId = $uploadResult['file_id'];
                    $driveLink   = $uploadResult['web_link'];
                } else {
                    $driveStatus = 'failed';
                    $driveError  = $uploadResult['error'];
                }
            } else {
                $driveError = $this->driveService->getInitError() ?? 'Google Drive credentials not configured.';
            }

            // Update backup record with Google Drive status
            $this->backupModel->update($backupRecordId, [
                'google_drive_file_id' => $driveFileId,
                'google_drive_link'    => $driveLink,
                'google_drive_status'  => $driveStatus,
                'google_drive_error'   => $driveError,
            ]);

            // Log activity
            try {
                $this->activityLogModel->insert([
                    'actor_id'       => $userId,
                    'event_type'     => 'SYSTEM_BACKUP_CREATED',
                    'severity'       => 'info',
                    'ip_address'     => service('request')->getIPAddress() ?? '127.0.0.1',
                    'user_agent'     => (string) service('request')->getUserAgent(),
                    'device_summary' => 'System Backup',
                    'details'        => "Manual database backup created: {$fileName} (" . number_format($fileSizeBytes) . " bytes)",
                    'metadata'       => json_encode([
                        'file_name'         => $fileName,
                        'size_bytes'        => $fileSizeBytes,
                        'cloud_sync_status' => $driveStatus,
                    ]),
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $logErr) {
                // Non-blocking log insertion failure
            }

            $finalRecord = $this->backupModel->find($backupRecordId);

            return [
                'success' => true,
                'message' => 'Database backup created successfully.',
                'backup'  => $finalRecord,
            ];
        } catch (Throwable $e) {
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            log_message('error', 'Backup failed: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Backup creation failed: ' . $e->getMessage(),
                'backup'  => null,
            ];
        }
    }

    /**
     * Restore database from an existing backup record.
     * If the local file is missing but exists on Google Drive, it will automatically download it.
     */
    public function restoreBackup(int $backupId, ?string $userId = null): array
    {
        $backup = $this->backupModel->find($backupId);
        if (!$backup) {
            return [
                'success' => false,
                'message' => 'Backup record not found.',
            ];
        }

        $filePath = $backup['file_path'];

        // If local file is missing, attempt to restore from Google Drive
        if (!file_exists($filePath)) {
            if (!empty($backup['google_drive_file_id']) && $this->driveService->isConfigured()) {
                $downloadResult = $this->driveService->downloadFile(
                    $backup['google_drive_file_id'],
                    $filePath
                );

                if (!$downloadResult['success']) {
                    return [
                        'success' => false,
                        'message' => 'Local backup missing and Google Drive download failed: ' . $downloadResult['error'],
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'message' => 'Backup file not found locally or on Google Drive.',
                ];
            }
        }

        return $this->executeSqlRestore($filePath, $backup['file_name'], $userId);
    }

    /**
     * Restore database from an uploaded SQL file.
     */
    public function restoreFromUpload(string $uploadedTempPath, string $originalName, ?string $userId = null): array
    {
        if (!file_exists($uploadedTempPath) || filesize($uploadedTempPath) === 0) {
            return [
                'success' => false,
                'message' => 'Uploaded file is invalid or empty.',
            ];
        }

        $destPath = $this->backupDir . 'restore_upload_' . date('Ymd_His') . '_' . basename($originalName);
        if (!move_uploaded_file($uploadedTempPath, $destPath) && !copy($uploadedTempPath, $destPath)) {
            return [
                'success' => false,
                'message' => 'Failed to store uploaded SQL file.',
            ];
        }

        return $this->executeSqlRestore($destPath, $originalName, $userId);
    }

    /**
     * Re-sync a local backup to Google Drive.
     */
    public function syncToGoogleDrive(int $backupId): array
    {
        $backup = $this->backupModel->find($backupId);
        if (!$backup) {
            return [
                'success' => false,
                'message' => 'Backup record not found.',
            ];
        }

        if (!file_exists($backup['file_path'])) {
            return [
                'success' => false,
                'message' => 'Local backup file does not exist on disk.',
            ];
        }

        if (!$this->driveService->isConfigured()) {
            return [
                'success' => false,
                'message' => $this->driveService->getInitError() ?? 'Google Drive is not configured.',
            ];
        }

        $upload = $this->driveService->uploadFile($backup['file_path'], $backup['file_name']);

        if ($upload['success']) {
            $this->backupModel->update($backupId, [
                'google_drive_file_id' => $upload['file_id'],
                'google_drive_link'    => $upload['web_link'],
                'google_drive_status'  => 'uploaded',
                'google_drive_error'   => null,
            ]);

            return [
                'success'   => true,
                'message'   => 'Successfully synced backup to Google Drive.',
                'file_id'   => $upload['file_id'],
                'web_link'  => $upload['web_link'],
            ];
        }

        $this->backupModel->update($backupId, [
            'google_drive_status' => 'failed',
            'google_drive_error'  => $upload['error'],
        ]);

        return [
            'success' => false,
            'message' => 'Google Drive upload failed: ' . $upload['error'],
        ];
    }

    /**
     * Delete a backup locally and from Google Drive.
     */
    public function deleteBackup(int $backupId, ?string $userId = null): array
    {
        $backup = $this->backupModel->find($backupId);
        if (!$backup) {
            return [
                'success' => false,
                'message' => 'Backup not found.',
            ];
        }

        // Delete from Google Drive if present
        if (!empty($backup['google_drive_file_id']) && $this->driveService->isConfigured()) {
            $this->driveService->deleteFile($backup['google_drive_file_id']);
        }

        // Delete local file
        if (file_exists($backup['file_path'])) {
            @unlink($backup['file_path']);
        }

        // Delete database record
        $this->backupModel->delete($backupId);

        // Audit log
        try {
            $this->activityLogModel->insert([
                'actor_id'       => $userId,
                'event_type'     => 'SYSTEM_BACKUP_DELETED',
                'severity'       => 'notice',
                'ip_address'     => service('request')->getIPAddress() ?? '127.0.0.1',
                'user_agent'     => (string) service('request')->getUserAgent(),
                'device_summary' => 'System Backup',
                'details'        => "Database backup removed: {$backup['file_name']}",
                'metadata'       => json_encode(['file_name' => $backup['file_name']]),
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $logErr) {
            // Non-blocking log failure
        }

        return [
            'success' => true,
            'message' => 'Backup deleted successfully.',
        ];
    }

    /**
     * Execute SQL restore script via native MySQLi connection.
     */
    private function executeSqlRestore(string $sqlFilePath, string $sourceName, ?string $userId = null): array
    {
        $db = Database::connect();
        $dbHost = $db->hostname;
        $dbUser = $db->username;
        $dbPass = $db->password;
        $dbName = $db->database;
        $dbPort = $db->port ?: 3306;

        $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int)$dbPort);

        if ($mysqli->connect_error) {
            return [
                'success' => false,
                'message' => 'Database connection failed: ' . $mysqli->connect_error,
            ];
        }

        $mysqli->set_charset("utf8mb4");

        // Increase execution time limit for restoration of large dumps
        @set_time_limit(300);

        try {
            $mysqli->query("SET FOREIGN_KEY_CHECKS = 0;");

            $sqlContent = file_get_contents($sqlFilePath);
            if ($sqlContent === false || strlen(trim($sqlContent)) === 0) {
                throw new \RuntimeException('SQL backup content could not be read or is empty.');
            }

            if ($mysqli->multi_query($sqlContent)) {
                do {
                    // Flush multi-query results
                    if ($result = $mysqli->store_result()) {
                        $result->free();
                    }
                } while ($mysqli->more_results() && $mysqli->next_result());
            }

            if ($mysqli->error) {
                throw new \RuntimeException('MySQL Error during execution: ' . $mysqli->error);
            }

            $mysqli->query("SET FOREIGN_KEY_CHECKS = 1;");
            $mysqli->close();

            // Audit log
            try {
                $this->activityLogModel->insert([
                    'actor_id'       => $userId,
                    'event_type'     => 'SYSTEM_RESTORE_EXECUTED',
                    'severity'       => 'warning',
                    'ip_address'     => service('request')->getIPAddress() ?? '127.0.0.1',
                    'user_agent'     => (string) service('request')->getUserAgent(),
                    'device_summary' => 'Disaster Recovery',
                    'details'        => "Database restored from snapshot: {$sourceName}",
                    'metadata'       => json_encode(['restored_from' => $sourceName]),
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $logErr) {
                // Non-blocking log failure
            }

            return [
                'success' => true,
                'message' => "Database restored successfully from {$sourceName}.",
            ];
        } catch (Throwable $e) {
            if (isset($mysqli) && $mysqli->ping()) {
                $mysqli->query("SET FOREIGN_KEY_CHECKS = 1;");
                $mysqli->close();
            }

            log_message('error', 'Database restore error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Database restoration failed: ' . $e->getMessage(),
            ];
        }
    }
}
