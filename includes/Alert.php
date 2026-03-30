<?php

// Ensure autoloader is loaded
if (file_exists(__DIR__ . "/../vendor/autoload.php")) {
    require_once __DIR__ . "/../vendor/autoload.php";
}

// =============================================================================
// includes/Alert.php - Multi-channel alert system with suppression
// =============================================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

class Alert {

    // -------------------------------------------------------------------------
    // Full RFC-compliant email validation function
    // -------------------------------------------------------------------------
    private static function validateEmail(string $email): bool {
        // Trim whitespace
        $email = trim($email);

        // Check length (RFC 5321 limit is 254 characters)
        if (strlen($email) > 254 || strlen($email) < 3) {
            return false;
        }

        // Basic format check - must contain exactly one @
        if (substr_count($email, '@') !== 1) {
            return false;
        }

        // Split into local and domain parts
        $atPos = strpos($email, '@');
        $local = substr($email, 0, $atPos);
        $domain = substr($email, $atPos + 1);

        // Check local and domain are not empty
        if (empty($local) || empty($domain)) {
            return false;
        }

        // Local part length check (RFC 5321 - max 64 characters)
        if (strlen($local) > 64) {
            return false;
        }

        // Domain length check
        if (strlen($domain) > 253) {
            return false;
        }

        // Check for consecutive dots
        if (strpos($email, '..') !== false) {
            return false;
        }

        // Local part cannot start or end with dot
        if ($local[0] === '.' || $local[strlen($local) - 1] === '.') {
            return false;
        }

        // Domain cannot start or end with dot or hyphen
        if ($domain[0] === '.' || $domain[0] === '-' ||
            $domain[strlen($domain) - 1] === '.' || $domain[strlen($domain) - 1] === '-') {
            return false;
        }

        // Check for invalid characters in domain (only allow valid domain chars)
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/i', $domain)) {
            return false;
        }

        // Comprehensive local part validation (RFC 5322 compliant)
        // This allows: letters, digits, and special chars: !#$%&'*+-/=?^_`{|}~
        // Local part can be quoted or unquoted
        $localRegex = '/^(?:(?:[a-zA-Z0-9!#$%&\'*+\-\/=?^_`{|}~]+(?:\.[a-zA-Z0-9!#$%&\'*+\-\/=?^_`{|}~]+)*)|(?:"(?:\\\\[\x00-\x7F]|[^\\\\"])*"))$/';

        if (!preg_match($localRegex, $local)) {
            return false;
        }

        // Domain must have at least one dot (unless it's a local domain)
        // But allow localhost and IP addresses
        if (strpos($domain, '.') === false && !preg_match('/^(?:localhost|(?:\d{1,3}\.){3}\d{1,3})$/i', $domain)) {
            // Allow single-label domains for internal use, but flag as suspicious
            // For strict validation, we could return false here
        }

        // Final PHP filter validation as backup
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return true;
    }
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
                if (self::validateEmail($to)) {
                    self::sendEmail($to, $subject, $body);
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
        $icon = $event === 'recovery' ? '✅ RECOVERED' : ($event === 'ssl_expiry' ? '⚠️ SSL EXPIRING' : '🔴 DOWN ALERT');
        if ($event === 'ssl_expiry') {
            $subject = "$icon: {$site['name']} SSL expires in {$result['ssl_expiry_days']} days";
        } else {
            $subject = "$icon: {$site['name']} is " . ($event === 'recovery' ? 'back online' : 'DOWN');
        }
        // Encode subject for email to handle emojis properly
        return mb_encode_mimeheader($subject, 'UTF-8', 'B');
    }
        }
        return "$icon: {$site['name']} is " . ($event === 'recovery' ? 'back online' : 'DOWN');
    }

    // -------------------------------------------------------------------------
    // Build HTML alert body
    // -------------------------------------------------------------------------
    
    // -------------------------------------------------------------------------
    // Build HTML alert body - Creative Modern Template
    // -------------------------------------------------------------------------
    private static function buildBody(array $site, array $result, string $event): string {
        $time    = date('Y-m-d H:i:s T');
        $status  = strtoupper($event === 'recovery' ? 'RECOVERED' : ($event === 'ssl_expiry' ? 'SSL EXPIRING' : $result['status']));
        $color   = $event === 'recovery' ? '#10b981' : ($event === 'ssl_expiry' ? '#f59e0b' : '#ef4444');
        $bgColor  = $event === 'recovery' ? '#d1fae5' : ($event === 'ssl_expiry' ? '#fed7aa' : '#fee2e2');
        $error   = htmlspecialchars($result['error_message'] ?? 'N/A');
        $rt      = $result['response_time'] ?? 'N/A';
        $url     = htmlspecialchars($site['url']);
        $name    = htmlspecialchars($site['name']);
        
        // Status icons
        $statusIcon = $event === 'recovery' ? '🎉' : ($event === 'ssl_expiry' ? '⚠️' : '🔴');
        $statusEmoji = $event === 'recovery' ? '✅ BACK ONLINE' : ($event === 'ssl_expiry' ? '⏰ EXPIRING SOON' : '🚨 CRITICAL ALERT');
        
        // Response time indicator
        $rtColor = $rt < 500 ? '#10b981' : ($rt < 1000 ? '#f59e0b' : '#ef4444');
        $rtIcon = $rt < 500 ? '⚡' : ($rt < 1000 ? '🐌' : '🐢');
        $rtText = $rt < 500 ? 'Excellent' : ($rt < 1000 ? 'Slow' : 'Very Slow');
        
        // SSL expiry details
        $expiryInfo = '';
        $expiryWarning = '';
        if ($event === 'ssl_expiry') {
            $days = $result['ssl_expiry_days'];
            $expiryInfo = "；
            <tr style=\"border-bottom: 1px solid #e5e7eb;\">
                <td style=\"padding: 12px; color: #6b7280; font-weight: 500;\">🔐 SSL Certificate</td>
                <td style=\"padding: 12px; text-align: right;\">
                    <span style=\"background: #fef3c7; color: #d97706; padding: 4px 12px; border-radius: 20px; font-weight: bold;\">
                        {$days} Days Left
                    </span>
                </td>
            </tr>";
            
            if ($days <= 3) {
                $expiryWarning = '<div style="background: #fee2e2; border-left: 4px solid #dc2626; padding: 12px; margin: 15px 0; border-radius: 8px;">
                    <strong style="color: #dc2626;">🔴 URGENT ACTION REQUIRED!</strong><br>
                    This SSL certificate expires in less than 3 days. Renew immediately to avoid service interruption.
                </div>';
            } elseif ($days <= 7) {
                $expiryWarning = '<div style="background: #fed7aa; border-left: 4px solid #f59e0b; padding: 12px; margin: 15px 0; border-radius: 8px;">
                    <strong style="color: #d97706;">⚠️ IMPORTANT NOTICE!</strong><br>
                    This SSL certificate expires in less than a week. Please schedule renewal soon.
                </div>';
            }
        }
        
        // Error message with better formatting
        $errorDisplay = $error !== 'N/A' && !empty($error) ? 
            '<tr style="border-bottom: 1px solid #e5e7eb;">
                <td style="padding: 12px; color: #6b7280; font-weight: 500;">❌ Error Details</td>
                <td style="padding: 12px; text-align: right; color: #dc2626; font-weight: 500;">' . $error . '</td>
            </tr>' : '';
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Monitor Alert - {$name}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Poppins', Arial, sans-serif; margin: 0; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
    <div style="max-width: 600px; margin: 0 auto; background: white; border-radius: 24px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
        
        <!-- Animated Header -->
        <div style="background: linear-gradient(135deg, {$color} 0%, {$color}dd 100%); padding: 32px; text-align: center;">
            <div style="font-size: 56px; margin-bottom: 12px; animation: pulse 1s;">{$statusIcon}</div>
            <h1 style="color: white; margin: 0; font-size: 32px; font-weight: bold; letter-spacing: -0.5px;">
                {$status}
            </h1>
            <p style="color: white; margin: 12px 0 0; opacity: 0.95; font-size: 14px;">
                {$statusEmoji}
            </p>
        </div>
        
        <!-- Main Content -->
        <div style="padding: 32px;">
            
            <!-- Site Name Badge -->
            <div style="text-align: center; margin-bottom: 24px;">
                <div style="display: inline-block; background: #f3f4f6; padding: 8px 20px; border-radius: 40px;">
                    <span style="font-size: 18px; font-weight: 600; color: #1f2937;">🌐 {$name}</span>
                </div>
            </div>
            
            {$expiryWarning}
            
            <!-- Details Table -->
            <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                <tr style="border-bottom: 1px solid #e5e7eb;">
                    <td style="padding: 12px; color: #6b7280; font-weight: 500;">🔗 URL</td>
                    <td style="padding: 12px; text-align: right;">
                        <a href="{$url}" style="color: #3b82f6; text-decoration: none; font-weight: 500;">{$url}</a>
                    </td>
                </tr>
                <tr style="border-bottom: 1px solid #e5e7eb;">
                    <td style="padding: 12px; color: #6b7280; font-weight: 500;">📊 Status</td>
                    <td style="padding: 12px; text-align: right;">
                        <span style="background: {$bgColor}; color: {$color}; padding: 4px 12px; border-radius: 20px; font-weight: bold;">
                            {$status}
                        </span>
                    </td>
                </tr>
                {$expiryInfo}
                {$errorDisplay}
                <tr style="border-bottom: 1px solid #e5e7eb;">
                    <td style="padding: 12px; color: #6b7280; font-weight: 500;">⏱️ Response Time</td>
                    <td style="padding: 12px; text-align: right;">
                        <span style="color: {$rtColor}; font-weight: bold;">
                            {$rtIcon} {$rt} ms ({$rtText})
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px; color: #6b7280; font-weight: 500;">🕐 Timestamp</td>
                    <td style="padding: 12px; text-align: right; color: #6b7280;">{$time}</td>
                </tr>
            </table>
            
            <!-- Action Button -->
            <div style="text-align: center; margin: 30px 0 20px;">
                <a href="{$url}" style="display: inline-block; background: linear-gradient(135deg, {$color} 0%, {$color}dd 100%); color: white; padding: 12px 32px; text-decoration: none; border-radius: 40px; font-weight: bold; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    🔍 Check Site Now
                </a>
            </div>
            
            <!-- Additional Info -->
            <div style="background: #f9fafb; border-radius: 12px; padding: 16px; margin-top: 24px; border: 1px solid #e5e7eb;">
                <p style="margin: 0 0 8px; font-weight: bold; color: #374151;">📋 What to do next:</p>
                <ul style="margin: 0; padding-left: 20px; color: #6b7280; line-height: 1.6;">
                    " . ($event === 'ssl_expiry' ? '
                    <li>Renew your SSL certificate immediately</li>
                    <li>Contact your hosting provider for assistance</li>
                    <li>Verify the new certificate is installed correctly</li>' : ($event === 'recovery' ? '
                    <li>Site is now accessible - no action needed</li>
                    <li>Monitor for any recurring issues</li>
                    <li>Review incident report for details</li>' : '
                    <li>Check if the server is responding</li>
                    <li>Verify network connectivity</li>
                    <li>Review server logs for errors</li>
                    <li>Contact your hosting provider if issue persists</li>') . "
                </ul>
            </div>
        </div>
        
        <!-- Footer -->
        <div style="background: #f9fafb; padding: 20px; text-align: center; border-top: 1px solid #e5e7eb;">
            <p style="margin: 0 0 8px; color: #6b7280; font-size: 12px;">
                🛡️ Site Monitor Pro - Real-time Website Monitoring
            </p>
            <p style="margin: 0; color: #9ca3af; font-size: 11px;">
                Alert cooldown: 1 hour | Check frequency: Every minute
            </p>
            <p style="margin: 8px 0 0; color: #9ca3af; font-size: 11px;">
                <a href="https://monitoring.euclideesolutions.com" style="color: #9ca3af; text-decoration: none;">Dashboard</a> | 
                <a href="#" style="color: #9ca3af; text-decoration: none;">Configure Alerts</a>
            </p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    // -------------------------------------------------------------------------
    // Send email via PHPMailer
    // -------------------------------------------------------------------------
        // -------------------------------------------------------------------------
    // Send email via PHPMailer with proper UTF-8 encoding
    // -------------------------------------------------------------------------
    private static function sendEmail(string $to, string $subject, string $htmlBody): void {
        // Ensure autoloader is loaded
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
        }
        
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log('[Alert] PHPMailer not installed. Run: composer install');
            return;
        }

        $to = filter_var(trim($to), FILTER_SANITIZE_EMAIL);
        if (!self::validateEmail($to)) {
            error_log('[Alert] Invalid recipient email in sendEmail: ' . $to);
            return;
        }

        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
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
            error_log("[Alert] Email sent to $to");
        } catch (Exception $e) {
            error_log('[Alert] Email failed: ' . $mail->ErrorInfo);
        }
    }
}
