<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Libraries\ResendEmailService;
use App\Models\SystemSettingModel;
use App\Models\AccountActivityLogModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * SystemSettingController
 *
 * Provides administrative endpoints to configure system settings,
 * including Resend.com transactional email service credentials and tests.
 */
class SystemSettingController extends BaseController
{
    private SystemSettingModel $settingModel;
    private AccountActivityLogModel $activityLogModel;
    private ResendEmailService $emailService;

    public function __construct()
    {
        $this->settingModel     = new SystemSettingModel();
        $this->activityLogModel = new AccountActivityLogModel();
        $this->emailService     = new ResendEmailService();
    }

    /**
     * Get current Resend email configuration.
     * GET /api/v1/settings/resend
     */
    public function getResendConfig(): ResponseInterface
    {
        $isConfigured = $this->settingModel->isResendConfigured();
        $maskedKey    = $this->settingModel->getMaskedResendKey();
        $fromEmail    = $this->settingModel->getSetting('resend_from_email', 'GSO E-Ticketing <onboarding@resend.dev>');
        $enabled      = $this->settingModel->getSetting('resend_notifications_enabled', '1') !== '0';

        return $this->successResponse('Resend configuration retrieved.', [
            'is_configured'         => $isConfigured,
            'masked_api_key'        => $maskedKey,
            'from_email'            => $fromEmail,
            'notifications_enabled' => $enabled,
        ]);
    }

    /**
     * Update Resend email configuration.
     * POST /api/v1/settings/resend
     * Accepts:
     * - api_key (string, optional/nullable, starts with 're_')
     * - from_email (string, optional)
     * - notifications_enabled (bool/int, optional)
     */
    public function updateResendConfig(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];
        $userId = $this->currentUserId();

        $errors = [];

        // 1. API Key validation
        if (isset($body['api_key'])) {
            $rawKey = trim((string)$body['api_key']);
            // Allow empty string to clear the key
            if ($rawKey !== '' && !str_starts_with($rawKey, 're_')) {
                $errors['api_key'] = 'Invalid Resend API Key. Keys from resend.com must start with "re_".';
            }
        }

        // 2. Sender email validation
        if (!empty($body['from_email'])) {
            $fromEmail = trim((string)$body['from_email']);
            // Extract email if format is 'Name <email@domain>'
            if (preg_match('/<([^>]+)>/', $fromEmail, $matches)) {
                $checkEmail = $matches[1];
            } else {
                $checkEmail = $fromEmail;
            }

            if (!filter_var($checkEmail, FILTER_VALIDATE_EMAIL)) {
                $errors['from_email'] = 'Please provide a valid sender email address.';
            }
        }

        if (!empty($errors)) {
            return $this->errorResponse('Validation failed.', $errors, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Save settings
        if (isset($body['api_key'])) {
            $rawKey = trim((string)$body['api_key']);
            $this->settingModel->setSetting('resend_api_key', $rawKey ?: null, 'API Key from Resend.com for transactional email delivery');
        }

        if (isset($body['from_email'])) {
            $this->settingModel->setSetting('resend_from_email', trim((string)$body['from_email']), 'Sender email address for outgoing system emails');
        }

        if (isset($body['notifications_enabled'])) {
            $val = (!empty($body['notifications_enabled']) && $body['notifications_enabled'] !== '0') ? '1' : '0';
            $this->settingModel->setSetting('resend_notifications_enabled', $val, 'Global toggle for email notification dispatch');
        }

        // Audit log
        $this->activityLogModel->logEvent([
            'event_type'     => 'SETTINGS_UPDATED',
            'severity'       => 'notice',
            'actor_id'       => $userId,
            'target_user_id' => null,
            'details'        => 'Superadmin updated Resend email service configuration.',
            'metadata'       => [
                'has_api_key' => !empty($body['api_key']),
                'from_email'  => $body['from_email'] ?? null,
            ],
        ]);

        return $this->successResponse('Resend configuration updated successfully.', [
            'is_configured'         => $this->settingModel->isResendConfigured(),
            'masked_api_key'        => $this->settingModel->getMaskedResendKey(),
            'from_email'            => $this->settingModel->getSetting('resend_from_email'),
            'notifications_enabled' => $this->settingModel->getSetting('resend_notifications_enabled') !== '0',
        ]);
    }

    /**
     * Send test email to verify Resend connectivity.
     * POST /api/v1/settings/resend/test
     * Accepts:
     * - email (optional, defaults to current superadmin user email)
     */
    public function testResendEmail(): ResponseInterface
    {
        $body   = $this->request->getJSON(true) ?? [];
        $userId = $this->currentUserId();

        $targetEmail = trim((string)($body['email'] ?? ''));

        if (empty($targetEmail)) {
            if ($userId) {
                $user = (new UserModel())->find($userId);
                $targetEmail = $user['email'] ?? '';
            }
        }

        if (empty($targetEmail) || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->errorResponse('A valid recipient email address is required to run the test.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$this->settingModel->isResendConfigured()) {
            return $this->errorResponse('Resend API Key is not configured yet. Please paste your key from resend.com first.', [], ResponseInterface::HTTP_BAD_REQUEST);
        }

        $result = $this->emailService->sendTestEmail($targetEmail);

        if (!$result['success']) {
            return $this->errorResponse(
                'Test email delivery failed: ' . ($result['message'] ?? 'Unknown Resend error'),
                ['resend_response' => $result],
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }

        return $this->successResponse("Test email dispatched successfully to {$targetEmail}.", [
            'recipient' => $targetEmail,
            'resend_id' => $result['id'] ?? null,
        ]);
    }
}
