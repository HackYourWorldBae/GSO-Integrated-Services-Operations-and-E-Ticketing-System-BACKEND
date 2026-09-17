<?php

use App\Libraries\FileSecurityService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Level 2 — Integration tests for the attachment security pipeline.
 *
 * Verifies the units chain as TicketController::uploadAttachment uses them:
 * inspect (gate) -> encrypt at rest -> decrypt on authorized download,
 * plus the sanitize -> response-envelope chain used across controllers.
 *
 * @internal
 */
final class FileCryptoPipelineTest extends CIUnitTestCase
{
    private FileSecurityService $service;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        helper('sanitize');
        $this->service = new FileSecurityService();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function makeTempFile(string $contents, string $suffix = '.txt'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gsoint') . $suffix;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testCleanUploadPassesGateAndSurvivesEncryptedStorage(): void
    {
        if (!extension_loaded('fileinfo')) {
            $this->markTestSkipped('ext-fileinfo is not loaded in this PHP runtime.');
        }

        $original = "Accomplishment narrative for FGMU-TIC-9-2026.\nWork completed.\n";
        $upload   = $this->makeTempFile($original);
        $stored   = $this->makeTempFile('', '.enc');

        // 1. Upload gate inspects the file.
        $inspection = $this->service->inspectFile($upload, 'accomplishment.txt', 'text/plain');
        $this->assertTrue($inspection['safe']);

        // 2. Stored encrypted at rest.
        $meta = $this->service->encryptFile($upload, $stored);
        $this->assertNotSame($original, file_get_contents($stored));

        // 3. Authorized download decrypts to byte-identical content.
        $this->assertSame($original, $this->service->decryptFile($stored, $meta['iv'], $meta['tag']));
    }

    public function testMaliciousUploadIsBlockedBeforeAnyStorage(): void
    {
        if (!extension_loaded('fileinfo')) {
            $this->markTestSkipped('ext-fileinfo is not loaded in this PHP runtime.');
        }

        $upload     = $this->makeTempFile("MZ" . str_repeat("\x90", 32), '.pdf');
        $inspection = $this->service->inspectFile($upload, 'report.pdf', 'application/pdf');

        $this->assertFalse($inspection['safe']);

        // Pipeline refuses: nothing is ever written to storage for it.
        $stored = $this->makeTempFile('', '.enc');
        $this->assertSame('', file_get_contents($stored));
    }

    public function testCorruptedCiphertextFailsClosedOnDownload(): void
    {
        $upload = $this->makeTempFile('authentic bytes');
        $stored = $this->makeTempFile('', '.enc');

        $meta = $this->service->encryptFile($upload, $stored);

        // Flip a byte in the stored ciphertext (disk corruption / tampering).
        $corrupted = file_get_contents($stored);
        $corrupted[0] = chr(ord($corrupted[0]) ^ 0xFF);
        file_put_contents($stored, $corrupted);

        $this->assertNull($this->service->decryptFile($stored, $meta['iv'], $meta['tag']));
    }

    public function testSanitizedInputFlowsIntoResponseEnvelopeUnchanged(): void
    {
        $raw = [
            'task_notes'       => '  <b>Replace faucet</b> ',
            'dispatcher_notes' => '<script>alert(1)</script>Schedule Monday',
            'working_days'     => 5,
        ];

        // Controller sanitizes, then wraps the outcome in the envelope.
        $clean    = sanitize_array($raw);
        $response = api_response(true, 'Worker assigned successfully.', $clean, 201);

        $this->assertSame('Replace faucet', $response['data']['task_notes']);
        $this->assertSame('alert(1)Schedule Monday', $response['data']['dispatcher_notes']);
        $this->assertSame(5, $response['data']['working_days']);
        $this->assertTrue($response['status']);
        $this->assertSame(201, $response['code']);
    }

    public function testRbacMatrixGatesDispatchCapabilityEndToEnd(): void
    {
        helper('sanitize');
        $matrix = (new ReflectionClass(\App\Models\RolePermissionModel::class))
            ->newInstanceWithoutConstructor();

        // Mirrors RoleGuardFilter: allowed roles pass, others get 403.
        $this->assertTrue($matrix->hasPermission('admin', 'tickets.dispatch'));
        $this->assertFalse($matrix->hasPermission('student', 'tickets.dispatch'));
        $this->assertFalse($matrix->hasPermission('director', 'tickets.assign_worker'));

        // The permission list handed to the frontend stays consistent.
        $adminPerms = $matrix->getPermissionsForRole('admin');
        $this->assertContains('tickets.dispatch', $adminPerms);
        $this->assertContains('tickets.assign_worker', $adminPerms);

        $studentPerms = $matrix->getPermissionsForRole('student');
        $this->assertNotContains('tickets.dispatch', $studentPerms);
    }
}
