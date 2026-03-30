<?php
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
    private static function buildSubject(array $site, array $result, string $event): string {
        $icon = $event === 'recovery' ? '✅ RECOVERED' : ($event === 'ssl_expiry' ? '🎫 SSL EXPIRING' : '🚨 ALERT');
        if ($event === 'ssl_expiry') {
            return "$icon: {$site['name']} SSL expires in {$result['ssl_expiry_days']} days";
        }
        return "$icon: {$site['name']} is " . ($event === 'recovery' ? 'back online' : 'DOWN');
    }

    // -------------------------------------------------------------------------
    // Build HTML alert body
    // -------------------------------------------------------------------------
    private static function buildBody(array $site, array $result, string $event): string {
        $time    = date('Y-m-d H:i:s T');
        $status  = strtoupper($event === 'recovery' ? 'RECOVERED' : ($event === 'ssl_expiry' ? 'SSL EXPIRING' : $result['status']));
        $color   = $event === 'recovery' ? '#27ae60' : ($event === 'ssl_expiry' ? '#f39c12' : '#e74c3c');
        $error   = htmlspecialchars($result['error_message'] ?? 'N/A');
        $rt      = $result['response_time'] ?? 'N/A';
        $url     = htmlspecialchars($site['url']);
        $name    = htmlspecialchars($site['name']);
        
        $expiryInfo = '';
        if ($event === 'ssl_expiry') {
            $days = $result['ssl_expiry_days'];
            $expiryInfo = "<tr><td style=\"padding:6px;color:#666\">SSL Expiry</td><td style=\"color:#e67e22;font-weight:bold\">{$days} Days Left</td></tr>";
        }

        return <<<HTML
<div style="font-family:Arial,sans-serif;max-width:600px">
  <div style="background:{$color};color:#fff;padding:16px;border-radius:6px 6px 0 0">
    <h2 style="margin:0">{$status}: {$name}</h2>
  </div>
  <div style="border:1px solid #ddd;padding:16px;border-radius:0 0 6px 6px">
    <table style="width:100%;border-collapse:collapse">
      <tr><td style="padding:6px;color:#666">URL</td><td><a href="{$url}">{$url}</a></td></tr>
      <tr><td style="padding:6px;color:#666">Status</td><td><strong>{$status}</strong></td></tr>
      {$expiryInfo}
      <tr><td style="padding:6px;color:#666">Error</td><td>{$error}</td></tr>
      <tr><td style="padding:6px;color:#666">Response Time</td><td>{$rt} ms</td></tr>
      <tr><td style="padding:6px;color:#666">Time</td><td>{$time}</td></tr>
    </table>
  </div>
  <p style="color:#999;font-size:12px">Sent by SiteMonitor &mdash; alerts suppressed for 1 hour after this.</p>
</div>
HTML;
    }

    // -------------------------------------------------------------------------
    // Send email via PHPMailer
    // -------------------------------------------------------------------------
    private static function sendEmail(string $to, string $subject, string $htmlBody): void {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log('[Alert] PHPMailer not installed. Run: composer install');
            return;
        }

        $to = filter_var(trim($to), FILTER_SANITIZE_EMAIL);
        if (!self::validateEmail($to)) {
            error_log('[Alert] Invalid recipient email in sendEmail: ' . $to);
            return;
        }

        // Prevent newline injection in subject
        $subject = str_replace(["\r", "\n"], ' ', trim($subject));

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL on port 465
            $mail->Port       = SMTP_PORT;
            $mail->setFrom(FROM_EMAIL, FROM_NAME);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;

            $plainBody = html_entity_decode(strip_tags($htmlBody));
            $plainBody = preg_replace('/\s+/', ' ', trim($plainBody));
            $mail->AltBody = $plainBody;

            $mail->send();
        } catch (MailException $e) {
            error_log('[Alert] Email failed: ' . $e->getMessage());
        }
    }
}
