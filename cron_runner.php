<?php
// =============================================================================
// cron_runner.php - Monitoring engine, run via cron every minute
// Usage: * * * * * php /path/to/monitor/cron_runner.php >> /var/log/monitor.log 2>&1
// =============================================================================

define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/Checker.php';
require_once MONITOR_ROOT . '/includes/Alert.php';
require_once MONITOR_ROOT . '/includes/Statistics.php';

// Autoload PHPMailer if composer is available
if (file_exists(MONITOR_ROOT . '/vendor/autoload.php')) {
    require_once MONITOR_ROOT . '/vendor/autoload.php';
}

$startTime = microtime(true);
$checked   = 0;
$errors    = 0;

echo '[' . date('Y-m-d H:i:s') . '] Cron runner started' . PHP_EOL;

// -------------------------------------------------------------------------
// 1. Fetch all active sites
// -------------------------------------------------------------------------
$sites = Database::fetchAll('SELECT * FROM sites WHERE is_active = 1');

foreach ($sites as $site) {
    $siteId = (int) $site['id'];

    // -------------------------------------------------------------------------
    // 2. Run the appropriate check
    // -------------------------------------------------------------------------
    $result = Checker::check($site);

    // -------------------------------------------------------------------------
    // 3. Save log entry
    // -------------------------------------------------------------------------
    Database::execute(
        'INSERT INTO logs (site_id, status, response_time, error_message, ssl_expiry_days, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())',
        [
            $siteId,
            $result['status'],
            $result['response_time'],
            $result['error_message'],
            $result['ssl_expiry_days'],
        ]
    );

    // -------------------------------------------------------------------------
    // 4. Update uptime_percentage on sites table
    // -------------------------------------------------------------------------
    $uptime = Statistics::getUptime($siteId, 30);
    Database::execute(
        'UPDATE sites SET uptime_percentage = ? WHERE id = ?',
        [$uptime, $siteId]
    );

    // -------------------------------------------------------------------------
    // 5. Smart threshold-based incident tracking
    // -------------------------------------------------------------------------
    try {
        $db = Database::getInstance();
        $db->beginTransaction();

        $failureThreshold = (int) ($site['failure_threshold'] ?? 3);
        $recoveryThreshold = (int) ($site['recovery_threshold'] ?? 3);

        if ($result['status'] === 'down') {
            // Increment consecutive failures, reset recoveries
            Database::execute(
                'UPDATE sites SET consecutive_failures = consecutive_failures + 1, consecutive_recoveries = 0 WHERE id = ?',
                [$siteId]
            );
            
            $currentFailures = (int) $site['consecutive_failures'] + 1;
            
            // Only trigger DOWN alert once we hit the threshold
            if ($currentFailures === $failureThreshold) {
                $existingIncident = Database::fetchOne(
                    'SELECT id FROM incidents WHERE site_id = ? AND ended_at IS NULL LIMIT 1',
                    [$siteId]
                );
                if (!$existingIncident) {
                    Database::execute(
                        'INSERT INTO incidents (site_id, started_at, error_message) VALUES (?, NOW(), ?)',
                        [$siteId, $result['error_message']]
                    );
                    Alert::send($site, $result, 'down');
                    Database::execute(
                        'UPDATE sites SET last_down_alert_time = NOW() WHERE id = ?',
                        [$siteId]
                    );
                    echo "  [DOWN-ALERT] {$site['name']} (after $currentFailures consecutive failures): {$result['error_message']}" . PHP_EOL;
                    $errors++;
                }
            } elseif ($currentFailures < $failureThreshold) {
                echo "  [FAILING] {$site['name']} ($currentFailures/$failureThreshold failures): {$result['error_message']}" . PHP_EOL;
            }

        } else {
            // Status is UP
            // Increment consecutive recoveries, reset failures
            Database::execute(
                'UPDATE sites SET consecutive_recoveries = consecutive_recoveries + 1, consecutive_failures = 0 WHERE id = ?',
                [$siteId]
            );

            $currentRecoveries = (int) $site['consecutive_recoveries'] + 1;
            
            // Check if we had an open incident
            $openIncident = Database::fetchOne(
                'SELECT id, started_at FROM incidents WHERE site_id = ? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1',
                [$siteId]
            );

            if ($openIncident) {
                // Only send recovery alert once we hit recovery threshold
                if ($currentRecoveries === $recoveryThreshold) {
                    $duration = time() - strtotime($openIncident['started_at']);
                    Database::execute(
                        'UPDATE incidents SET ended_at = NOW(), duration_seconds = ? WHERE id = ?',
                        [$duration, $openIncident['id']]
                    );
                    Alert::send($site, $result, 'recovery');
                    Database::execute(
                        'UPDATE sites SET last_recovery_alert_time = NOW() WHERE id = ?',
                        [$siteId]
                    );
                    echo "  [RECOVERY-ALERT] {$site['name']} recovered (after $currentRecoveries consecutive successes)" . PHP_EOL;
                } elseif ($currentRecoveries < $recoveryThreshold) {
                    echo "  [RECOVERING] {$site['name']} ($currentRecoveries/$recoveryThreshold successes)" . PHP_EOL;
                }
            } else {
                // No open incident, just reset counters if approaching threshold
                if ($currentRecoveries === 1) {
                    echo "  [OK] {$site['name']}: " . ($result['response_time'] ?? 0) . "ms" . PHP_EOL;
                }
            }
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        echo "  [ERROR] Incident tracking failed: {$e->getMessage()}" . PHP_EOL;
    }

    // -------------------------------------------------------------------------
    // 6. SSL Expiry Alert: check if expiring within 10 days
    // -------------------------------------------------------------------------
    if ($result['ssl_expiry_days'] !== null && $result['ssl_expiry_days'] <= 10) {
        Alert::send($site, $result, 'ssl_expiry');
        echo "  [SSL]  {$site['name']}: expiring in {$result['ssl_expiry_days']} days" . PHP_EOL;
    }

    $checked++;
}

// -------------------------------------------------------------------------
// 7. Aggregate stats (runs every minute but queries guard against duplicates)
// -------------------------------------------------------------------------
Statistics::aggregateHourlyStats();

// Aggregate daily stats once per day (around midnight)
if (date('H:i') === '00:01') {
    Statistics::aggregateDailyUptime();
}

// -------------------------------------------------------------------------
// 7. Clean up old logs (retain LOG_RETENTION_DAYS)
// -------------------------------------------------------------------------
$deleted = Database::execute(
    'DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
    [LOG_RETENTION_DAYS]
);
if ($deleted > 0) {
    echo "  [CLEANUP] Deleted $deleted old log rows" . PHP_EOL;
}

$elapsed = round(microtime(true) - $startTime, 3);
echo '[' . date('Y-m-d H:i:s') . "] Done. Checked: $checked sites, Errors: $errors, Time: {$elapsed}s" . PHP_EOL;
