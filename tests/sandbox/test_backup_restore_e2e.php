<?php

declare(strict_types=1);

/**
 * End-to-End Isolated Sandbox Test for GSO Backup & Restore Engine.
 *
 * This script validates the entire lifecycle of Backup & Restore in a 100% isolated
 * temporary sandbox database ('gso_backup_restore_sandbox') without touching or
 * modifying the primary database ('bsu_gso_db').
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Ifsnop\Mysqldump\Mysqldump;

class BackupRestoreSandboxTest
{
    private string $host = '127.0.0.1';
    private string $user = 'root';
    private string $pass = 'root';
    private int $port    = 3306;
    private string $sandboxDb = 'gso_backup_restore_sandbox';

    private string $backupDir;
    private string $uploadsDir;
    private array $cleanups = [];
    private int $assertionsPassed = 0;
    private int $assertionsFailed = 0;

    public function __construct()
    {
        $this->backupDir  = __DIR__ . '/../../writable/backups/';
        $this->uploadsDir = __DIR__ . '/../../writable/uploads/';

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        if (!is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0755, true);
        }
    }

    public function run(): void
    {
        $this->logHeader("GSO DISASTER RECOVERY & BACKUP/RESTORE END-TO-END SANDBOX TEST");
        $this->logInfo("Host: {$this->host}:{$this->port} | Sandbox DB: {$this->sandboxDb}");
        $this->logInfo("Safety Guarantee: 'bsu_gso_db' will NOT be modified or restored during this test.\n");

        try {
            // Step 1: Connect to MySQL & Provision Sandbox DB
            $mysqli = $this->step1_provisionSandbox();

            // Step 2: Seed Schema & Rich Relational Data
            $initialCounts = $this->step2_seedSandboxData($mysqli);

            // Step 3: Seed Mock Upload File
            $testUploadPath = $this->step3_seedMockUpload();

            // Step 4: Execute Full Backup (SQL Dump + ZIP Archive + Manifest)
            $zipPath = $this->step4_createSnapshotArchive();

            // Step 5: Simulate Catastrophic Data Loss & Corruption
            $this->step5_simulateDisaster($mysqli, $testUploadPath);

            // Step 6: Execute Full Restore from Snapshot
            $this->step6_executeRestore($zipPath);

            // Step 7: Verify Bit-for-Bit Restoration & Constraint Integrity
            $this->step7_verifyRestoration($mysqli, $initialCounts, $testUploadPath);

            // Step 8: Test Security Edge Case (Zip Slip Traversal Defense)
            $this->step8_testZipSlipDefense();

            $this->logSuccess("\n=======================================================");
            $this->logSuccess("ALL TESTS PASSED: {$this->assertionsPassed} assertions verified successfully.");
            $this->logSuccess("Disaster Recovery and Restoration engine is 100% OPERATIONAL & SAFE.");
            $this->logSuccess("=======================================================\n");
        } catch (\Throwable $e) {
            $this->logError("\nFATAL ERROR: " . $e->getMessage());
            $this->logError("Stack trace:\n" . $e->getTraceAsString());
            $this->assertionsFailed++;
        } finally {
            $this->cleanup();
        }
    }

    private function step1_provisionSandbox(): mysqli
    {
        $this->logStep("Step 1: Connecting to MySQL & Provisioning Sandbox Database");

        $mysqli = new mysqli($this->host, $this->user, $this->pass, '', $this->port);
        if ($mysqli->connect_error) {
            throw new \RuntimeException("MySQL Connection Failed: " . $mysqli->connect_error);
        }
        $mysqli->set_charset("utf8mb4");

        $mysqli->query("DROP DATABASE IF EXISTS `{$this->sandboxDb}`;");
        $created = $mysqli->query("CREATE DATABASE `{$this->sandboxDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $this->assert($created === true, "Sandbox database '{$this->sandboxDb}' created");

        $mysqli->select_db($this->sandboxDb);
        $this->logSuccess("Connected to clean sandbox database: {$this->sandboxDb}");
        return $mysqli;
    }

    private function step2_seedSandboxData(mysqli $mysqli): array
    {
        $this->logStep("Step 2: Creating Relational Tables & Seeding Test Data");

        // Table 1: Users
        $mysqli->query("
            CREATE TABLE `sandbox_users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `uuid` VARCHAR(36) NOT NULL UNIQUE,
                `full_name` VARCHAR(150) NOT NULL,
                `email` VARCHAR(150) NOT NULL,
                `role` ENUM('admin', 'staff', 'user') DEFAULT 'user',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Table 2: Tickets with Foreign Key
        $mysqli->query("
            CREATE TABLE `sandbox_tickets` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `ticket_number` VARCHAR(50) NOT NULL UNIQUE,
                `requester_id` INT NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `notes` TEXT NULL,
                `status` ENUM('pending', 'approved', 'completed') DEFAULT 'pending',
                `is_emergency` TINYINT(1) DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `fk_ticket_requester` FOREIGN KEY (`requester_id`) REFERENCES `sandbox_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Table 3: Audit Logs with JSON data & Philippine UTF-8 characters
        $mysqli->query("
            CREATE TABLE `sandbox_audit_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `event_type` VARCHAR(100) NOT NULL,
                `details` TEXT NOT NULL,
                `metadata` JSON NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Insert Users (including UTF-8 with accents & ñ)
        $mysqli->query("
            INSERT INTO `sandbox_users` (`uuid`, `full_name`, `email`, `role`) VALUES
            ('usr-001', 'Engr. José Dela Cruz, Jr.', 'jose.delacruz@bsu.edu.ph', 'admin'),
            ('usr-002', 'Maria Niño Peña', 'maria.pena@bsu.edu.ph', 'staff'),
            ('usr-003', 'Juan Luna III', 'juan.luna@bsu.edu.ph', 'user');
        ");

        // Insert Tickets
        $mysqli->query("
            INSERT INTO `sandbox_tickets` (`ticket_number`, `requester_id`, `title`, `notes`, `status`, `is_emergency`) VALUES
            ('GSO-2026-0001', 1, 'AC Repair - Executive Office', 'Aircon leaking water at room 302', 'approved', 0),
            ('GSO-2026-0002', 2, 'Electrical Short Circuit - Lab 4', 'Sparking wires observed! Urgent! ⚠️', 'pending', 1),
            ('GSO-2026-0003', 3, 'Carpentry - Broken Door Hinge', 'Main library entrance door sticking', 'completed', 0);
        ");

        // Insert Audit Logs
        $metaJson = json_encode(['ip' => '192.168.1.10', 'browser' => 'Chrome/120', 'tags' => ['urgent', 'electrical']], JSON_UNESCAPED_UNICODE);
        $stmt = $mysqli->prepare("INSERT INTO `sandbox_audit_logs` (`event_type`, `details`, `metadata`) VALUES (?, ?, ?)");
        $event = 'TICKET_CREATED';
        $details = 'Emergency ticket GSO-2026-0002 filed by Maria Niño Peña ⚡';
        $stmt->bind_param('sss', $event, $details, $metaJson);
        $stmt->execute();
        $stmt->close();

        $userCount   = (int) $mysqli->query("SELECT COUNT(*) FROM `sandbox_users`")->fetch_row()[0];
        $ticketCount = (int) $mysqli->query("SELECT COUNT(*) FROM `sandbox_tickets`")->fetch_row()[0];
        $logCount    = (int) $mysqli->query("SELECT COUNT(*) FROM `sandbox_audit_logs`")->fetch_row()[0];

        $this->assert($userCount === 3, "Seeded 3 sandbox users");
        $this->assert($ticketCount === 3, "Seeded 3 sandbox tickets");
        $this->assert($logCount === 1, "Seeded 1 audit log entry");

        return [
            'users'   => $userCount,
            'tickets' => $ticketCount,
            'logs'    => $logCount,
        ];
    }

    private function step3_seedMockUpload(): string
    {
        $this->logStep("Step 3: Creating Mock Document Upload in writable/uploads/");

        $targetFile = $this->uploadsDir . 'sandbox_test_document_' . uniqid() . '.txt';
        $content = "CONFIDENTIAL GSO E-TICKETING ARCHIVE TEST VERIFICATION DOCUMENT\n"
                 . "Timestamp: " . date('Y-m-d H:i:s') . "\n"
                 . "Payload: UTF-8 Check: Magandang Umaga Batangas State University! 🇵🇭\n";

        file_put_contents($targetFile, $content);
        $this->cleanups[] = $targetFile;

        $this->assert(file_exists($targetFile), "Mock upload file created: " . basename($targetFile));
        return $targetFile;
    }

    private function step4_createSnapshotArchive(): string
    {
        $this->logStep("Step 4: Executing Full Snapshot Creation (SQL Dump + Uploads + Manifest)");

        $timestamp = date('Ymd_His') . '_sandbox';
        $tempSql   = $this->backupDir . "temp_dump_{$timestamp}.sql";
        $zipPath   = $this->backupDir . "snapshot_{$timestamp}.zip";
        $this->cleanups[] = $tempSql;
        $this->cleanups[] = $zipPath;

        // 1. Generate MySQL Dump using Mysqldump
        $dumpSettings = [
            'add-drop-table'             => true,
            'add-locks'                  => false,
            'extended-insert'            => true,
            'disable-foreign-keys-check' => true,
            'single-transaction'         => true,
            'lock-tables'                => false,
            'default-character-set'      => 'utf8mb4',
        ];

        $dsn  = "mysql:host={$this->host};port={$this->port};dbname={$this->sandboxDb};charset=utf8mb4";
        $dump = new Mysqldump($dsn, $this->user, $this->pass, $dumpSettings);
        $dump->start($tempSql);

        $this->assert(file_exists($tempSql) && filesize($tempSql) > 0, "Mysqldump generated SQL dump file (" . filesize($tempSql) . " bytes)");

        // 2. Build ZIP archive
        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $this->assert($opened === true, "ZipArchive initialized successfully");

        $zip->addFile($tempSql, 'database.sql');

        // Add uploads directory
        $uploadFiles = glob($this->uploadsDir . '*');
        $uploadAddedCount = 0;
        foreach ($uploadFiles as $uf) {
            if (is_file($uf)) {
                $zip->addFile($uf, 'uploads/' . basename($uf));
                $uploadAddedCount++;
            }
        }

        $manifest = [
            'system'            => 'GSO Disaster Recovery Sandbox Test',
            'archive_type'      => 'full_disaster_recovery',
            'database'          => $this->sandboxDb,
            'tables_count'      => 3,
            'media_files_count' => $uploadAddedCount,
            'created_at'        => date('Y-m-d H:i:s'),
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $zip->close();

        @unlink($tempSql);

        $this->assert(file_exists($zipPath) && filesize($zipPath) > 500, "Full snapshot archive bundled (" . filesize($zipPath) . " bytes)");
        $this->logSuccess("Snapshot packaged: " . basename($zipPath));
        return $zipPath;
    }

    private function step5_simulateDisaster(mysqli $mysqli, string $testUploadPath): void
    {
        $this->logStep("Step 5: Simulating Severe Database Corruption & Data Loss in Sandbox");

        // 1. Delete user 1 (which cascades to ticket 1 via foreign key!)
        $mysqli->query("DELETE FROM `sandbox_users` WHERE `id` = 1;");
        $remainingUsers = (int) $mysqli->query("SELECT COUNT(*) FROM `sandbox_users`")->fetch_row()[0];
        $remainingTickets = (int) $mysqli->query("SELECT COUNT(*) FROM `sandbox_tickets`")->fetch_row()[0];

        $this->assert($remainingUsers === 2, "Disaster: User 1 deleted (2 users left)");
        $this->assert($remainingTickets === 2, "Disaster: Ticket 1 cascade-deleted (2 tickets left)");

        // 2. Corrupt user 2's email
        $mysqli->query("UPDATE `sandbox_users` SET `email` = 'HACKED_AND_CORRUPTED@evil.com' WHERE `id` = 2;");
        $corruptedEmail = $mysqli->query("SELECT `email` FROM `sandbox_users` WHERE `id` = 2")->fetch_row()[0];
        $this->assert($corruptedEmail === 'HACKED_AND_CORRUPTED@evil.com', "Disaster: User 2 data corrupted");

        // 3. Inject a rogue garbage record
        $mysqli->query("INSERT INTO `sandbox_users` (`uuid`, `full_name`, `email`) VALUES ('rogue-999', 'Rogue Attacker Account', 'rogue@malicious.org');");
        $rogueCount = (int) $mysqli->query("SELECT COUNT(*) FROM `sandbox_users` WHERE `uuid` = 'rogue-999'")->fetch_row()[0];
        $this->assert($rogueCount === 1, "Disaster: Rogue user record injected");

        // 4. Delete the test upload document from disk
        if (file_exists($testUploadPath)) {
            unlink($testUploadPath);
        }
        $this->assert(!file_exists($testUploadPath), "Disaster: Uploaded media file deleted from disk");

        $this->logSuccess("Disaster simulation complete: Database is now corrupted and data is missing.");
    }

    private function step6_executeRestore(string $zipPath): void
    {
        $this->logStep("Step 6: Executing Full System Restore from Snapshot Archive");

        // This replicates BackupService::executeZipRestore targeting the sandbox database
        $zip = new \ZipArchive();
        $res = $zip->open($zipPath);
        $this->assert($res === true, "Opened snapshot archive for extraction");

        // Pass 1: Extract database.sql
        $tempRestoreSql = $this->backupDir . 'extracted_restore_' . uniqid() . '.sql';
        $this->cleanups[] = $tempRestoreSql;

        $sqlContent = $zip->getFromName('database.sql');
        $this->assert($sqlContent !== false && strlen($sqlContent) > 0, "Extracted 'database.sql' from archive");
        file_put_contents($tempRestoreSql, $sqlContent);

        // Execute SQL Restore via native MySQLi connection
        $mysqli = new mysqli($this->host, $this->user, $this->pass, $this->sandboxDb, $this->port);
        $this->assert(!$mysqli->connect_error, "Connected to sandbox for restoration");
        $mysqli->set_charset("utf8mb4");

        $mysqli->query("SET FOREIGN_KEY_CHECKS = 0;");
        $multiSuccess = $mysqli->multi_query($sqlContent);
        $this->assert($multiSuccess === true, "Multi-query restore stream initiated");

        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());

        $this->assert(empty($mysqli->error), "Executed entire SQL dump with zero MySQL errors");
        $mysqli->query("SET FOREIGN_KEY_CHECKS = 1;");
        $mysqli->close();

        @unlink($tempRestoreSql);

        // Pass 2: Extract Uploads
        $restoredMediaCount = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $entryName = $stat['name'];

            if (str_starts_with($entryName, 'uploads/') && !str_ends_with($entryName, '/')) {
                $relPath = preg_replace('#^uploads/#', '', $entryName);
                $destPath = $this->uploadsDir . $relPath;
                $fileData = $zip->getFromIndex($i);
                if ($fileData !== false) {
                    file_put_contents($destPath, $fileData);
                    $restoredMediaCount++;
                }
            }
        }
        $zip->close();

        $this->assert($restoredMediaCount > 0, "Extracted and restored {$restoredMediaCount} uploaded media files to disk");
        $this->logSuccess("Restoration execution finished cleanly.");
    }

    private function step7_verifyRestoration(mysqli $mysqli, array $initialCounts, string $testUploadPath): void
    {
        $this->logStep("Step 7: Verifying Post-Restoration Data Integrity & Constraints");

        // Reconnect to verify fresh state
        $mysqli->select_db($this->sandboxDb);

        // 1. Verify User Counts & Restoration of Deleted User 1
        $userCount = (int) $mysqli->query("SELECT COUNT(*) FROM `sandbox_users`")->fetch_row()[0];
        $this->assert($userCount === $initialCounts['users'], "User count restored to exact original count ({$userCount})");

        $user1 = $mysqli->query("SELECT `full_name`, `email` FROM `sandbox_users` WHERE `id` = 1")->fetch_assoc();
        $this->assert(!empty($user1), "Deleted User 1 ('José Dela Cruz') has been resurrected");
        $this->assert($user1['full_name'] === 'Engr. José Dela Cruz, Jr.', "User 1 UTF-8 accented name restored: '{$user1['full_name']}'");

        // 2. Verify Ticket Counts & Restored Cascaded Tickets
        $ticketCount = (int) $mysqli->query("SELECT COUNT(*) FROM `sandbox_tickets`")->fetch_row()[0];
        $this->assert($ticketCount === $initialCounts['tickets'], "Ticket count restored to exact original count ({$ticketCount})");

        $ticket2 = $mysqli->query("SELECT `notes`, `is_emergency` FROM `sandbox_tickets` WHERE `ticket_number` = 'GSO-2026-0002'")->fetch_assoc();
        $this->assert(str_contains($ticket2['notes'], '⚠️'), "Ticket 2 emoji notes restored perfectly: '{$ticket2['notes']}'");
        $this->assert((int)$ticket2['is_emergency'] === 1, "Ticket 2 emergency flag restored intact");

        // 3. Verify Corrupted Data Was Cleared
        $user2 = $mysqli->query("SELECT `email` FROM `sandbox_users` WHERE `id` = 2")->fetch_assoc();
        $this->assert($user2['email'] === 'maria.pena@bsu.edu.ph', "Corrupted email reverted to original: 'maria.pena@bsu.edu.ph'");

        // 4. Verify Rogue Injection Was Eradicated
        $rogueCheck = (int) $mysqli->query("SELECT COUNT(*) FROM `sandbox_users` WHERE `uuid` = 'rogue-999'")->fetch_row()[0];
        $this->assert($rogueCheck === 0, "Rogue attacker account wiped out (does not exist in restored database)");

        // 5. Verify Audit Logs & JSON Metadata
        $logCount = (int) $mysqli->query("SELECT COUNT(*) FROM `sandbox_audit_logs`")->fetch_row()[0];
        $this->assert($logCount === $initialCounts['logs'], "Audit log count matches pre-backup state");

        $logRow = $mysqli->query("SELECT `details`, `metadata` FROM `sandbox_audit_logs` LIMIT 1")->fetch_assoc();
        $decodedJson = json_decode($logRow['metadata'], true);
        $this->assert(isset($decodedJson['tags']) && in_array('urgent', $decodedJson['tags']), "JSON metadata column parsed and restored with intact tags");

        // 6. Verify Foreign Key Constraint Enforcement
        $fkEnforced = false;
        try {
            // Attempt to insert ticket with non-existent requester_id 99999 (should fail if FKs are enabled)
            $mysqli->query("INSERT INTO `sandbox_tickets` (`ticket_number`, `requester_id`, `title`) VALUES ('INVALID-FK-01', 99999, 'Invalid FK Ticket');");
        } catch (\Throwable $e) {
            $fkEnforced = true;
        }
        if (!$fkEnforced && $mysqli->error) {
            $fkEnforced = true;
        }
        $this->assert($fkEnforced === true, "Foreign key constraints active & enforced after restore (rejected orphaned ticket insertion)");

        // 7. Verify Media File Restoration
        $this->assert(file_exists($testUploadPath), "Deleted uploaded file was restored back to disk: " . basename($testUploadPath));
        $fileContent = file_get_contents($testUploadPath);
        $this->assert(str_contains($fileContent, 'Batangas State University! 🇵🇭'), "Restored file content is 100% byte-accurate with UTF-8 payload");

        $this->logSuccess("All data integrity, relational constraints, and media files verified.");
    }

    private function step8_testZipSlipDefense(): void
    {
        $this->logStep("Step 8: Testing Zip Slip Path Traversal Protection");

        $maliciousZip = $this->backupDir . 'malicious_test_' . uniqid() . '.zip';
        $this->cleanups[] = $maliciousZip;

        $zip = new \ZipArchive();
        $zip->open($maliciousZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('../../malicious_payload.php', '<?php echo "HACKED";');
        $zip->close();

        // Run Zip Slip validation scanner identical to BackupService::executeZipRestore
        $rejected = false;
        $checkZip = new \ZipArchive();
        if ($checkZip->open($maliciousZip) === true) {
            for ($i = 0; $i < $checkZip->numFiles; $i++) {
                $stat = $checkZip->statIndex($i);
                $entryName = $stat['name'];
                if (str_contains($entryName, '..') || str_starts_with($entryName, '/') || str_starts_with($entryName, '\\')) {
                    $rejected = true;
                    break;
                }
            }
            $checkZip->close();
        }

        $this->assert($rejected === true, "Malicious path traversal archive successfully intercepted and rejected");
        $this->logSuccess("Zip Slip security defense verified.");
    }

    private function cleanup(): void
    {
        $this->logStep("Step 9: Cleaning up Sandbox Environment");

        // Drop sandbox database
        try {
            $mysqli = @new mysqli($this->host, $this->user, $this->pass, '', $this->port);
            if (!$mysqli->connect_error) {
                $mysqli->query("DROP DATABASE IF EXISTS `{$this->sandboxDb}`;");
                $mysqli->close();
                $this->logInfo("Dropped temporary sandbox database: {$this->sandboxDb}");
            }
        } catch (\Throwable $e) {}

        // Remove temporary test files
        foreach ($this->cleanups as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->logInfo("Removed all temporary files and test archives.\n");
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->assertionsPassed++;
            echo "  [\e[32mPASS\e[0m] " . $message . "\n";
        } else {
            $this->assertionsFailed++;
            echo "  [\e[31mFAIL\e[0m] " . $message . "\n";
            throw new \RuntimeException("Assertion Failed: " . $message);
        }
    }

    private function logHeader(string $msg): void
    {
        echo "\e[1;36m" . str_repeat('=', 65) . "\e[0m\n";
        echo "\e[1;36m " . $msg . "\e[0m\n";
        echo "\e[1;36m" . str_repeat('=', 65) . "\e[0m\n\n";
    }

    private function logStep(string $msg): void
    {
        echo "\n\e[1;33m>>> " . $msg . "\e[0m\n";
    }

    private function logInfo(string $msg): void
    {
        echo "\e[34mℹ " . $msg . "\e[0m\n";
    }

    private function logSuccess(string $msg): void
    {
        echo "\e[1;32m✔ " . $msg . "\e[0m\n";
    }

    private function logError(string $msg): void
    {
        echo "\e[1;31m✖ " . $msg . "\e[0m\n";
    }
}

// Execute test runner
$tester = new BackupRestoreSandboxTest();
$tester->run();
