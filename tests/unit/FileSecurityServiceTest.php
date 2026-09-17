<?php

use App\Libraries\FileSecurityService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Level 1 — Unit tests for upload inspection and file encryption.
 *
 * Covers App\Libraries\FileSecurityService::inspectFile(),
 * encryptFile(), decryptFile(), encryptSelfContained() and
 * decryptSelfContained(), which guard every ticket attachment and
 * accomplishment upload in the system.
 *
 * @internal
 */
final class FileSecurityServiceTest extends CIUnitTestCase
{
    private FileSecurityService $service;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
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
        $path = tempnam(sys_get_temp_dir(), 'gso') . $suffix;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Magic-byte verification needs ext-fileinfo. Deployment PHP ships it,
     * but when it is missing the inspection tests cannot run — skip loudly
     * instead of failing on environment, not on code.
     */
    private function requireFileinfo(): void
    {
        if (!extension_loaded('fileinfo')) {
            $this->markTestSkipped('ext-fileinfo is not loaded in this PHP runtime.');
        }
    }

    public function testMissingFileIsUnsafe(): void
    {
        $result = $this->service->inspectFile('/nonexistent/path/file.txt', 'file.txt');

        $this->assertFalse($result['safe']);
        $this->assertNotEmpty($result['reason']);
    }

    public function testCleanTextFilePassesInspection(): void
    {
        $this->requireFileinfo();
        $path   = $this->makeTempFile("Quarterly maintenance summary.\nAll units operational.\n");
        $result = $this->service->inspectFile($path, 'summary.txt', 'text/plain');

        $this->assertTrue($result['safe']);
        $this->assertNull($result['reason']);
    }

    public function testCleanPngPassesInspection(): void
    {
        $this->requireFileinfo();
        // Minimal valid PNG header recognized by finfo as image/png.
        $path   = $this->makeTempFile("\x89PNG\r\n\x1a\n" . str_repeat("\x00", 64), '.png');
        $result = $this->service->inspectFile($path, 'damage.png', 'image/png');

        $this->assertTrue($result['safe']);
    }

    public function testDangerousExtensionsAreBlocked(): void
    {
        foreach (['exploit.php', 'run.exe', 'payload.js', 'script.ps1', 'prog.jar'] as $name) {
            $path   = $this->makeTempFile('harmless content');
            $result = $this->service->inspectFile($path, $name);

            $this->assertFalse($result['safe'], "{$name} should be blocked");
            $this->assertStringContainsString('prohibited', $result['reason']);
        }
    }

    public function testUppercaseDangerousExtensionIsBlocked(): void
    {
        $path   = $this->makeTempFile('harmless content');
        $result = $this->service->inspectFile($path, 'SHELL.PHP');

        $this->assertFalse($result['safe']);
    }

    public function testDisguisedWindowsExecutableIsBlocked(): void
    {
        $this->requireFileinfo();
        $path   = $this->makeTempFile("MZ" . str_repeat("\x90", 64), '.pdf');
        $result = $this->service->inspectFile($path, 'report.pdf', 'application/pdf');

        $this->assertFalse($result['safe']);
        $this->assertStringContainsString('MZ', $result['reason']);
    }

    public function testDisguisedLinuxExecutableIsBlocked(): void
    {
        $this->requireFileinfo();
        $path   = $this->makeTempFile("\x7fELF" . str_repeat("\x00", 64), '.pdf');
        $result = $this->service->inspectFile($path, 'report.pdf', 'application/pdf');

        $this->assertFalse($result['safe']);
        $this->assertStringContainsString('ELF', $result['reason']);
    }

    public function testEmbeddedPhpTagIsBlocked(): void
    {
        $this->requireFileinfo();
        $path   = $this->makeTempFile("Invoice details\n<?php echo 'pwned'; ?>\nTotal: 100\n");
        $result = $this->service->inspectFile($path, 'invoice.txt', 'text/plain');

        $this->assertFalse($result['safe']);
        $this->assertStringContainsString('PHP', $result['reason']);
    }

    public function testEmbeddedScriptTagIsBlocked(): void
    {
        $this->requireFileinfo();
        $path   = $this->makeTempFile("Notes\n<script>alert('xss')</script>\nDone\n");
        $result = $this->service->inspectFile($path, 'notes.txt', 'text/plain');

        $this->assertFalse($result['safe']);
        $this->assertStringContainsString('script', $result['reason']);
    }

    public function testUnverifiableBinaryWithUnknownExtensionIsBlocked(): void
    {
        $this->requireFileinfo();
        $path   = $this->makeTempFile("\x00\x01\x02\x03\x04\x05binary-blob", '.bin');
        $result = $this->service->inspectFile($path, 'blob.bin', 'application/octet-stream');

        $this->assertFalse($result['safe']);
    }

    public function testEncryptDecryptRoundTripPreservesBytes(): void
    {
        $source = $this->makeTempFile("Confidential job order contents \x00\xff binary-safe.");
        $dest   = $this->makeTempFile('', '.enc');

        $meta = $this->service->encryptFile($source, $dest);

        $this->assertArrayHasKey('iv', $meta);
        $this->assertArrayHasKey('tag', $meta);
        $this->assertSame(24, strlen($meta['iv']));  // 12 bytes hex
        $this->assertSame(32, strlen($meta['tag'])); // 16 bytes hex
        $this->assertNotSame(file_get_contents($source), file_get_contents($dest));

        $this->assertSame(
            file_get_contents($source),
            $this->service->decryptFile($dest, $meta['iv'], $meta['tag'])
        );
    }

    public function testDecryptWithTamperedTagFailsClosed(): void
    {
        $source = $this->makeTempFile('authentic payload');
        $dest   = $this->makeTempFile('', '.enc');

        $meta = $this->service->encryptFile($source, $dest);

        $badTag = str_repeat('0', 32);
        $this->assertNotSame($badTag, $meta['tag']);
        $this->assertNull($this->service->decryptFile($dest, $meta['iv'], $badTag));
    }

    public function testDecryptMissingFileReturnsNull(): void
    {
        $this->assertNull($this->service->decryptFile('/nonexistent/cipher.enc', str_repeat('0', 24), str_repeat('0', 32)));
    }

    public function testEncryptMissingSourceThrows(): void
    {
        // file_get_contents() raises a warning first: under a strict error
        // handler (tests, CI4 debug) that surfaces as ErrorException, while
        // production flow reaches the RuntimeException guard. Both fail closed.
        try {
            $this->service->encryptFile('/nonexistent/source.txt', $this->makeTempFile('', '.enc'));
            $this->fail('Encrypting a missing source file must fail.');
        } catch (RuntimeException | ErrorException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function testSelfContainedRoundTrip(): void
    {
        $source = $this->makeTempFile('self-contained secret payload');
        $dest   = $this->makeTempFile('', '.gsoenc');

        $this->service->encryptSelfContained($source, $dest);

        $this->assertSame('self-contained secret payload', $this->service->decryptSelfContained($dest));
    }

    public function testSelfContainedDecryptPassesThroughPlainFiles(): void
    {
        $plain = $this->makeTempFile('already plain text');

        $this->assertSame('already plain text', $this->service->decryptSelfContained($plain));
    }

    public function testSelfContainedDecryptMissingFileReturnsNull(): void
    {
        $this->assertNull($this->service->decryptSelfContained('/nonexistent/file.gsoenc'));
    }
}
