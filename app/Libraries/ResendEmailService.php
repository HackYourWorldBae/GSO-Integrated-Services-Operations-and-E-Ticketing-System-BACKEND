<?php

namespace App\Libraries;

use App\Models\SystemSettingModel;

/**
 * ResendEmailService
 *
 * Provides transactional email dispatching via Resend.com REST API.
 * Features:
 * - Dynamic API key & sender retrieval from system_settings with .env fallback
 * - Bulletproof SSL handling (supports local cacert.pem fallback on Windows)
 * - Non-blocking error handling (never crashes parent controllers/transactions)
 * - Rich, responsive HTML templates matching institutional GSO branding
 */
class ResendEmailService
{
    private const RESEND_API_URL = 'https://api.resend.com/emails';
    private SystemSettingModel $settingModel;

    public function __construct()
    {
        $this->settingModel = new SystemSettingModel();
    }

    /**
     * Send raw HTML email via Resend REST API.
     *
     * @param string|array $to
     * @param string $subject
     * @param string $htmlContent
     * @return array ['success' => bool, 'message' => string, 'id' => ?string]
     */
    public function sendRawEmail($to, string $subject, string $htmlContent): array
    {
        // 1. Check if email notifications are enabled globally
        $isEnabled = $this->settingModel->getSetting('resend_notifications_enabled', '1');
        if ($isEnabled === '0' || $isEnabled === false) {
            log_message('info', '[ResendEmailService] Email notifications are paused in system settings. Skipping email to: ' . (is_array($to) ? implode(', ', $to) : $to));
            return ['success' => false, 'message' => 'Email notifications are paused in system settings.'];
        }

        // 2. Fetch API key
        $apiKey = $this->settingModel->getSetting('resend_api_key');
        if (empty($apiKey)) {
            log_message('warning', '[ResendEmailService] Resend API key is not configured. Email skipped for: ' . (is_array($to) ? implode(', ', $to) : $to));
            return ['success' => false, 'message' => 'Resend API key is not configured.'];
        }

        // 3. Sender address
        $from = $this->settingModel->getSetting('resend_from_email', 'GSO E-Ticketing <onboarding@resend.dev>');
        if (empty($from)) {
            $from = 'GSO E-Ticketing <onboarding@resend.dev>';
        }

        // Format recipient list
        $recipients = is_array($to) ? array_values(array_filter($to)) : [trim($to)];
        if (empty($recipients)) {
            return ['success' => false, 'message' => 'No valid recipients specified.'];
        }

        $payload = [
            'from'    => $from,
            'to'      => $recipients,
            'subject' => $subject,
            'html'    => $htmlContent,
        ];

        return $this->dispatchToResend($apiKey, $payload);
    }

    /**
     * Dispatch payload via cURL with defensive SSL error recovery.
     */
    private function dispatchToResend(string $apiKey, array $payload): array
    {
        $ch = curl_init(self::RESEND_API_URL);

        $headers = [
            'Authorization: Bearer ' . trim($apiKey),
            'Content-Type: application/json',
            'User-Agent: GSO-ETicketing-System/1.0',
        ];

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        // SSL verification configuration
        $caCertPath = WRITEPATH . 'cacert.pem';
        if (file_exists($caCertPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caCertPath);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        } else {
            // Local dev fallback if no ca-bundle is present
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if (!empty($curlError)) {
            log_message('error', '[ResendEmailService] cURL network error: ' . $curlError);
            return [
                'success' => false,
                'message' => 'Network error contacting Resend API: ' . $curlError,
            ];
        }

        $decoded = json_decode($responseBody, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            log_message('info', '[ResendEmailService] Email dispatched successfully. Resend ID: ' . ($decoded['id'] ?? 'unknown'));
            return [
                'success' => true,
                'message' => 'Email sent successfully.',
                'id'      => $decoded['id'] ?? null,
            ];
        }

        $errorMsg = $decoded['message'] ?? ($decoded['error']['message'] ?? "Resend responded with HTTP status {$httpCode}");
        log_message('error', "[ResendEmailService] Resend API Error [{$httpCode}]: " . $errorMsg . " | Body: " . $responseBody);

        return [
            'success' => false,
            'message' => $errorMsg,
            'status'  => $httpCode,
        ];
    }

    // =========================================================================
    // Transactional Email Templates & Methods
    // =========================================================================

    /**
     * Send Password Reset Link Email.
     */
    public function sendPasswordResetLink(string $toEmail, string $userName, string $resetUrl): array
    {
        $subject = 'Reset Your Password — GSO E-Ticketing System';
        $safeName = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $safeUrl  = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

        $html = $this->wrapLayout(
            'Password Reset Request',
            'Security & Access Credentials',
            "
            <p style=\"margin: 0 0 16px 0; font-size: 15px; color: #334155; line-height: 1.6;\">
                Hello <strong>{$safeName}</strong>,
            </p>
            <p style=\"margin: 0 0 20px 0; font-size: 14px; color: #475569; line-height: 1.6;\">
                We received a request to reset your password for your <strong>Benguet State University — GSO E-Ticketing System</strong> account. Click the button below to choose a new secure password:
            </p>
            <div style=\"text-align: center; margin: 32px 0;\">
                <a href=\"{$safeUrl}\" style=\"display: inline-block; background-color: #059669; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 10px; font-weight: 700; font-size: 14px; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25); letter-spacing: 0.02em;\">
                    Reset Your Password
                </a>
            </div>
            <p style=\"margin: 0 0 12px 0; font-size: 13px; color: #64748b; line-height: 1.5;\">
                Or copy and paste this link into your browser:
            </p>
            <p style=\"margin: 0 0 24px 0; font-size: 12px; color: #059669; word-break: break-all; background-color: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0;\">
                {$safeUrl}
            </p>
            <div style=\"border-top: 1px solid #f1f5f9; padding-top: 16px; margin-top: 24px;\">
                <p style=\"margin: 0; font-size: 12px; color: #94a3b8; line-height: 1.5;\">
                    <strong>Note:</strong> This password reset link is valid for <strong>60 minutes</strong>. If you did not initiate this request, you can safely ignore this email; your account credentials remain unchanged.
                </p>
            </div>
            "
        );

        return $this->sendRawEmail($toEmail, $subject, $html);
    }

    /**
     * Send Password Reset Success Confirmation.
     */
    public function sendPasswordResetSuccess(string $toEmail, string $userName): array
    {
        $subject = 'Password Changed Successfully — GSO E-Ticketing System';
        $safeName = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');

        $html = $this->wrapLayout(
            'Password Updated',
            'Account Security Alert',
            "
            <p style=\"margin: 0 0 16px 0; font-size: 15px; color: #334155; line-height: 1.6;\">
                Hello <strong>{$safeName}</strong>,
            </p>
            <p style=\"margin: 0 0 20px 0; font-size: 14px; color: #475569; line-height: 1.6;\">
                The password for your GSO E-Ticketing System account was recently updated successfully. You can now log in using your new credentials.
            </p>
            <div style=\"background-color: #f0fdf4; border-left: 4px solid #10b981; padding: 14px 16px; border-radius: 6px; margin: 20px 0;\">
                <p style=\"margin: 0; font-size: 13px; color: #065f46; font-weight: 600;\">
                    If you did not perform this change, please contact the General Services Office (GSO) or your system administrator immediately to secure your account.
                </p>
            </div>
            "
        );

        return $this->sendRawEmail($toEmail, $subject, $html);
    }

    /**
     * Send Ticket Intake Submission Confirmation to Requestor.
     */
    public function sendTicketIntakeConfirmation(string $toEmail, string $userName, array $ticketDetails): array
    {
        $safeName = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $ticketsCount = count($ticketDetails);
        $subject = ($ticketsCount === 1)
            ? "Request Received: Ticket #{$ticketDetails[0]['id']} — GSO E-Ticketing"
            : "{$ticketsCount} Service Requests Received — GSO E-Ticketing";

        $ticketCards = '';
        foreach ($ticketDetails as $t) {
            $tId      = htmlspecialchars($t['id'] ?? '', ENT_QUOTES, 'UTF-8');
            $unitName = htmlspecialchars($t['unit_name'] ?? ($t['unit_code'] ?? 'GSO'), ENT_QUOTES, 'UTF-8');
            $service  = htmlspecialchars($t['service_type'] ?? 'General Service', ENT_QUOTES, 'UTF-8');

            $ticketCards .= "
            <div style=\"background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 12px;\">
                <div style=\"display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;\">
                    <span style=\"font-size: 15px; font-weight: 800; color: #0f172a;\">#{$tId}</span>
                    <span style=\"background-color: #e0f2fe; color: #0369a1; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase;\">Pending Review</span>
                </div>
                <p style=\"margin: 0 0 4px 0; font-size: 13px; color: #475569;\"><strong>Service Unit:</strong> {$unitName}</p>
                <p style=\"margin: 0; font-size: 13px; color: #475569;\"><strong>Requested Work:</strong> {$service}</p>
            </div>";
        }

        $frontendUrl = rtrim(env('APP_FRONTEND_URL', 'http://localhost:5173'), '/');

        $html = $this->wrapLayout(
            'Request Intake Confirmed',
            'Campus Operational Work Orders',
            "
            <p style=\"margin: 0 0 16px 0; font-size: 15px; color: #334155; line-height: 1.6;\">
                Hello <strong>{$safeName}</strong>,
            </p>
            <p style=\"margin: 0 0 20px 0; font-size: 14px; color: #475569; line-height: 1.6;\">
                Your service intake submission has been received and registered into the GSO queue. Our administrative officers are reviewing the operational requirements:
            </p>
            {$ticketCards}
            <div style=\"text-align: center; margin: 28px 0;\">
                <a href=\"{$frontendUrl}/user/tickets\" style=\"display: inline-block; background-color: #0f172a; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 10px; font-weight: 700; font-size: 13px;\">
                    Track Live Ticket Status
                </a>
            </div>
            <p style=\"margin: 0; font-size: 12px; color: #94a3b8; line-height: 1.5;\">
                You will receive automated email updates whenever your ticket status changes (e.g. approval, dispatch, completion).
            </p>
            "
        );

        return $this->sendRawEmail($toEmail, $subject, $html);
    }

    /**
     * Send Ticket Status Change Email to Requestor.
     */
    public function sendTicketStatusUpdate(string $toEmail, string $userName, string $ticketId, string $statusLabel, string $message, array $extra = []): array
    {
        $safeName   = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $safeId     = htmlspecialchars($ticketId, ENT_QUOTES, 'UTF-8');
        $safeStatus = htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8');
        $safeMsg    = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        $subject = "Ticket #{$ticketId} Status: {$statusLabel} — GSO E-Ticketing";

        // Status badge styling
        $badgeBg = '#e2e8f0';
        $badgeColor = '#334155';
        $lowerStatus = strtolower($statusLabel);

        if (str_contains($lowerStatus, 'approved')) {
            $badgeBg = '#d1fae5';
            $badgeColor = '#065f46';
        } elseif (str_contains($lowerStatus, 'completed') || str_contains($lowerStatus, 'accomplished')) {
            $badgeBg = '#dbeafe';
            $badgeColor = '#1e40af';
        } elseif (str_contains($lowerStatus, 'decline') || str_contains($lowerStatus, 'reject') || str_contains($lowerStatus, 'cancel')) {
            $badgeBg = '#ffe4e6';
            $badgeColor = '#9f1239';
        } elseif (str_contains($lowerStatus, 'delay') || str_contains($lowerStatus, 'investigat')) {
            $badgeBg = '#fef3c7';
            $badgeColor = '#92400e';
        }

        $frontendUrl = rtrim(env('APP_FRONTEND_URL', 'http://localhost:5173'), '/');

        $extraDetailsHtml = '';
        if (!empty($extra)) {
            $extraDetailsHtml .= "<div style=\"background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin: 16px 0;\">";
            foreach ($extra as $k => $v) {
                $kSafe = htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8');
                $vSafe = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
                $extraDetailsHtml .= "<p style=\"margin: 4px 0; font-size: 12px; color: #475569;\"><strong>{$kSafe}:</strong> {$vSafe}</p>";
            }
            $extraDetailsHtml .= "</div>";
        }

        $html = $this->wrapLayout(
            "Ticket Update: #{$safeId}",
            'Work Order Status Notification',
            "
            <p style=\"margin: 0 0 16px 0; font-size: 15px; color: #334155; line-height: 1.6;\">
                Hello <strong>{$safeName}</strong>,
            </p>
            <p style=\"margin: 0 0 16px 0; font-size: 14px; color: #475569; line-height: 1.6;\">
                Your service request <strong>#{$safeId}</strong> has received a status update:
            </p>
            <div style=\"text-align: center; margin: 20px 0;\">
                <span style=\"display: inline-block; background-color: {$badgeBg}; color: {$badgeColor}; font-size: 14px; font-weight: 800; padding: 8px 18px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.04em;\">
                    {$safeStatus}
                </span>
            </div>
            <p style=\"margin: 16px 0; font-size: 14px; color: #334155; line-height: 1.6; background-color: #f8fafc; padding: 14px; border-radius: 8px; border-left: 3px solid {$badgeColor};\">
                {$safeMsg}
            </p>
            {$extraDetailsHtml}
            <div style=\"text-align: center; margin: 28px 0;\">
                <a href=\"{$frontendUrl}/user/tickets\" style=\"display: inline-block; background-color: #0f172a; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 10px; font-weight: 700; font-size: 13px;\">
                    View in Requestor Dashboard
                </a>
            </div>
            "
        );

        return $this->sendRawEmail($toEmail, $subject, $html);
    }

    /**
     * Send High-Priority Incident Report Email Alert to SSU Administrators.
     */
    public function sendSsuIncidentAlertToAdmins(array $adminEmails, array $incidentData, string $ticketId): array
    {
        if (empty($adminEmails)) {
            return ['success' => false, 'message' => 'No SSU Admin email addresses found.'];
        }

        $safeId       = htmlspecialchars($ticketId, ENT_QUOTES, 'UTF-8');
        $safeWhere    = htmlspecialchars($incidentData['where'] ?? 'Campus Grounds', ENT_QUOTES, 'UTF-8');
        $safeWhen     = htmlspecialchars($incidentData['when'] ?? date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8');
        $safeWho      = htmlspecialchars($incidentData['who'] ?? 'Unspecified', ENT_QUOTES, 'UTF-8');
        $safeHow      = htmlspecialchars($incidentData['how'] ?? 'No narrative provided.', ENT_QUOTES, 'UTF-8');
        $safeReporter = htmlspecialchars($incidentData['reportedBy']['printedName'] ?? 'Anonymous / Campus User', ENT_QUOTES, 'UTF-8');

        $incidentsList = is_array($incidentData['incidents'] ?? null)
            ? implode(', ', $incidentData['incidents'])
            : ($incidentData['otherIncident'] ?? 'Security Incident');
        $safeTypes = htmlspecialchars($incidentsList, ENT_QUOTES, 'UTF-8');

        $subject = "[ALERT] New SSU Incident Report #{$ticketId} — Immediate Action Required";

        $frontendUrl = rtrim(env('APP_FRONTEND_URL', 'http://localhost:5173'), '/');

        $html = $this->wrapLayout(
            'SSU Incident Incoming',
            'Security Services Unit Alert System',
            "
            <div style=\"background-color: #fef2f2; border: 2px solid #ef4444; border-radius: 10px; padding: 16px; margin-bottom: 24px;\">
                <div style=\"display: flex; align-items: center; margin-bottom: 8px;\">
                    <span style=\"background-color: #ef4444; color: #ffffff; font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.05em;\">
                        High Priority Incident
                    </span>
                    <span style=\"font-size: 15px; font-weight: 800; color: #991b1b; margin-left: 12px;\">
                        Ticket #{$safeId}
                    </span>
                </div>
                <p style=\"margin: 0; font-size: 13px; color: #7f1d1d; line-height: 1.5;\">
                    A new security incident report has just been filed via the campus portal and is awaiting review in the SSU administrative dashboard.
                </p>
            </div>

            <table style=\"width: 100%; border-collapse: collapse; margin-bottom: 20px;\">
                <tr>
                    <td style=\"padding: 8px 0; font-size: 13px; color: #64748b; width: 35%;\"><strong>Incident Type(s):</strong></td>
                    <td style=\"padding: 8px 0; font-size: 13px; color: #0f172a; font-weight: 600;\">{$safeTypes}</td>
                </tr>
                <tr>
                    <td style=\"padding: 8px 0; font-size: 13px; color: #64748b;\"><strong>Location / Where:</strong></td>
                    <td style=\"padding: 8px 0; font-size: 13px; color: #0f172a; font-weight: 600;\">{$safeWhere}</td>
                </tr>
                <tr>
                    <td style=\"padding: 8px 0; font-size: 13px; color: #64748b;\"><strong>Time / When:</strong></td>
                    <td style=\"padding: 8px 0; font-size: 13px; color: #0f172a; font-weight: 600;\">{$safeWhen}</td>
                </tr>
                <tr>
                    <td style=\"padding: 8px 0; font-size: 13px; color: #64748b;\"><strong>Persons Involved:</strong></td>
                    <td style=\"padding: 8px 0; font-size: 13px; color: #0f172a;\">{$safeWho}</td>
                </tr>
                <tr>
                    <td style=\"padding: 8px 0; font-size: 13px; color: #64748b;\"><strong>Reported By:</strong></td>
                    <td style=\"padding: 8px 0; font-size: 13px; color: #0f172a;\">{$safeReporter}</td>
                </tr>
            </table>

            <div style=\"background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 24px;\">
                <p style=\"margin: 0 0 6px 0; font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase;\">Incident Narrative</p>
                <p style=\"margin: 0; font-size: 13px; color: #334155; line-height: 1.6; white-space: pre-line;\">{$safeHow}</p>
            </div>

            <div style=\"text-align: center; margin: 28px 0;\">
                <a href=\"{$frontendUrl}/admin/ssu/incident-queues\" style=\"display: inline-block; background-color: #dc2626; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 10px; font-weight: 700; font-size: 14px; box-shadow: 0 4px 14px rgba(220, 38, 38, 0.3);\">
                    Open SSU Admin Dashboard
                </a>
            </div>
            "
        );

        return $this->sendRawEmail($adminEmails, $subject, $html);
    }

    /**
     * Send System Connectivity Test Email.
     */
    public function sendTestEmail(string $toEmail): array
    {
        $subject = 'Test Connection Successful — GSO E-Ticketing System';
        $timestamp = date('Y-m-d H:i:s T');

        $html = $this->wrapLayout(
            'Resend Connection Active',
            'Infrastructure Diagnostics Probe',
            "
            <p style=\"margin: 0 0 16px 0; font-size: 15px; color: #334155; line-height: 1.6;\">
                Greetings Administrator,
            </p>
            <p style=\"margin: 0 0 16px 0; font-size: 14px; color: #475569; line-height: 1.6;\">
                Your <strong>Resend.com API Key</strong> has been verified successfully. The GSO E-Ticketing mail delivery pipeline is operational and ready to send transactional notifications for:
            </p>
            <ul style=\"margin: 0 0 20px 20px; font-size: 13px; color: #334155; line-height: 1.8;\">
                <li>Email Forgot Password recovery with secure account reset links</li>
                <li>Requestor service ticket confirmation and status notifications</li>
                <li>Campus SSU Incident alerts dispatched directly to SSU administrators</li>
            </ul>
            <div style=\"background-color: #f1f5f9; padding: 10px 14px; border-radius: 6px; font-size: 12px; color: #64748b;\">
                Dispatched at: <strong>{$timestamp}</strong>
            </div>
            "
        );

        return $this->sendRawEmail($toEmail, $subject, $html);
    }

    // =========================================================================
    // Core Layout Wrapper
    // =========================================================================

    /**
     * Wrap content in high-end, responsive HTML layout with BSU & GSO branding.
     */
    private function wrapLayout(string $title, string $subtitle, string $bodyHtml): string
    {
        return "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>{$title}</title>
</head>
<body style=\"margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;\">
    <table role=\"presentation\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"background-color: #f1f5f9; padding: 32px 12px;\">
        <tr>
            <td align=\"center\">
                <table role=\"presentation\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"max-width: 580px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0;\">
                    <!-- Brand Header -->
                    <tr>
                        <td style=\"background-color: #0f172a; padding: 28px 32px; text-align: left; border-bottom: 3px solid #059669;\">
                            <div style=\"display: inline-block; vertical-align: middle;\">
                                <h1 style=\"margin: 0; font-size: 18px; font-weight: 800; color: #ffffff; letter-spacing: -0.02em;\">Benguet State University</h1>
                                <p style=\"margin: 4px 0 0 0; font-size: 12px; font-weight: 600; color: #34d399; text-transform: uppercase; letter-spacing: 0.1em;\">General Services Office — E-Ticketing</p>
                            </div>
                        </td>
                    </tr>
                    <!-- Title Bar -->
                    <tr>
                        <td style=\"padding: 28px 32px 0 32px;\">
                            <span style=\"display: inline-block; font-size: 10px; font-weight: 800; color: #059669; text-transform: uppercase; letter-spacing: 0.12em; margin-bottom: 4px;\">{$subtitle}</span>
                            <h2 style=\"margin: 0; font-size: 20px; font-weight: 800; color: #0f172a; letter-spacing: -0.01em;\">{$title}</h2>
                        </td>
                    </tr>
                    <!-- Body Content -->
                    <tr>
                        <td style=\"padding: 20px 32px 32px 32px;\">
                            {$bodyHtml}
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style=\"background-color: #f8fafc; padding: 20px 32px; border-top: 1px solid #e2e8f0; text-align: center;\">
                            <p style=\"margin: 0 0 4px 0; font-size: 11px; color: #94a3b8; font-weight: 500;\">
                                © " . date('Y') . " Benguet State University — General Services Office
                            </p>
                            <p style=\"margin: 0; font-size: 10px; color: #cbd5e1;\">
                                This is an automated system transmission. Please do not reply directly to this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>";
    }
}
