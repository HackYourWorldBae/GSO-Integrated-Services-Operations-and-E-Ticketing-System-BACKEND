<?php

namespace App\Libraries;

use App\Models\SystemBackupModel;
use App\Models\AccountActivityLogModel;
use App\Models\TicketLogModel;
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
    private TicketLogModel $ticketLogModel;
    private string $backupDir;

    public function __construct(
        ?SystemBackupModel $backupModel = null,
        ?GoogleDriveService $driveService = null,
        ?AccountActivityLogModel $activityLogModel = null,
        ?TicketLogModel $ticketLogModel = null
    ) {
        $this->backupModel      = $backupModel ?? new SystemBackupModel();
        $this->driveService     = $driveService ?? new GoogleDriveService();
        $this->activityLogModel = $activityLogModel ?? new AccountActivityLogModel();
        $this->ticketLogModel   = $ticketLogModel ?? new TicketLogModel();
        $this->backupDir        = WRITEPATH . 'backups' . DIRECTORY_SEPARATOR;

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * Create a full unified snapshot (database + media) and sync to Google Drive.
     * All backups are unified full snapshots to eliminate restore conflicts.
     *
     * @param ?string $userId Superadmin user ID performing the backup
     * @param string $type 'manual' or 'scheduled'
     * @param string $notes Optional descriptive note
     * @param string $category 'full' (unified snapshot)
     * @return array Result summary with backup record
     */
    public function createBackup(?string $userId = null, string $type = 'manual', string $notes = '', string $category = 'full'): array
    {
        // Unified full snapshot bundling MySQL database dump + all uploaded media files
        return $this->createFullBackup($userId, $type, $notes);
    }

    /**
     * Restore database and media files from an existing backup record.
     * Supports explicitly choosing storage source:
     *   - 'local': Restores from the server's onsite storage disk.
     *   - 'google_drive': Force-downloads fresh copy from Google Drive cloud storage.
     *   - 'auto': Restores from local if present, else downloads from Google Drive.
     */
    public function restoreBackup(int $backupId, ?string $userId = null, string $source = 'auto'): array
    {
        $backup = $this->backupModel->find($backupId);
        if (!$backup) {
            return [
                'success' => false,
                'message' => 'Backup record not found.',
            ];
        }

        $filePath    = $backup['file_path'];
        $sourceLabel = 'Local Onsite Storage';

        if ($source === 'google_drive') {
            if (!empty($backup['google_drive_file_id']) && $this->driveService->isConfigured()) {
                $downloadResult = $this->driveService->downloadFile(
                    $backup['google_drive_file_id'],
                    $filePath
                );

                if (!$downloadResult['success']) {
                    return [
                        'success' => false,
                        'message' => 'Google Drive cloud download failed: ' . $downloadResult['error'],
                    ];
                }
                $sourceLabel = 'Google Drive Cloud';
            } else {
                return [
                    'success' => false,
                    'message' => 'Google Drive cloud backup is not available for this snapshot entry.',
                ];
            }
        } elseif ($source === 'local') {
            if (!file_exists($filePath)) {
                return [
                    'success' => false,
                    'message' => 'Local onsite backup file not found on disk: ' . basename($filePath),
                ];
            }
            $sourceLabel = 'Local Onsite Storage';
        } else {
            // Auto mode: always prefer the latest between Local onsite file and Google Drive cloud file
            $localExists = file_exists($filePath);
            $driveExists = !empty($backup['google_drive_file_id']) && $this->driveService->isConfigured();

            if ($localExists && $driveExists) {
                $localMtime = (int) filemtime($filePath);
                $driveMeta  = $this->driveService->getFileMetadata($backup['google_drive_file_id']);
                $driveMtime = !empty($driveMeta['modified_time']) ? (int) strtotime($driveMeta['modified_time']) : 0;

                // If Google Drive has a newer file version, pull it; otherwise use the local copy
                if ($driveMtime > $localMtime) {
                    $downloadResult = $this->driveService->downloadFile(
                        $backup['google_drive_file_id'],
                        $filePath
                    );
                    if ($downloadResult['success']) {
                        $sourceLabel = 'Google Drive Cloud (Newer Version Detected)';
                    } else {
                        $sourceLabel = 'Local Onsite Storage (Drive download fallback)';
                    }
                } else {
                    $sourceLabel = 'Local Onsite Storage (Latest Version)';
                }
            } elseif ($localExists) {
                $sourceLabel = 'Local Onsite Storage';
            } elseif ($driveExists) {
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
                $sourceLabel = 'Google Drive Cloud (Auto-downloaded)';
            } else {
                return [
                    'success' => false,
                    'message' => 'Backup file not found locally or on Google Drive.',
                ];
            }
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            return $this->executeZipRestore($filePath, "{$backup['file_name']} [{$sourceLabel}]", $userId);
        }

        return $this->executeSqlRestore($filePath, "{$backup['file_name']} [{$sourceLabel}]", $userId);
    }

    /**
     * Automatically restore the latest snapshot available in the repository.
     */
    public function restoreLatestSnapshot(?string $userId = null, string $source = 'auto'): array
    {
        $latest = $this->backupModel->getLatestBackup();
        if (!$latest) {
            return [
                'success' => false,
                'message' => 'No snapshot records found in the repository.',
            ];
        }

        return $this->restoreBackup((int) $latest['id'], $userId, $source);
    }

    /**
     * Restore database or media files from an uploaded SQL or ZIP file.
     */
    public function restoreFromUpload(string $uploadedTempPath, string $originalName, ?string $userId = null): array
    {
        if (!file_exists($uploadedTempPath) || filesize($uploadedTempPath) === 0) {
            return [
                'success' => false,
                'message' => 'Uploaded file is invalid or empty.',
            ];
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['sql', 'zip'], true)) {
            return [
                'success' => false,
                'message' => 'Invalid backup file format. Only .sql database dumps and .zip archives are supported.',
            ];
        }

        $destPath = $this->backupDir . 'restore_upload_' . date('Ymd_His') . '_' . basename($originalName);
        if (!move_uploaded_file($uploadedTempPath, $destPath) && !copy($uploadedTempPath, $destPath)) {
            return [
                'success' => false,
                'message' => 'Failed to store uploaded backup file.',
            ];
        }

        if ($ext === 'zip') {
            return $this->executeZipRestore($destPath, $originalName, $userId);
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

            try {
                $this->activityLogModel->logEvent([
                    'actor_id'       => null,
                    'event_type'     => 'SYSTEM_BACKUP_SYNCED',
                    'severity'       => 'info',
                    'device_summary' => 'Cloud Synchronization',
                    'details'        => "Backup snapshot {$backup['file_name']} synced to Google Drive.",
                    'metadata'       => ['file_name' => $backup['file_name'], 'file_id' => $upload['file_id']],
                ]);
                $this->ticketLogModel->logAction(
                    'SYSTEM-BACKUP',
                    null,
                    'Snapshot Synced to Drive',
                    "Backup snapshot {$backup['file_name']} successfully uploaded to Google Drive."
                );
            } catch (Throwable $ignored) {}

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
            $this->activityLogModel->logEvent([
                'actor_id'       => $userId,
                'event_type'     => 'SYSTEM_BACKUP_DELETED',
                'severity'       => 'notice',
                'device_summary' => 'System Backup',
                'details'        => "Backup snapshot deleted: {$backup['file_name']}",
                'metadata'       => ['file_name' => $backup['file_name']],
            ]);
            $this->ticketLogModel->logAction(
                'SYSTEM-BACKUP',
                $userId,
                'Backup Snapshot Deleted',
                "Backup snapshot permanently removed: {$backup['file_name']}"
            );
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
                $this->activityLogModel->logEvent([
                    'actor_id'       => $userId,
                    'event_type'     => 'SYSTEM_RESTORE_EXECUTED',
                    'severity'       => 'warning',
                    'device_summary' => 'Disaster Recovery (SQL)',
                    'details'        => "Database restored from snapshot: {$sourceName}",
                    'metadata'       => ['restored_from' => $sourceName],
                ]);
                $this->ticketLogModel->logAction(
                    'SYSTEM-RESTORE',
                    $userId,
                    'System Snapshot Restored',
                    "Database successfully restored from snapshot: {$sourceName}."
                );
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

    /**
     * Create a ZIP archive of all uploaded files, images, and documents in writable/uploads.
     */
    public function createMediaBackup(?string $userId = null, string $type = 'manual', string $notes = ''): array
    {
        @set_time_limit(300);
        $timestamp = date('Ymd_His');
        $fileName  = "media_backup_{$timestamp}.zip";
        $filePath  = $this->backupDir . $fileName;
        $uploadsDir = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR;

        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }

        try {
            $zip = new \ZipArchive();
            if ($zip->open($filePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Failed to initialize ZIP archive for media backup.');
            }

            $fileCount = $this->addDirectoryToZip($zip, $uploadsDir, 'uploads');
            $zip->close();

            if (!file_exists($filePath) || filesize($filePath) === 0) {
                throw new \RuntimeException('Media archive generated an empty file.');
            }

            $fileSizeBytes = (int) filesize($filePath);

            $backupRecordId = $this->backupModel->insert([
                'file_name'           => $fileName,
                'file_path'           => $filePath,
                'file_size_bytes'     => $fileSizeBytes,
                'backup_type'         => $type,
                'backup_category'     => 'media',
                'tables_included'     => json_encode(['media_files_count' => $fileCount]),
                'google_drive_status' => 'pending',
                'status'              => 'completed',
                'notes'               => $notes ?: "Media & uploads archive ({$fileCount} files)",
                'created_by'          => $userId,
            ]);

            // Sync to Google Drive under 'Media' subfolder
            $driveStatus = 'not_configured';
            $driveFileId = null;
            $driveLink   = null;
            $driveError  = null;

            if ($this->driveService->isConfigured()) {
                $uploadResult = $this->driveService->uploadFile($filePath, $fileName, 'media');
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

            $this->backupModel->update($backupRecordId, [
                'google_drive_file_id' => $driveFileId,
                'google_drive_link'    => $driveLink,
                'google_drive_status'  => $driveStatus,
                'google_drive_error'   => $driveError,
            ]);

            try {
                $this->activityLogModel->insert([
                    'actor_id'       => $userId,
                    'event_type'     => 'SYSTEM_BACKUP_CREATED',
                    'severity'       => 'info',
                    'ip_address'     => service('request')->getIPAddress() ?? '127.0.0.1',
                    'user_agent'     => (string) service('request')->getUserAgent(),
                    'device_summary' => 'Media Archive',
                    'details'        => "Media & documents backup created: {$fileName} ({$fileCount} files, " . number_format($fileSizeBytes) . " bytes)",
                    'metadata'       => json_encode([
                        'file_name'         => $fileName,
                        'category'          => 'media',
                        'files_count'       => $fileCount,
                        'size_bytes'        => $fileSizeBytes,
                        'cloud_sync_status' => $driveStatus,
                    ]),
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $ignored) {}

            $finalRecord = $this->backupModel->find($backupRecordId);

            return [
                'success' => true,
                'message' => "Media backup created successfully ({$fileCount} files archived).",
                'backup'  => $finalRecord,
            ];
        } catch (Throwable $e) {
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
            log_message('error', '[BackupService::createMediaBackup] ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Media backup failed: ' . $e->getMessage(),
                'backup'  => null,
            ];
        }
    }

    /**
     * Create a Full System Archive bundling MySQL database dump + all uploaded media files into a single ZIP.
     */
    public function createFullBackup(?string $userId = null, string $type = 'manual', string $notes = ''): array
    {
        @set_time_limit(300);
        $db     = Database::connect();
        $dbName = $db->database;
        $dbHost = $db->hostname;
        $dbUser = $db->username;
        $dbPass = $db->password;
        $dbPort = $db->port ?: 3306;

        $timestamp = date('Ymd_His');
        $fileName  = "snapshot_{$timestamp}.zip";
        $filePath  = $this->backupDir . $fileName;
        $tempSql   = $this->backupDir . "temp_dump_{$timestamp}.sql";
        $uploadsDir = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR;

        try {
            // 1. Generate SQL dump
            $dumpSettings = [
                'add-drop-table'             => true,
                'add-locks'                  => false,
                'extended-insert'            => true,
                'disable-foreign-keys-check' => true,
                'single-transaction'         => true,
                'lock-tables'                => false,
                'default-character-set'      => 'utf8mb4',
                'exclude-tables'             => ['system_backups'],
            ];

            $dsn  = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $dump = new Mysqldump($dsn, $dbUser, $dbPass, $dumpSettings);
            $dump->start($tempSql);

            // 2. Create ZIP archive
            $zip = new \ZipArchive();
            if ($zip->open($filePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Failed to initialize Full System ZIP archive.');
            }

            if (file_exists($tempSql)) {
                $zip->addFile($tempSql, 'database.sql');
            }

            $mediaCount = 0;
            if (is_dir($uploadsDir)) {
                $mediaCount = $this->addDirectoryToZip($zip, $uploadsDir, 'uploads');
            }

            $tables = $db->listTables();
            $manifest = [
                'system'            => 'GSO Integrated Services Operations & E-Ticketing System',
                'archive_type'      => 'full_disaster_recovery',
                'database'          => $dbName,
                'tables_count'      => count($tables),
                'media_files_count' => $mediaCount,
                'created_at'        => date('Y-m-d H:i:s'),
                'created_by'        => $userId,
            ];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();

            if (file_exists($tempSql)) {
                @unlink($tempSql);
            }

            $fileSizeBytes = (int) filesize($filePath);

            $backupRecordId = $this->backupModel->insert([
                'file_name'           => $fileName,
                'file_path'           => $filePath,
                'file_size_bytes'     => $fileSizeBytes,
                'backup_type'         => $type,
                'backup_category'     => 'full',
                'tables_included'     => json_encode($tables),
                'google_drive_status' => 'pending',
                'status'              => 'completed',
                'notes'               => $notes ?: "Full system snapshot: Database + {$mediaCount} uploads",
                'created_by'          => $userId,
            ]);

            // Sync to Google Drive under 'Databases' subfolder
            $driveStatus = 'not_configured';
            $driveFileId = null;
            $driveLink   = null;
            $driveError  = null;

            if ($this->driveService->isConfigured()) {
                $uploadResult = $this->driveService->uploadFile($filePath, $fileName, 'full');
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

            $this->backupModel->update($backupRecordId, [
                'google_drive_file_id' => $driveFileId,
                'google_drive_link'    => $driveLink,
                'google_drive_status'  => $driveStatus,
                'google_drive_error'   => $driveError,
            ]);

            try {
                $this->activityLogModel->logEvent([
                    'actor_id'       => $userId,
                    'event_type'     => 'SYSTEM_BACKUP_CREATED',
                    'severity'       => 'info',
                    'device_summary' => 'Full Disaster Snapshot',
                    'details'        => "Full disaster recovery backup created: {$fileName} (Database + {$mediaCount} uploads, " . number_format($fileSizeBytes) . " bytes). Cloud sync: {$driveStatus}.",
                    'metadata'       => [
                        'file_name'         => $fileName,
                        'category'          => 'full',
                        'tables_count'      => count($tables),
                        'media_count'       => $mediaCount,
                        'size_bytes'        => $fileSizeBytes,
                        'cloud_sync_status' => $driveStatus,
                    ],
                ]);
                $this->ticketLogModel->logAction(
                    'SYSTEM-SNAPSHOT',
                    $userId,
                    'System Snapshot Created',
                    "Full disaster recovery snapshot created: {$fileName} (Database + {$mediaCount} uploads, " . number_format($fileSizeBytes) . " bytes). Synced to Google Drive [{$driveStatus}]."
                );
            } catch (Throwable $ignored) {}

            $finalRecord = $this->backupModel->find($backupRecordId);

            return [
                'success' => true,
                'message' => "Full system backup created successfully (Database + {$mediaCount} uploaded files).",
                'backup'  => $finalRecord,
            ];
        } catch (Throwable $e) {
            if (file_exists($tempSql)) {
                @unlink($tempSql);
            }
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
            log_message('error', '[BackupService::createFullBackup] ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Full system backup failed: ' . $e->getMessage(),
                'backup'  => null,
            ];
        }
    }

    /**
     * Restore from a ZIP archive (Database SQL dump, Media files, or Full System snapshot).
     * Enforces strict Zip-Slip protection to prevent path traversal attacks.
     */
    private function executeZipRestore(string $zipFilePath, string $sourceName, ?string $userId = null): array
    {
        @set_time_limit(300);
        $zip = new \ZipArchive();
        $openResult = $zip->open($zipFilePath);

        if ($openResult !== true) {
            return [
                'success' => false,
                'message' => "Unable to read ZIP archive (error code: {$openResult}).",
            ];
        }

        $uploadBaseDir = rtrim(WRITEPATH . 'uploads', '\\/') . DIRECTORY_SEPARATOR;
        if (!is_dir($uploadBaseDir)) {
            mkdir($uploadBaseDir, 0755, true);
        }

        $sqlFileExtracted   = null;
        $restoredMediaCount = 0;
        $dbRestored         = false;

        try {
            // Pre-validation pass: scan all entries for Zip Slip attacks before extracting anything
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat      = $zip->statIndex($i);
                $entryName = $stat['name'];

                if (str_contains($entryName, '..') || str_starts_with($entryName, '/') || str_starts_with($entryName, '\\')) {
                    $zip->close();
                    return [
                        'success' => false,
                        'message' => "Archive rejected: contains an invalid or malicious relative path: {$entryName}",
                    ];
                }
            }

            // First pass: detect if an SQL dump exists in the ZIP
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat      = $zip->statIndex($i);
                $entryName = $stat['name'];

                if (str_ends_with(strtolower($entryName), '.sql')) {
                    $tempSqlPath = $this->backupDir . 'restore_extracted_' . uniqid() . '.sql';
                    $content     = $zip->getFromIndex($i);
                    if ($content !== false && strlen($content) > 0) {
                        file_put_contents($tempSqlPath, $content);
                        $sqlFileExtracted = $tempSqlPath;
                        break;
                    }
                }
            }

            // Execute SQL restore first if database dump was found
            if ($sqlFileExtracted) {
                $sqlResult = $this->executeSqlRestore($sqlFileExtracted, "{$sourceName} [database.sql]", $userId);
                @unlink($sqlFileExtracted);

                if (!$sqlResult['success']) {
                    $zip->close();
                    return [
                        'success' => false,
                        'message' => 'Database restoration from archive failed: ' . $sqlResult['message'],
                    ];
                }
                $dbRestored = true;
            }

            // Second pass: extract media uploads
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat      = $zip->statIndex($i);
                $entryName = $stat['name'];

                // Skip directories, manifest, and SQL files
                if (substr($entryName, -1) === '/' || substr($entryName, -1) === '\\') {
                    continue;
                }
                if (str_ends_with(strtolower($entryName), '.sql') || $entryName === 'manifest.json') {
                    continue;
                }

                // Normalize: strip leading 'uploads/' prefix if present
                $relPath = preg_replace('#^uploads[\\\\/]#', '', $entryName);
                if (empty($relPath)) {
                    continue;
                }

                $targetPath = $uploadBaseDir . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
                $targetDir  = dirname($targetPath);

                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }

                $fileData = $zip->getFromIndex($i);
                if ($fileData !== false) {
                    file_put_contents($targetPath, $fileData);
                    $restoredMediaCount++;
                }
            }

            $zip->close();

            // Audit log
            try {
                $this->activityLogModel->logEvent([
                    'actor_id'       => $userId,
                    'event_type'     => 'SYSTEM_RESTORE_EXECUTED',
                    'severity'       => 'warning',
                    'device_summary' => 'Disaster Recovery (ZIP)',
                    'details'        => "Archive restored from {$sourceName}: {$restoredMediaCount} media files" . ($dbRestored ? " and database tables." : "."),
                    'metadata'       => [
                        'source'      => $sourceName,
                        'db_restored' => $dbRestored,
                        'media_count' => $restoredMediaCount,
                    ],
                ]);
                $this->ticketLogModel->logAction(
                    'SYSTEM-RESTORE',
                    $userId,
                    'System Snapshot Restored',
                    "Full disaster recovery snapshot restored from {$sourceName}: " . ($dbRestored ? "Database schema and records restored. " : "") . "{$restoredMediaCount} media files extracted."
                );
            } catch (Throwable $ignored) {}

            $msgParts = [];
            if ($dbRestored) {
                $msgParts[] = "Database successfully restored";
            }
            if ($restoredMediaCount > 0) {
                $msgParts[] = "{$restoredMediaCount} uploaded media/document files restored to disk";
            }
            if (empty($msgParts)) {
                $msgParts[] = "Archive processed successfully";
            }

            return [
                'success' => true,
                'message' => implode(' and ', $msgParts) . " from {$sourceName}.",
            ];
        } catch (Throwable $e) {
            if ($sqlFileExtracted && file_exists($sqlFileExtracted)) {
                @unlink($sqlFileExtracted);
            }
            $zip->close();
            log_message('error', '[BackupService::executeZipRestore] ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Archive restoration failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Recursively add a directory's contents into a ZipArchive.
     * Returns total files added.
     */
    private function addDirectoryToZip(\ZipArchive $zip, string $dirPath, string $zipSubdir = ''): int
    {
        if (!is_dir($dirPath)) {
            return 0;
        }

        $count = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $filePath     = $file->getRealPath();
            $relativePath = substr($filePath, strlen(realpath($dirPath)) + 1);
            $relativePath = str_replace('\\', '/', $relativePath);

            $zipPath = !empty($zipSubdir) ? rtrim($zipSubdir, '/') . '/' . $relativePath : $relativePath;
            $zip->addFile($filePath, $zipPath);
            $count++;
        }

        return $count;
    }
}
