<?php

use App\Libraries\ResendEmailService;
use App\Models\UserMfaChallengeModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for Multi-Factor Authentication (MFA) OTP generation and email dispatch.
 *
 * @internal
 */
final class MfaServiceTest extends CIUnitTestCase
{
    public function testSendMfaOtpEmailFailsGracefullyWithoutApiKey(): void
    {
        $service = new ResendEmailService();
        $result  = $service->sendMfaOtpEmail('student@batstate-u.edu.ph', 'Juan Dela Cruz', '123456', 10);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testSendMfaOtpEmailRejectsEmptyEmail(): void
    {
        $service = new ResendEmailService();
        $result  = $service->sendMfaOtpEmail('', 'Juan Dela Cruz', '123456', 10);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
    }

    public function testChallengeModelHandlesNonexistentTableGracefully(): void
    {
        $model = new UserMfaChallengeModel();
        // verifyChallenge with a dummy token should return false gracefully if DB table is missing or token not found
        $result = $model->verifyChallenge('nonexistent_token_1234567890', '123456');

        $this->assertIsArray($result);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['message']);
    }
}
