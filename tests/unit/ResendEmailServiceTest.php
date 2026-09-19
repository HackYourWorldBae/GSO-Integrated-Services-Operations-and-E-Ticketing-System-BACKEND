<?php

use App\Libraries\ResendEmailService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for ResendEmailService.
 *
 * @internal
 */
final class ResendEmailServiceTest extends CIUnitTestCase
{
    public function testGracefullySkipsWhenApiKeyUnconfigured(): void
    {
        $service = new ResendEmailService();
        $result  = $service->sendRawEmail('test@example.com', 'Test Subject', '<p>Hello</p>');

        // Without an API key configured in env/db, it must fail safely without throwing an uncaught exception
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
    }

    public function testReturnsErrorForEmptyRecipients(): void
    {
        $service = new ResendEmailService();
        $result  = $service->sendRawEmail([], 'Test Subject', '<p>Hello</p>');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
    }
}
