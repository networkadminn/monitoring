<?php
// =============================================================================
// includes/Alert.php - Multi-channel alert system with suppression
// =============================================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

class Alert {

    // -------------------------------------------------------------------------
    // Send all configured alerts for a site, respecting cooldown
    // -------------------------------------------------------------------------
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
            self::sendEmail($site['alert_email'], $subject, $body);
        }

        // Record that we sent this alert (for cooldown)
        self::recordAlert($siteId, $alertType);
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
        $icon = $event === 'recovery' ? '✅ RECOVERED' : '🚨 ALERT';
        return "$icon: {$site['name']} is " . ($event === 'recovery' ? 'back online' : 'DOWN');
    }

    // -------------------------------------------------------------------------
    // Build HTML alert body
    // -------------------------------------------------------------------------
    private static function buildBody(array $site, array $result, string $event): string {
        $time    = date('Y-m-d H:i:s T');
        $status  = strtoupper($event === 'recovery' ? 'RECOVERED' : $result['status']);
        $color   = $event === 'recovery' ? '#27ae60' : '#e74c3c';
        $error   = htmlspecialchars($result['error_message'] ?? 'N/A');
        $rt      = $result['response_time'] ?? 'N/A';
        $url     = htmlspecialchars($site['url']);
        $name    = htmlspecialchars($site['name']);

        return <<<HTML
<div style="font-family:Arial,sans-serif;max-width:600px">
  <div style="background:{$color};color:#fff;padding:16px;border-radius:6px 6px 0 0">
    <h2 style="margin:0">{$status}: {$name}</h2>
  </div>
  <div style="border:1px solid #ddd;padding:16px;border-radius:0 0 6px 6px">
    <table style="width:100%;border-collapse:collapse">
      <tr><td style="padding:6px;color:#666">URL</td><td><a href="{$url}">{$url}</a></td></tr>
      <tr><td style="padding:6px;color:#666">Status</td><td><strong>{$status}</strong></td></tr>
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
            $mail->AltBody = strip_tags($htmlBody);
            $mail->send();
        } catch (MailException $e) {
            error_log('[Alert] Email failed: ' . $e->getMessage());
        }
    }
}
