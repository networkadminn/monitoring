<?php

// Ensure autoloader is loaded
if (file_exists(__DIR__ . "/../vendor/autoload.php")) {
    require_once __DIR__ . "/../vendor/autoload.php";
}

require_once __DIR__ . '/Helpers.php';

// =============================================================================
// includes/Alert.php - Multi-channel alert system with suppression
// =============================================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

class Alert {

    // Note: Email validation is now delegated to shared Helpers::validateEmail()
      
    public static function send(array $site, array $checkResult, string $event = 'down'): void {
        $siteId    = $site['id'];
        $alertType = $event; // 'down' or 'recovery'

        if (!self::canSend($siteId, $alertType)) {
            return; // Still within cooldown window
        }

        $subject = self::buildSubject($site, $checkResult, $event);
        $body    = self::buildBody($site, $checkResult, $event);

        // Email
        if (!empty($site['alert_email'])) {
            $emails = array_filter(array_map('trim', explode(',', $site['alert_email'])));
            foreach ($emails as $to) {
                if (validateEmail($to)) {
                    try {
                        self::sendEmail($to, $subject, $body);
                    } catch (Exception $e) {
                        error_log('[Alert] Failed to send email: ' . $e->getMessage());
                    }
                } else {
                    error_log('[Alert] Skipping invalid alert email: ' . $to);
                }
            }
        }

        // SMS
        if (ENABLE_SMS_ALERTS && !empty($site['alert_phone'])) {
            self::sendSms($site['alert_phone'], "{$subject} - {$site['url']} - {$checkResult['error_message']}");
        }

        // Telegram
        if (ENABLE_TELEGRAM_ALERTS && !empty($site['alert_telegram'])) {
            self::sendTelegram($site['alert_telegram'], "{$subject} - {$site['url']} - {$checkResult['error_message']}");
        }

        // Microsoft Teams
        if (ENABLE_TEAMS_ALERTS && !empty($site['alert_teams'])) {
            self::sendTeams($site, $checkResult, $subject, $event);
        }

        // Record that we sent this alert (for cooldown)
        self::recordAlert($siteId, $alertType);
    }

    private static function sendSms(string $phone, string $message): void {
        if (empty(SMS_API_ENDPOINT) || empty(SMS_API_KEY)) {
            error_log('[Alert] SMS delivery not configured.');
            return;
        }

        // Example HTTP API: POST JSON
        $data = json_encode(['to' => $phone, 'message' => $message, 'api_key' => SMS_API_KEY]);

        $ch = curl_init(SMS_API_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_TIMEOUT        => CHECK_TIMEOUT,
        ]);
        $resp = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('[Alert] SMS send failed: ' . curl_error($ch));
        }
        curl_close($ch);
    }

    private static function sendTelegram(string $chatId, string $message): void {
        if (empty(TELEGRAM_BOT_TOKEN)) {
            error_log('[Alert] Telegram bot token not configured.');
            return;
        }

        $url = 'https://api.telegram.org/bot' . urlencode(TELEGRAM_BOT_TOKEN) . '/sendMessage';
        $payload = http_build_query(['chat_id' => $chatId, 'text' => $message]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => CHECK_TIMEOUT,
        ]);
        $resp = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('[Alert] Telegram send failed: ' . curl_error($ch));
        }
        curl_close($ch);
    }

    private static function sendTeams(array $site, array $checkResult, string $subject, string $event): void {
        $webhookUrl = $site['alert_teams'] ?? null;
        if (empty($webhookUrl)) {
            return;
        }

        // Determine color and status based on event type
        if ($event === 'recovery') {
            $color = '28a745';  // Green
            $status = '✅ RECOVERED';
        } elseif ($event === 'ssl_expiry') {
            $color = 'ffc107';  // Yellow/Orange
            $status = '⚠️ SSL EXPIRING';
        } else {
            $color = 'dc3545';  // Red
            $status = '❌ DOWN';
        }

        $errorMsg = htmlspecialchars($checkResult['error_message'] ?? 'No error details');
        $siteName = htmlspecialchars($site['name']);
        $siteUrl  = htmlspecialchars($site['url']);
        $responseTime = $checkResult['response_time'] ?? 'N/A';
        $timestamp = date('Y-m-d H:i:s T');

        // Build Teams Adaptive Card JSON
        $card = [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => $subject,
            'themeColor' => $color,
            'sections' => [
                [
                    'activityTitle' => $subject,
                    'activitySubtitle' => "$status - " . date('Y-m-d H:i:s T'),
                    'text' => "<b>Site:</b> $siteName<br><b>URL:</b> $siteUrl<br><b>Error:</b> $errorMsg<br><b>Response Time:</b> {$responseTime}ms",
                    'markdown' => true
                ]
            ],
            'potentialAction' => [
                [
                    '@type' => 'OpenUri',
                    'name' => 'View Monitor',
                    'targets' => [
                        ['os' => 'default', 'uri' => APP_URL . '/site_details.php?id=' . (int)$site['id']]
                    ]
                ]
            ]
        ];

        $payload = json_encode($card);

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("[Alert] Teams send failed: $curlError");
        } elseif ($httpCode !== 200) {
            error_log("[Alert] Teams returned HTTP $httpCode: $response");
        }
    }

    // -------------------------------------------------------------------------
    // Cooldown check: returns true if we're allowed to send
    // -------------------------------------------------------------------------
    private static function canSend(int $siteId, string $alertType): bool {
        $row = Database::fetchOne(
            'SELECT sent_at FROM alert_log WHERE site_id = ? AND alert_type = ?',
            [$siteId, $alertType]
        );

        if (!$row) return true;

        $elapsed = time() - strtotime($row['sent_at']);
        return $elapsed >= ALERT_COOLDOWN;
    }

    // -------------------------------------------------------------------------
    // Upsert alert_log row (INSERT or UPDATE on duplicate key)
    // -------------------------------------------------------------------------
    private static function recordAlert(int $siteId, string $alertType): void {
        Database::execute(
            'INSERT INTO alert_log (site_id, alert_type, sent_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE sent_at = NOW()',
            [$siteId, $alertType]
        );
    }

    // -------------------------------------------------------------------------
    // Build subject line
    // -------------------------------------------------------------------------
        // -------------------------------------------------------------------------
    // Build subject line with proper UTF-8 encoding
    // -------------------------------------------------------------------------
    private static function buildSubject(array $site, array $result, string $event): string {
        if ($event === 'ssl_expiry') {
            $days = $result['ssl_expiry_days'] ?? 0;
            $subject = "[SSL EXPIRING] {$site['name']} - Certificate expires in {$days} days";
        } elseif ($event === 'recovery') {
            $subject = "[RECOVERED] {$site['name']} is back online";
        } else {
            $subject = "[DOWN ALERT] {$site['name']} is currently unavailable";
        }
        return $subject;
    }

    // -------------------------------------------------------------------------
    // Build HTML alert body
    // -------------------------------------------------------------------------
    // Build HTML email body - Professional responsive template
    // -------------------------------------------------------------------------
    private static function buildBody(array $site, array $result, string $event): string {
        $timestamp   = date('Y-m-d H:i:s T');
        $siteName    = htmlspecialchars($site['name']);
        $siteUrl     = htmlspecialchars($site['url']);
        $checkType   = htmlspecialchars($site['check_type'] ?? 'http');
        $responseTime = isset($result['response_time']) ? round($result['response_time']) : 'N/A';
        $errorMsg    = htmlspecialchars($result['error_message'] ?? 'No error details available');

        // Determine status and colors
        if ($event === 'recovery') {
            $statusLabel  = 'RECOVERED';
            $statusText   = 'Site is back online';
            $headerBg     = '#10b981';
            $headerBgGrad = '#059669';
            $badge        = '#d1fae5';
            $badgeText    = '#047857';
            $actionText   = 'View Dashboard';
            $actionBg     = '#10b981';
            $nextSteps    = [
                'Site is back online and functioning normally',
                'Check the dashboard for any performance metrics',
                'Review incident duration and response times',
                'Monitor for any recurring issues'
            ];
        } elseif ($event === 'ssl_expiry') {
            $days         = $result['ssl_expiry_days'] ?? 0;
            $statusLabel  = 'SSL EXPIRING SOON';
            $statusText   = "Certificate expires in {$days} days";
            $headerBg     = '#f59e0b';
            $headerBgGrad = '#d97706';
            $badge        = '#fed7aa';
            $badgeText    = '#d97706';
            $actionText   = 'Renew Certificate';
            $actionBg     = '#f59e0b';
            $nextSteps    = [
                'Review the certificate expiry date above',
                'Log in to your hosting provider\'s certificate management panel',
                'Renew or reissue the SSL certificate',
                'Reinstall the new certificate on your server',
                'Verify the certificate is working correctly'
            ];
        } else {
            $statusLabel  = 'DOWN - IMMEDIATE ACTION NEEDED';
            $statusText   = 'Site is currently unavailable';
            $headerBg     = '#ef4444';
            $headerBgGrad = '#dc2626';
            $badge        = '#fee2e2';
            $badgeText    = '#991b1b';
            $actionText   = 'Troubleshoot Now';
            $actionBg     = '#ef4444';
            $nextSteps    = [
                'Check if your server is running and responsive',
                'Verify network connectivity and firewall settings',
                'Check your hosting provider\'s status page',
                'Review server logs for error messages',
                'Contact your hosting provider if the issue persists'
            ];
        }

        // Build next steps list
        $nextStepsList = '';
        foreach ($nextSteps as $step) {
            $nextStepsList .= '<li style="margin: 8px 0;">' . htmlspecialchars($step) . '</li>';
        }

        // Build error details section (if applicable)
        $errorSection = '';
        if ($event !== 'recovery' && !empty($result['error_message'])) {
            $errorSection = '
                <div style="background: #fef2f2; border-left: 4px solid #dc2626; padding: 12px; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0 0 8px; color: #991b1b; font-weight: bold; font-size: 13px;">Error Details:</p>
                    <p style="margin: 0; color: #7f1d1d; font-size: 13px; word-break: break-word;">' . $errorMsg . '</p>
                </div>';
        }

        // Build SSL details section (if applicable)
        $sslSection = '';
        if ($event === 'ssl_expiry' && isset($result['ssl_expiry_days'])) {
            $days = $result['ssl_expiry_days'];
            $urgencyMsg = '';
            if ($days <= 3) {
                $urgencyMsg = '<p style="margin: 4px 0 0; color: #dc2626; font-weight: bold; font-size: 12px;">🔴 URGENT - Renew immediately</p>';
            } elseif ($days <= 7) {
                $urgencyMsg = '<p style="margin: 4px 0 0; color: #d97706; font-weight: bold; font-size: 12px;">⚠️ HIGH PRIORITY - Renew soon</p>';
            }
            $sslSection = '
                <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0 0 4px; color: #92400e; font-weight: bold; font-size: 13px;">🔐 SSL Certificate expires in ' . $days . ' days</p>
                    <p style="margin: 0; color: #b45309; font-size: 12px;">Expiry Date: ' . date('M d, Y', strtotime("+{$days} days")) . '</p>
                    ' . $urgencyMsg . '
                </div>';
        }

        return <<<TEMPLATE
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Monitor Alert - {$siteName}</title>
</head>
<body style="margin: 0; padding: 16px; background: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
    <div style="max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        
        <!-- Header -->
        <div style="background: linear-gradient(135deg, {$headerBg} 0%, {$headerBgGrad} 100%); color: white; padding: 32px 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 28px; font-weight: 700; letter-spacing: -0.5px;">{$statusLabel}</h1>
            <p style="margin: 8px 0 0; font-size: 16px; opacity: 0.95;">{$statusText}</p>
        </div>

        <!-- Content -->
        <div style="padding: 32px 24px;">
            
            <!-- Site Name Badge -->
            <div style="text-align: center; margin-bottom: 24px;">
                <span style="display: inline-block; background: {$badge}; color: {$badgeText}; padding: 8px 16px; border-radius: 20px; font-weight: 600; font-size: 14px;">
                    {$siteName}
                </span>
            </div>

            {$sslSection}
            {$errorSection}

            <!-- Details Table -->
            <table style="width: 100%; border-collapse: collapse; margin: 24px 0;">
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 12px 0; width: 40%; color: #6b7280; font-weight: 500;">URL</td>
                    <td style="padding: 12px 0; text-align: right; color: #1f2937;">
                        <a href="{$siteUrl}" style="color: #0284c7; text-decoration: none;">{$siteUrl}</a>
                    </td>
                </tr>
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 12px 0; color: #6b7280; font-weight: 500;">Check Type</td>
                    <td style="padding: 12px 0; text-align: right; color: #1f2937;">
                        <span style="background: #f3f4f6; color: #6b7280; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">{$checkType}</span>
                    </td>
                </tr>
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 12px 0; color: #6b7280; font-weight: 500;">Response Time</td>
                    <td style="padding: 12px 0; text-align: right; color: #1f2937;">{$responseTime} ms</td>
                </tr>
                <tr>
                    <td style="padding: 12px 0; color: #6b7280; font-weight: 500;">Timestamp</td>
                    <td style="padding: 12px 0; text-align: right; color: #1f2937;">{$timestamp}</td>
                </tr>
            </table>

            <!-- Action Button -->
            <div style="text-align: center; margin: 28px 0;">
                <a href="{$siteUrl}" style="display: inline-block; background: linear-gradient(135deg, {$actionBg} 0%, {$actionBg}dd 100%); color: white; padding: 14px 32px; text-decoration: none; border-radius: 6px; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    {$actionText}
                </a>
            </div>

            <!-- Next Steps -->
            <div style="background: #f0f9ff; border-left: 4px solid #0284c7; padding: 16px; margin: 24px 0; border-radius: 4px;">
                <h4 style="margin: 0 0 12px; color: #0c4a6e; font-size: 14px; font-weight: 600;">📋 Recommended Actions:</h4>
                <ol style="margin: 0; padding-left: 20px; color: #0c4a6e; font-size: 13px; line-height: 1.6;">
                    {$nextStepsList}
                </ol>
            </div>

        </div>

        <!-- Footer -->
        <div style="background: #f9fafb; padding: 20px 24px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">
            <p style="margin: 0 0 8px;">
                <strong>Site Monitor</strong> – Real-time Website Monitoring & Alerts
            </p>
            <p style="margin: 0 0 8px; font-size: 11px;">
                Alert Cooldown: 1 hour | Check Interval: Every minute
            </p>
            <p style="margin: 0; font-size: 11px;">
                <a href="{APP_URL}" style="color: #3b82f6; text-decoration: none;">Dashboard</a> | 
                <a href="{APP_URL}/settings.php" style="color: #3b82f6; text-decoration: none;">Settings</a>
            </p>
        </div>

    </div>
</body>
</html>
TEMPLATE;
    }

    // -------------------------------------------------------------------------
    // Send email via PHPMailer with proper UTF-8 encoding
    // -------------------------------------------------------------------------
    private static function sendEmail(string $to, string $subject, string $htmlBody): void {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            throw new Exception('PHPMailer not installed. Run: composer install');
        }

        $to = filter_var(trim($to), FILTER_SANITIZE_EMAIL);
        if (!self::validateEmail($to)) {
            throw new Exception('Invalid recipient email: ' . $to);
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = 'base64';
            $mail->setFrom(FROM_EMAIL, FROM_NAME);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);
            $mail->send();
            error_log("[Alert] Email sent successfully to $to");
        } catch (Exception $e) {
            // Re-throw so the caller can decide what to do
            throw new Exception('Email send failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Send a test email for verifying SMTP configuration
     * This method is used by the test_email API endpoint and throws exceptions if anything fails
     */
    public static function sendTestEmail(array $site, array $checkResult, string $event): void {
        // Validate configuration first
        if (empty(SMTP_HOST) || empty(SMTP_USER) || empty(SMTP_PASS)) {
            throw new Exception('SMTP configuration incomplete: SMTP_HOST, SMTP_USER, SMTP_PASS required');
        }
        if (empty(FROM_EMAIL)) {
            throw new Exception('FROM_EMAIL not configured in config.php');
        }
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            throw new Exception('PHPMailer not installed. Run: composer install');
        }
        
        $subject = self::buildSubject($site, $checkResult, $event);
        $body    = self::buildBody($site, $checkResult, $event);
        
        if (empty($site['alert_email'])) {
            throw new Exception('No recipient email configured for this test');
        }
        
        $emails = array_filter(array_map('trim', explode(',', $site['alert_email'])));
        if (empty($emails)) {
            throw new Exception('No valid recipient emails to send to');
        }
        
        // Send to all configured emails, let exceptions propagate
        foreach ($emails as $to) {
            if (!self::validateEmail($to)) {
                throw new Exception('Invalid recipient email: ' . $to);
            }
            // This will throw if anything fails
            self::sendEmail($to, $subject, $body);
        }
    }
}
