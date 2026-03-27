<?php
// =============================================================================
// api.php - REST endpoints for AJAX calls from the dashboard
// =============================================================================

define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/Statistics.php';
require_once MONITOR_ROOT . '/includes/Checker.php';
require_once MONITOR_ROOT . '/includes/auth.php';

session_start();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Session auth check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    jsonError('Unauthorized', 401);
}

// CSRF check for mutating requests
$method = $_SERVER['REQUEST_METHOD'];
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        jsonError('Invalid CSRF token', 403);
    }
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // Dashboard summary cards
        case 'health':
            jsonOk(Statistics::getSystemHealth());
            break;

        // All sites with latest status
        case 'sites':
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
            jsonOk($sites);
            break;

        // Response time trend (multi-site, last 24h)
        case 'response_trend':
            $siteIds = array_map('intval', explode(',', $_GET['ids'] ?? ''));
            $data    = [];
            foreach ($siteIds as $id) {
                if ($id > 0) {
                    $data[$id] = Statistics::getResponseTimeTrend($id, 24);
                }
            }
            jsonOk($data);
            break;

        // SSL expiry bar chart data
        case 'ssl_expiry':
            jsonOk(Statistics::getSSLExpiryInfo());
            break;

        // 30-day uptime area chart
        case 'uptime_chart':
            $id = (int) ($_GET['id'] ?? 0);
            jsonOk(Statistics::getDailyUptime($id, 30));
            break;

        // Recent incidents
        case 'incidents':
            $id    = (int) ($_GET['id'] ?? 0);
            $limit = (int) ($_GET['limit'] ?? 50);
            $data  = $id > 0
                ? Statistics::getIncidents($id, $limit)
                : Statistics::getAllIncidents($limit);
            jsonOk($data);
            break;

        // Response histogram
        case 'histogram':
            $id = (int) ($_GET['id'] ?? 0);
            jsonOk(Statistics::getResponseHistogram($id));
            break;

        // Slowest sites
        case 'slowest':
            jsonOk(Statistics::getSlowestSites(5));
            break;

        // Site detail: full trend + histogram + incidents + logs
        case 'site_detail':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) jsonError('Missing site id', 400);
            $site = Database::fetchOne('SELECT * FROM sites WHERE id = ?', [$id]);
            if (!$site) jsonError('Site not found', 404);
            jsonOk([
                'site'      => $site,
                'trend'     => Statistics::getResponseTimeTrend($id, 168), // 7 days
                'daily'     => Statistics::getDailyUptime($id, 30),
                'histogram' => Statistics::getResponseHistogram($id),
                'incidents' => Statistics::getIncidents($id, 20),
                'logs'      => Statistics::getRecentLogs($id, 100),
                'sla'       => Statistics::getMonthlySLA($id),
                'uptime30'  => Statistics::getUptime($id, 30),
            ]);
            break;

        // Test connection before saving
        case 'test_connection':
            if ($method !== 'POST') jsonError('POST required', 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $result = Checker::check($data);
            jsonOk($result);
            break;

        // Save / update a site
        case 'save_site':
            if ($method !== 'POST') jsonError('POST required', 405);
            $data = json_decode(file_get_contents('php://input'), true);
            saveSite($data);
            break;

        // Delete a site
        case 'delete_site':
            if ($method !== 'POST') jsonError('POST required', 405);
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) jsonError('Missing id', 400);

            // Delete associated data first
            Database::execute('DELETE FROM incidents WHERE site_id = ?', [$id]);
            Database::execute('DELETE FROM logs WHERE site_id = ?', [$id]);
            Database::execute('DELETE FROM sites WHERE id = ?', [$id]);

            jsonOk(['deleted' => $id]);
            break;

        // Bulk delete sites
        case 'bulk_delete_sites':
            if ($method !== 'POST') jsonError('POST required', 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $ids  = array_filter(array_map('intval', $data['ids'] ?? []), fn($i) => $i > 0);
            if (empty($ids)) jsonError('No valid IDs provided', 400);

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            // Delete associated data first
            Database::execute("DELETE FROM incidents WHERE site_id IN ($placeholders)", $ids);
            Database::execute("DELETE FROM logs WHERE site_id IN ($placeholders)", $ids);
            Database::execute("DELETE FROM sites WHERE id IN ($placeholders)", $ids);

            jsonOk(['deleted' => count($ids)]);
            break;

        // Export logs as CSV (returns JSON array for client-side CSV generation)
        case 'export_logs':
            $id   = (int) ($_GET['id'] ?? 0);
            $logs = Statistics::getRecentLogs($id, 10000);
            jsonOk($logs);
            break;

        // Purge old logs manually
        case 'purge_logs':
            if ($method !== 'POST') jsonError('POST required', 405);
            $deleted = Database::execute(
                'DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
                [LOG_RETENTION_DAYS]
            );
            jsonOk(['deleted' => $deleted]);
            break;

        default:
            jsonError('Unknown action', 400);
    }
} catch (Throwable $e) {
    error_log('[API] ' . $e->getMessage());
    jsonError('Server error: ' . $e->getMessage(), 500);
}

// =============================================================================
// Helpers
// =============================================================================

function saveSite(array $d): void {
    $fields = ['name', 'url', 'check_type', 'port', 'hostname', 'keyword',
               'expected_status', 'alert_email', 'alert_phone', 'alert_telegram', 'is_active', 'tags'];

    $clean = [];
    foreach ($fields as $f) {
        $clean[$f] = isset($d[$f]) ? trim((string) $d[$f]) : null;
    }

    // Basic validation
    if (empty($clean['name'])) jsonError('Name is required');
    if (empty($clean['url'])) jsonError('URL is required');

    // More URL validation based on check type
    if (in_array($clean['check_type'], ['http', 'keyword', 'ssl'])) {
        if (!filter_var($clean['url'], FILTER_VALIDATE_URL)) {
            jsonError('Invalid URL format');
        }
    }

    $clean['is_active']       = isset($d['is_active']) ? (int) $d['is_active'] : 1;
    $clean['expected_status'] = (int) ($d['expected_status'] ?? 200);

    if (!empty($d['id'])) {
        $id = (int) $d['id'];
        Database::execute(
            'UPDATE sites SET name=?, url=?, check_type=?, port=?, hostname=?, keyword=?,
             expected_status=?, alert_email=?, alert_phone=?, alert_telegram=?, is_active=?, tags=?
             WHERE id=?',
            array_merge(array_values($clean), [$id])
        );
        jsonOk(['updated' => $id, 'message' => 'Monitor updated successfully']);
    } else {
        $id = Database::insert(
            'INSERT INTO sites (name, url, check_type, port, hostname, keyword,
             expected_status, alert_email, alert_phone, alert_telegram, is_active, tags)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            array_values($clean)
        );
        jsonOk(['created' => $id, 'message' => 'Monitor added successfully']);
    }
}

function jsonOk(mixed $data): never {
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function jsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
