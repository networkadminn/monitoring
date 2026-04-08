<?php
// =============================================================================
// includes/ReportMailer.php - Weekly/monthly email reports
// =============================================================================

require_once __DIR__ . '/Statistics.php';

class ReportMailer {

    public static function sendWeeklyReport(string $toEmail): void {
        $sites   = Database::fetchAll("SELECT * FROM sites WHERE is_active = 1");
        $subject = '[Site Monitor] Weekly Uptime Report — ' . date('M d, Y');
        $body    = self::buildReportHtml($sites, 7, 'Weekly');
        self::mail($toEmail, $subject, $body);
    }

    public static function sendMonthlyReport(string $toEmail): void {
        $sites   = Database::fetchAll("SELECT * FROM sites WHERE is_active = 1");
        $subject = '[Site Monitor] Monthly Uptime Report — ' . date('F Y');
        $body    = self::buildReportHtml($sites, 30, 'Monthly');
        self::mail($toEmail, $subject, $body);
    }

    private static function buildReportHtml(array $sites, int $days, string $period): string {
        $health   = Statistics::getSystemHealth();
        $incidents = Statistics::getAllIncidents(20);
        $rows = '';

        foreach ($sites as $s) {
            $uptime = Statistics::getUptime($s['id'], $days);
            $color  = $uptime >= 99 ? '#10b981' : ($uptime >= 95 ? '#f59e0b' : '#ef4444');
            $rows  .= "<tr>
                <td style='padding:10px 12px;border-bottom:1px solid #e5e7eb'>{$s['name']}</td>
                <td style='padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-size:12px'>{$s['url']}</td>
                <td style='padding:10px 12px;border-bottom:1px solid #e5e7eb;font-weight:700;color:{$color}'>{$uptime}%</td>
                <td style='padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#6b7280'>" . strtoupper($s['check_type']) . "</td>
            </tr>";
        }

        $incidentRows = '';
        foreach ($incidents as $i) {
            $dur = $i['duration_seconds'] ? round($i['duration_seconds'] / 60) . ' min' : 'Ongoing';
            $incidentRows .= "<tr>
                <td style='padding:8px 12px;border-bottom:1px solid #e5e7eb'>{$i['site_name']}</td>
                <td style='padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:12px'>{$i['started_at']}</td>
                <td style='padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:12px'>{$dur}</td>
                <td style='padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:12px;color:#6b7280'>" . htmlspecialchars($i['error_message'] ?? '') . "</td>
            </tr>";
        }

        $scoreColor = $health['health_score'] >= 95 ? '#10b981' : ($health['health_score'] >= 80 ? '#f59e0b' : '#ef4444');

        return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:20px;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif">
<div style="max-width:700px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 4px 6px rgba(0,0,0,0.07)">
  <div style="background:linear-gradient(135deg,#1e293b,#0f172a);padding:32px 40px;color:#fff">
    <h1 style="margin:0;font-size:24px;font-weight:700">$period Uptime Report</h1>
    <p style="margin:8px 0 0;opacity:0.7;font-size:14px">Period: Last $days days — Generated {$_SERVER['SERVER_NAME'] ?? 'Site Monitor'}</p>
  </div>
  <div style="padding:32px 40px">
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:32px">
      <div style="background:#f8fafc;border-radius:8px;padding:16px;text-align:center">
        <div style="font-size:28px;font-weight:800;color:{$scoreColor}">{$health['health_score']}%</div>
        <div style="font-size:12px;color:#6b7280;margin-top:4px">Health Score</div>
      </div>
      <div style="background:#f8fafc;border-radius:8px;padding:16px;text-align:center">
        <div style="font-size:28px;font-weight:800;color:#3b82f6">{$health['total_sites']}</div>
        <div style="font-size:12px;color:#6b7280;margin-top:4px">Monitors</div>
      </div>
      <div style="background:#f8fafc;border-radius:8px;padding:16px;text-align:center">
        <div style="font-size:28px;font-weight:800;color:#ef4444">{$health['sites_down']}</div>
        <div style="font-size:12px;color:#6b7280;margin-top:4px">Currently Down</div>
      </div>
    </div>
    <h2 style="font-size:16px;font-weight:600;margin:0 0 12px">Monitor Uptime</h2>
    <table style="width:100%;border-collapse:collapse;margin-bottom:32px">
      <thead><tr style="background:#f8fafc">
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600">Monitor</th>
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600">URL</th>
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600">Uptime</th>
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600">Type</th>
      </tr></thead>
      <tbody>$rows</tbody>
    </table>
    <h2 style="font-size:16px;font-weight:600;margin:0 0 12px">Recent Incidents</h2>
    <table style="width:100%;border-collapse:collapse">
      <thead><tr style="background:#f8fafc">
        <th style="padding:8px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600">Site</th>
        <th style="padding:8px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600">Started</th>
        <th style="padding:8px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600">Duration</th>
        <th style="padding:8px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600">Error</th>
      </tr></thead>
      <tbody>$incidentRows</tbody>
    </table>
  </div>
  <div style="background:#f8fafc;padding:20px 40px;text-align:center;font-size:12px;color:#9ca3af;border-top:1px solid #e5e7eb">
    <a href="APP_URL_PLACEHOLDER" style="color:#3b82f6;text-decoration:none">View Dashboard</a> · Site Monitor
  </div>
</div>
</body></html>
HTML;
    }

    private static function mail(string $to, string $subject, string $body): void {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
            }
        }

        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log('[ReportMailer] PHPMailer not available');
            return;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom(FROM_EMAIL, FROM_NAME);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = str_replace('APP_URL_PLACEHOLDER', APP_URL, $body);
            $mail->AltBody = strip_tags($body);
            $mail->send();
        } catch (Exception $e) {
            error_log('[ReportMailer] Failed: ' . $e->getMessage());
        }
    }
}
