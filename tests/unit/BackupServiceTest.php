<?php

use App\Libraries\BackupService;
use App\Libraries\GoogleDriveService;
use App\Models\AccountActivityLogModel;
use App\Models\SystemBackupModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Level 1 — Unit tests for Database Backup and Restore Service.
 *
 * Covers App\Libraries\BackupService, App\Libraries\GoogleDriveService,
 * and App\Models\SystemBackupModel.
 *
 * @internal
 */
final class BackupServiceTest extends CIUnitTestCase
{
    private GoogleDriveService $driveService;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveService = new GoogleDriveService();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function createTempFile(string $contents, string $suffix = '.sql'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gso_test_') . $suffix;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }

    // =========================================================================
    // SystemBackupModel Contract & Configuration Tests
    // =========================================================================

    public function testModelConfiguredWithCorrectTableAndPrimaryKey(): void
    {
        $backupModel = (new ReflectionClass(SystemBackupModel::class))
            ->newInstanceWithoutConstructor();

        $this->assertSame('system_backups', $backupModel->table);
        $this->assertSame('id', $backupModel->primaryKey);
        $this->assertSame('array', $backupModel->returnType);
        $this->assertTrue($backupModel->useTimestamps);
    }

    public function testModelAllowedFieldsMatchSchema(): void
    {
        $backupModel = (new ReflectionClass(SystemBackupModel::class))
            ->newInstanceWithoutConstructor();

        $expectedFields = [
            'file_name',
            'file_path',
            'file_size_bytes',
            'backup_type',
            'tables_included',
            'google_drive_file_id',
            'google_drive_link',
            'google_drive_status',
            'google_drive_error',
            'status',
            'notes',
            'created_by',
        ];

        foreach ($expectedFields as $field) {
            $this->assertContains(
                $field,
                $backupModel->allowedFields,
                "SystemBackupModel must permit '{$field}' in allowedFields"
            );
        }
    }

    public function testGetBackupsWithUserDoesNotReferenceObsoleteNameColumn(): void
    {
        $backupModelReflection = new ReflectionClass(SystemBackupModel::class);
        $method = $backupModelReflection->getMethod('getBackupsWithUser');

        $this->assertNotNull($method);

        // Read source code of getBackupsWithUser to ensure users.name is not referenced
        $filename = $backupModelReflection->getFileName();
        $source = file_get_contents($filename);
        $this->assertStringNotContainsString('users.name', $source, "Must not query 'users.name'; use CONCAT of users.first_name and users.last_name instead.");
    }

    // =========================================================================
    // GoogleDriveService Contract & Resilience Tests
    // =========================================================================

    public function testGoogleDriveServiceInitializesSafely(): void
    {
        $this->assertInstanceOf(GoogleDriveService::class, $this->driveService);
        $this->assertIsBool($this->driveService->isConfigured());
    }

    public function testGoogleDriveServiceConnectionTestReturnsContract(): void
    {
        $result = $this->driveService->testConnection();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['success']);
        $this->assertIsString($result['message']);
    }

    public function testGoogleDriveUploadRejectsMissingLocalFile(): void
    {
        $nonExistentPath = WRITEPATH . 'backups/non_existent_snapshot_file_9999.sql';

        $result = $this->driveService->uploadFile($nonExistentPath, 'test.sql');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertNull($result['file_id']);
        $this->assertNotEmpty($result['error']);
    }

    public function testGoogleDriveDownloadWithEmptyIdReturnsError(): void
    {
        $destPath = $this->createTempFile('');

        $result = $this->driveService->downloadFile('', $destPath);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    public function testGoogleDriveDeleteWithEmptyIdReturnsFalse(): void
    {
        $result = $this->driveService->deleteFile('');
        $this->assertFalse($result);
    }

    // =========================================================================
    // BackupService Safe Restoration & Error Boundary Tests
    // =========================================================================

    public function testRestoreBackupFailsGracefullyWhenRecordNotFound(): void
    {
        $mockModel = $this->createMock(SystemBackupModel::class);
        $mockModel->method('find')->with(999)->willReturn(null);

        $service = new BackupService($mockModel, $this->driveService);
        $result  = $service->restoreBackup(999, 'test-superadmin-uuid');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('Backup record not found.', $result['message']);
    }

    public function testRestoreBackupFailsWhenLocalFileMissingAndDriveNotConfigured(): void
    {
        $mockModel = $this->createMock(SystemBackupModel::class);
        $mockModel->method('find')->with(1)->willReturn([
            'id'                   => 1,
            'file_name'            => 'backup_test.sql',
            'file_path'            => WRITEPATH . 'backups/missing_local_file.sql',
            'google_drive_file_id' => null,
        ]);

        $service = new BackupService($mockModel, $this->driveService);
        $result  = $service->restoreBackup(1, 'test-superadmin-uuid');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('Backup file not found locally or on Google Drive.', $result['message']);
    }

    public function testRestoreFromUploadRejectsNonExistentFile(): void
    {
        $missingPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing_' . uniqid() . '.sql';

        $service = new BackupService();
        $result  = $service->restoreFromUpload($missingPath, 'missing.sql', 'test-uuid');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('Uploaded file is invalid or empty.', $result['message']);
    }

    public function testRestoreFromUploadRejectsZeroByteEmptyFile(): void
    {
        $emptyPath = $this->createTempFile('');

        $service = new BackupService();
        $result  = $service->restoreFromUpload($emptyPath, 'empty.sql', 'test-uuid');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('Uploaded file is invalid or empty.', $result['message']);
    }

    public function testSyncToGoogleDriveFailsWhenRecordNotFound(): void
    {
        $mockModel = $this->createMock(SystemBackupModel::class);
        $mockModel->method('find')->with(999)->willReturn(null);

        $service = new BackupService($mockModel, $this->driveService);
        $result  = $service->syncToGoogleDrive(999);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('Backup record not found.', $result['message']);
    }

    public function testSyncToGoogleDriveFailsWhenLocalFileDoesNotExist(): void
    {
        $mockModel = $this->createMock(SystemBackupModel::class);
        $mockModel->method('find')->with(1)->willReturn([
            'id'        => 1,
            'file_name' => 'backup_test.sql',
            'file_path' => WRITEPATH . 'backups/missing_snapshot_12345.sql',
        ]);

        $service = new BackupService($mockModel, $this->driveService);
        $result  = $service->syncToGoogleDrive(1);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('Local backup file does not exist on disk.', $result['message']);
    }

    public function testSyncToGoogleDriveUpdatesRecordOnSuccessfulUpload(): void
    {
        $sampleFile = $this->createTempFile('-- sample SQL content');

        $mockModel = $this->createMock(SystemBackupModel::class);
        $mockModel->method('find')->with(1)->willReturn([
            'id'        => 1,
            'file_name' => basename($sampleFile),
            'file_path' => $sampleFile,
        ]);

        $mockModel->expects($this->once())
            ->method('update')
            ->with(1, [
                'google_drive_file_id' => 'mock-drive-id-12345',
                'google_drive_link'    => 'https://drive.google.com/file/d/mock-drive-id-12345/view',
                'google_drive_status'  => 'uploaded',
                'google_drive_error'   => null,
            ]);

        $mockDrive = $this->createMock(GoogleDriveService::class);
        $mockDrive->method('isConfigured')->willReturn(true);
        $mockDrive->method('uploadFile')->willReturn([
            'success'  => true,
            'file_id'  => 'mock-drive-id-12345',
            'web_link' => 'https://drive.google.com/file/d/mock-drive-id-12345/view',
            'error'    => null,
        ]);

        $service = new BackupService($mockModel, $mockDrive);
        $result  = $service->syncToGoogleDrive(1);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('mock-drive-id-12345', $result['file_id']);
        $this->assertSame('https://drive.google.com/file/d/mock-drive-id-12345/view', $result['web_link']);
    }

    public function testDeleteBackupFailsGracefullyWhenRecordNotFound(): void
    {
        $mockModel = $this->createMock(SystemBackupModel::class);
        $mockModel->method('find')->with(999)->willReturn(null);

        $service = new BackupService($mockModel, $this->driveService);
        $result  = $service->deleteBackup(999, 'test-uuid');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('Backup not found.', $result['message']);
    }

    public function testDeleteBackupCleansUpDriveAndLocalFileAndDatabase(): void
    {
        $sampleFile = $this->createTempFile('-- sample SQL content');

        $mockModel = $this->createMock(SystemBackupModel::class);
        $mockModel->method('find')->with(1)->willReturn([
            'id'                   => 1,
            'file_name'            => basename($sampleFile),
            'file_path'            => $sampleFile,
            'google_drive_file_id' => 'mock-drive-id-777',
        ]);
        $mockModel->expects($this->once())->method('delete')->with(1);

        $mockDrive = $this->createMock(GoogleDriveService::class);
        $mockDrive->method('isConfigured')->willReturn(true);
        $mockDrive->expects($this->once())->method('deleteFile')->with('mock-drive-id-777')->willReturn(true);

        $mockLog = $this->createMock(AccountActivityLogModel::class);

        $service = new BackupService($mockModel, $mockDrive, $mockLog);
        $result  = $service->deleteBackup(1, 'superadmin-uuid');

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('Backup deleted successfully.', $result['message']);
        $this->assertFileDoesNotExist($sampleFile);
    }

    public function testBackupDirectoryIsCreatedAndWritable(): void
    {
        $backupDir = WRITEPATH . 'backups';

        $this->assertDirectoryExists($backupDir);
        $this->assertIsWritable($backupDir);
    }

    public function testFormatGoogleDriveErrorParsesStorageQuotaExceededJson(): void
    {
        $rawGoogleJson = json_encode([
            'error' => [
                'code' => 403,
                'message' => 'Service Accounts do not have storage. Leverage shared drives or use OAuth delegation instead',
                'errors' => [
                    [
                        'message' => 'Service Accounts do not have storage.',
                        'domain'  => 'usageLimits',
                        'reason'  => 'storageQuotaExceeded',
                    ]
                ]
            ]
        ]);

        $exception = new Exception($rawGoogleJson, 403);
        $formatted = $this->driveService->formatGoogleDriveError($exception);

        $this->assertStringContainsString('Google Drive storage quota error', $formatted);
        $this->assertStringContainsString('Service Accounts do not have private drive storage', $formatted);
        $this->assertStringContainsString('Personal Google Drive (OAuth 2.0)', $formatted);
    }

    public function testFormatGoogleDriveErrorHandlesFolderNotFound(): void
    {
        $rawGoogleJson = json_encode([
            'error' => [
                'code' => 404,
                'message' => 'File not found: 1invalidFolderId',
            ]
        ]);

        $exception = new Exception($rawGoogleJson, 404);
        $formatted = $this->driveService->formatGoogleDriveError($exception);

        $this->assertStringContainsString('Google Drive target folder was not found', $formatted);
    }

    public function testUploadFileWithoutFolderIdReturnsDescriptiveServiceAccountWarning(): void
    {
        // When initialized without folder ID, uploadFile must reject before attempting root upload
        $reflection = new ReflectionClass($this->driveService);
        $folderProp = $reflection->getProperty('folderId');
        $initProp   = $reflection->getProperty('initialized');
        $serviceProp = $reflection->getProperty('service');

        $folderProp->setValue($this->driveService, null);
        $initProp->setValue($this->driveService, true);
        $serviceProp->setValue($this->driveService, $this->createMock(\Google\Service\Drive::class));

        $tempSql = $this->createTempFile('-- sample sql');
        $result = $this->driveService->uploadFile($tempSql, 'backup.sql');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Google Drive Folder ID is required for Service Accounts', $result['error']);
    }

    public function testCreateOAuthAuthUrlGeneratesValidGoogleConsentUrl(): void
    {
        $url = $this->driveService->createOAuthAuthUrl(
            'test-client-id.apps.googleusercontent.com',
            'test-secret',
            'https://backend.hywb.online/api/v1/superadmin/backups/google-oauth-callback',
            'state123'
        );

        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString('test-client-id.apps.googleusercontent.com', $url);
        $this->assertStringContainsString('google-oauth-callback', $url);
        $this->assertStringContainsString('access_type=offline', $url);
    }

    public function testUploadFileWithOAuthAllowsUploadWithoutFolderId(): void
    {
        $reflection = new ReflectionClass($this->driveService);
        $folderProp   = $reflection->getProperty('folderId');
        $initProp     = $reflection->getProperty('initialized');
        $authTypeProp = $reflection->getProperty('authType');
        $serviceProp  = $reflection->getProperty('service');

        $folderProp->setValue($this->driveService, null);
        $initProp->setValue($this->driveService, true);
        $authTypeProp->setValue($this->driveService, 'oauth');

        // Mock Drive service with files resource
        $mockFiles = $this->createMock(\Google\Service\Drive\Resource\Files::class);
        $mockDriveFile = new \Google\Service\Drive\DriveFile(['id' => 'oauth_uploaded_id', 'webViewLink' => 'https://drive.google.com/file/d/test']);
        $mockFiles->method('create')->willReturn($mockDriveFile);

        $mockService = $this->createMock(\Google\Service\Drive::class);
        $mockService->files = $mockFiles;
        $serviceProp->setValue($this->driveService, $mockService);

        $tempSql = $this->createTempFile('-- sample sql');
        $result = $this->driveService->uploadFile($tempSql, 'backup.sql');

        $this->assertTrue($result['success']);
        $this->assertSame('oauth_uploaded_id', $result['file_id']);
    }
}

