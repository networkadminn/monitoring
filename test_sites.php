<?php
define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/Statistics.php';

header('Content-Type: application/json');

try {
    $sites = Database::fetchAll(
        'SELECT s.id, s.name, s.url, s.check_type, s.uptime_percentage, s.tags,
                l.status, l.response_time, l.error_message, l.ssl_expiry_days, l.created_at AS last_checked
         FROM sites s
         LEFT JOIN logs l ON l.id = (
             SELECT id FROM logs WHERE site_id = s.id ORDER BY created_at DESC LIMIT 1
         )
         WHERE s.is_active = 1
         ORDER BY s.name ASC'
    );
    echo json_encode(['success' => true, 'count' => count($sites), 'data' => array_slice($sites, 0, 3)]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
