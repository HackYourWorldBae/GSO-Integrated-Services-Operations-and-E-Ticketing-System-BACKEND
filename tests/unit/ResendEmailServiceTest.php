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

    public function testReturnsErrorForEmptySsuAdminRecipients(): void
    {
        $service = new ResendEmailService();
        $result  = $service->sendSsuIncidentAlertToAdmins([], ['where' => 'Main Gate'], 'SSU-2026-0001');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No SSU Admin email', $result['message']);
    }

    public function testGetOptedInUnitAdminsReturnsArray(): void
    {
        $service = new ResendEmailService();
        $admins  = $service->getOptedInUnitAdmins(1, ['admin', 'staff']);

        $this->assertIsArray($admins);
        foreach ($admins as $admin) {
            $this->assertArrayHasKey('id', $admin);
            $this->assertArrayHasKey('email', $admin);
            $this->assertArrayHasKey('name', $admin);
            $this->assertArrayHasKey('role', $admin);
        }
    }

    public function testGetOptedInUnitEmailsReturnsStrings(): void
    {
        $service = new ResendEmailService();
        $emails  = $service->getOptedInUnitEmails(1, ['admin', 'staff']);

        $this->assertIsArray($emails);
        foreach ($emails as $email) {
            $this->assertIsString($email);
            $this->assertNotEmpty($email);
        }
    }

    public function testSystemSettingFallbackWhenDbUnavailable(): void
    {
        $settingModel = new \App\Models\SystemSettingModel();
        $value = $settingModel->getSetting('non_existent_key_123', 'fallback_val');
        $this->assertSame('fallback_val', $value);
    }
}

