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
    // 5. Incident tracking: detect transitions
    // -------------------------------------------------------------------------
    try {
        $db = Database::getInstance();
        $db->beginTransaction();

        $prevLog = Database::fetchOne(
            'SELECT status FROM logs WHERE site_id = ? ORDER BY created_at DESC LIMIT 1 OFFSET 1',
            [$siteId]
        );
        $prevStatus = $prevLog['status'] ?? 'up';

        if ($result['status'] === 'down' && $prevStatus !== 'down') {
            // Site just went DOWN — open a new incident
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
                echo "  [DOWN] {$site['name']}: {$result['error_message']}" . PHP_EOL;
                $errors++;
            }

        } elseif ($result['status'] === 'up' && $prevStatus === 'down') {
            // Site just came back UP — close the open incident
            $openIncident = Database::fetchOne(
                'SELECT id, started_at FROM incidents WHERE site_id = ? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1',
                [$siteId]
            );
            if ($openIncident) {
                $duration = time() - strtotime($openIncident['started_at']);
                Database::execute(
                    'UPDATE incidents SET ended_at = NOW(), duration_seconds = ? WHERE id = ?',
                    [$duration, $openIncident['id']]
                );
            }
            Alert::send($site, $result, 'recovery');
            echo "  [UP]   {$site['name']}: recovered" . PHP_EOL;
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
// 6. Aggregate stats (runs every minute but queries guard against duplicates)
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
