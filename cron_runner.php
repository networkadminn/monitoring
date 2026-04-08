<?php
// =============================================================================
// cron_runner.php - Monitoring engine, run via cron every minute
// Usage: * * * * * php /path/to/monitor/cron_runner.php >> /var/log/monitor.log 2>&1
// =============================================================================

define('MONITOR_ROOT', __DIR__);

try {
    require_once MONITOR_ROOT . '/config.php';
    require_once MONITOR_ROOT . '/includes/Database.php';
    require_once MONITOR_ROOT . '/includes/Checker.php';
    require_once MONITOR_ROOT . '/includes/Alert.php';
    require_once MONITOR_ROOT . '/includes/Statistics.php';
    require_once MONITOR_ROOT . '/includes/Helpers.php';
    require_once MONITOR_ROOT . '/includes/MaintenanceWindow.php';
    require_once MONITOR_ROOT . '/includes/MultiLocation.php';

    // Autoload PHPMailer if composer is available
    if (file_exists(MONITOR_ROOT . '/vendor/autoload.php')) {
        require_once MONITOR_ROOT . '/vendor/autoload.php';
    }
} catch (Throwable $e) {
    die('[' . date('Y-m-d H:i:s') . '] FATAL ERROR: Failed to load required files: ' . $e->getMessage() . PHP_EOL);
}

$startTime = microtime(true);
$checked   = 0;
$errors    = 0;

echo '[' . date('Y-m-d H:i:s') . '] Cron runner started' . PHP_EOL;

// -------------------------------------------------------------------------
// 1. Initialize database connection
// -------------------------------------------------------------------------
try {
    Database::getInstance();
} catch (Throwable $e) {
    die('[' . date('Y-m-d H:i:s') . "] FATAL: Database connection failed: {$e->getMessage()}" . PHP_EOL);
}

// -------------------------------------------------------------------------
// 2. Fetch all active sites
// -------------------------------------------------------------------------
try {
    $sites = Database::fetchAll('SELECT * FROM sites WHERE is_active = 1');
} catch (Throwable $e) {
    die('[' . date('Y-m-d H:i:s') . "] FATAL: Failed to fetch sites: {$e->getMessage()}" . PHP_EOL);
}

if (empty($sites)) {
    echo '[' . date('Y-m-d H:i:s') . '] No active sites to check' . PHP_EOL;
    exit(0);
}

foreach ($sites as $site) {
    $siteId = (int) $site['id'];

    try {
        // -------------------------------------------------------------------------
        // Per-site check interval: skip if not due yet
        // -------------------------------------------------------------------------
        $interval = (int) ($site['check_interval'] ?? 1);
        if ($interval > 1) {
            $lastCheck = Database::fetchOne(
                'SELECT created_at FROM logs WHERE site_id = ? ORDER BY created_at DESC LIMIT 1',
                [$siteId]
            );
            if ($lastCheck) {
                $secondsSinceLast = time() - strtotime($lastCheck['created_at']);
                if ($secondsSinceLast < ($interval * 60 - 30)) { // 30s grace
                    continue; // Not due yet
                }
            }
        }

        // -------------------------------------------------------------------------
        // 3. Run the appropriate check (skip if in maintenance window)
        // -------------------------------------------------------------------------
        if (MaintenanceWindow::isActive($siteId)) {
            echo "  [MAINTENANCE] {$site['name']}: skipping checks during maintenance window" . PHP_EOL;
            $checked++;
            continue;
        }

        $result = Checker::check($site);

        // -------------------------------------------------------------------------
        // Multi-location check (if enabled for this site)
        // -------------------------------------------------------------------------
        $enabledLocations = MultiLocation::getEnabledLocations($site);
        if (count($enabledLocations) > 1) {
            $locationResults = MultiLocation::checkAll($site);
            // Save latest snapshot
            MultiLocation::saveResults($siteId, $locationResults);
            // Save history for charts
            foreach ($locationResults as $lr) {
                Database::execute(
                    'INSERT INTO location_checks_history
                        (site_id, location, location_name, status, response_time, error_message, checked_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())',
                    [$siteId, $lr['location'], $lr['location_name'], $lr['status'], $lr['response_time'], $lr['error_message']]
                );
            }
            // Aggregate: override result with multi-location consensus
            $aggregated = MultiLocation::aggregate($locationResults);
            $result['status']        = $aggregated['status'];
            $result['response_time'] = $aggregated['response_time'];
            $result['error_message'] = $aggregated['error_message'];

            $downLocs = array_filter($locationResults, fn($r) => $r['status'] === 'down');
            if (!empty($downLocs)) {
                $locNames = implode(', ', array_column($downLocs, 'location_name'));
                echo "  [MULTI-LOC] {$site['name']}: down from $locNames" . PHP_EOL;
            }
        }

        // -------------------------------------------------------------------------
        // 4. Save log entry
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
        // 5. Update uptime_percentage on sites table
        // -------------------------------------------------------------------------
        $uptime = Statistics::getUptime($siteId, 30);
        Database::execute(
            'UPDATE sites SET uptime_percentage = ? WHERE id = ?',
            [$uptime, $siteId]
        );

        // -------------------------------------------------------------------------
        // 6. Smart threshold-based incident tracking
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
            if (isset($db)) {
                $db->rollBack();
            }
            echo "  [ERROR] Incident tracking failed for {$site['name']}: {$e->getMessage()}" . PHP_EOL;
        }

        // -------------------------------------------------------------------------
        // 7. SSL Expiry Alert: check if expiring within configured threshold
        // -------------------------------------------------------------------------
        if ($result['ssl_expiry_days'] !== null && $result['ssl_expiry_days'] <= SSL_EXPIRY_WARNING_DAYS) {
            Alert::send($site, $result, 'ssl_expiry');
            echo "  [SSL]  {$site['name']}: expiring in {$result['ssl_expiry_days']} days" . PHP_EOL;
        }

        $checked++;
        
    } catch (Throwable $e) {
        echo "  [ERROR] Site check failed for {$site['name']}: {$e->getMessage()}" . PHP_EOL;
        $errors++;
    }
}

// -------------------------------------------------------------------------
// 8. Aggregate stats (runs every minute but queries guard against duplicates)
// -------------------------------------------------------------------------
try {
    Statistics::aggregateHourlyStats();

    // Aggregate daily stats once per day (around midnight)
    if (date('H:i') === '00:01') {
        Statistics::aggregateDailyUptime();
    }

    // Send weekly report every Monday at 08:00
    if (date('N') === '1' && date('H:i') === '08:00') {
        require_once MONITOR_ROOT . '/includes/ReportMailer.php';
        if (!empty(FROM_EMAIL)) {
            ReportMailer::sendWeeklyReport(FROM_EMAIL);
            echo '  [REPORT] Weekly report sent to ' . FROM_EMAIL . PHP_EOL;
        }
    }

    // Send monthly report on 1st of month at 08:00
    if (date('j') === '1' && date('H:i') === '08:00') {
        require_once MONITOR_ROOT . '/includes/ReportMailer.php';
        if (!empty(FROM_EMAIL)) {
            ReportMailer::sendMonthlyReport(FROM_EMAIL);
            echo '  [REPORT] Monthly report sent to ' . FROM_EMAIL . PHP_EOL;
        }
    }
} catch (Throwable $e) {
    echo "  [WARNING] Failed to aggregate stats: {$e->getMessage()}" . PHP_EOL;
}

// -------------------------------------------------------------------------
// 9. Clean up old logs (retain LOG_RETENTION_DAYS)
// -------------------------------------------------------------------------
try {
    $deleted = Database::execute(
        'DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
        [LOG_RETENTION_DAYS]
    );
    if ($deleted > 0) {
        echo "  [CLEANUP] Deleted $deleted old log rows" . PHP_EOL;
    }
} catch (Throwable $e) {
    echo "  [WARNING] Log cleanup failed: {$e->getMessage()}" . PHP_EOL;
}

$elapsed = round(microtime(true) - $startTime, 3);
echo '[' . date('Y-m-d H:i:s') . "] Done. Checked: $checked sites, Errors: $errors, Time: {$elapsed}s" . PHP_EOL;
