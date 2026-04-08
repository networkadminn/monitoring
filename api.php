<?php
// =============================================================================
// api.php - REST endpoints for AJAX calls from the dashboard
// =============================================================================

define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/Statistics.php';
require_once MONITOR_ROOT . '/includes/Checker.php';
require_once MONITOR_ROOT . '/includes/Helpers.php';
require_once MONITOR_ROOT . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Session-based rate limiting helper
function checkSessionRateLimit(string $action, int $maxPerMinute = 60): bool {
    $key = "rate_limit_{$action}";
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'reset_at' => time() + 60];
    }

    if (time() >= $_SESSION[$key]['reset_at']) {
        $_SESSION[$key] = ['count' => 0, 'reset_at' => time() + 60];
    }

    $_SESSION[$key]['count']++;
    return $_SESSION[$key]['count'] <= $maxPerMinute;
}

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
            $filterType = $_GET['type'] ?? '';
            $filterTag  = $_GET['tag'] ?? '';

            $where = ['s.is_active = 1'];
            $params = [];

            if ($filterType) {
                switch ($filterType) {
                    case 'websites':
                        $where[] = "s.check_type IN ('http','keyword','ssl')";
                        break;
                    case 'ssl':
                        $where[] = "s.check_type = 'ssl'";
                        break;
                    case 'ports':
                        $where[] = "s.check_type = 'port'";
                        break;
                }
            }

            if ($filterTag) {
                $where[] = 'FIND_IN_SET(?, s.tags) > 0';
                $params[] = $filterTag;
            }

            $query = 'SELECT s.id, s.name, s.url, s.check_type, s.uptime_percentage, s.tags,
                            l.status, l.response_time, l.error_message, l.ssl_expiry_days, l.created_at AS last_checked
                     FROM sites s
                     LEFT JOIN logs l ON l.id = (
                         SELECT id FROM logs WHERE site_id = s.id ORDER BY created_at DESC LIMIT 1
                     )
                     WHERE ' . implode(' AND ', $where) . '
                     ORDER BY s.name ASC';

            $sites = Database::fetchAll($query, $params);
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

        // Global system uptime trend
        case 'system_uptime':
            jsonOk(Statistics::getSystemUptimeTrend(30));
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
            if (!checkRateLimit('test_connection', 30)) {
                jsonError('Rate limit exceeded for connection tests (30 per minute)', 429);
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $result = Checker::check($data);
            jsonOk($result);
            break;

        // Immediate check for an existing site
        case 'check_site':
            if ($method !== 'POST') jsonError('POST required', 405);
            if (!checkRateLimit('check_site', 20)) {
                jsonError('Rate limit exceeded for immediate checks (20 per minute)', 429);
            }
            $payload = json_decode(file_get_contents('php://input'), true);
            $siteId  = (int) ($payload['id'] ?? 0);
            if (!$siteId) jsonError('Missing site id', 400);

            $site = Database::fetchOne('SELECT * FROM sites WHERE id = ?', [$siteId]);
            if (!$site) jsonError('Site not found', 404);

            $result = Checker::check($site);
            Database::execute(
                'INSERT INTO logs (site_id, status, response_time, error_message, ssl_expiry_days, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [$siteId, $result['status'], $result['response_time'], $result['error_message'], $result['ssl_expiry_days']]
            );
            $uptime = Statistics::getUptime($siteId, 30);
            Database::execute('UPDATE sites SET uptime_percentage = ? WHERE id = ?', [$uptime, $siteId]);

            jsonOk(['result' => $result, 'uptime_30d' => $uptime]);
            break;

        // Save / update a site
        case 'add_site':
            if ($method !== 'POST') jsonError('POST required', 405);
            $data = json_decode(file_get_contents('php://input'), true);
            addSite($data);
            break;

        case 'update_site':
            if ($method !== 'POST') jsonError('POST required', 405);
            $data = json_decode(file_get_contents('php://input'), true);
            updateSite($data);
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

        // Multi-location results for a site
        case 'location_results':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) jsonError('id required', 400);
            require_once MONITOR_ROOT . '/includes/MultiLocation.php';
            jsonOk([
                'latest'    => MultiLocation::getLatestResults($id),
                'history'   => MultiLocation::getLocationHistory($id, 24),
                'locations' => MultiLocation::getAllLocations(),
            ]);
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

        // Maintenance windows
        case 'add_maintenance':
            if ($method !== 'POST') jsonError('POST required', 405);
            $d = json_decode(file_get_contents('php://input'), true);
            $siteId = (int)($d['site_id'] ?? 0);
            if (!$siteId) jsonError('site_id required', 400);
            if (empty($d['title'])) jsonError('title required', 400);
            if (empty($d['start_time']) || empty($d['end_time'])) jsonError('start_time and end_time required', 400);
            require_once MONITOR_ROOT . '/includes/MaintenanceWindow.php';
            $id = MaintenanceWindow::create($siteId, $d['title'], $d['start_time'], $d['end_time'], $d['description'] ?? '');
            jsonOk(['id' => $id]);
            break;

        case 'delete_maintenance':
            if ($method !== 'POST') jsonError('POST required', 405);
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) jsonError('id required', 400);
            require_once MONITOR_ROOT . '/includes/MaintenanceWindow.php';
            MaintenanceWindow::delete($id);
            jsonOk(['deleted' => $id]);
            break;

        // Status page config
        case 'save_status_page':
            if ($method !== 'POST') jsonError('POST required', 405);
            $d = json_decode(file_get_contents('php://input'), true);
            require_once MONITOR_ROOT . '/includes/StatusPage.php';
            StatusPage::saveConfig([
                'title'       => substr(trim($d['title'] ?? 'Service Status'), 0, 120),
                'description' => substr(trim($d['description'] ?? ''), 0, 300),
                'logo_url'    => substr(trim($d['logo_url'] ?? ''), 0, 500),
                'is_public'   => (int)($d['is_public'] ?? 1),
                'show_values' => (int)($d['show_values'] ?? 1),
                'accent_color'=> preg_match('/^#[0-9a-fA-F]{6}$/', $d['accent_color'] ?? '') ? $d['accent_color'] : '#3b82f6',
                'footer_text' => substr(trim($d['footer_text'] ?? ''), 0, 300),
            ]);
            jsonOk(['saved' => true]);
            break;

        // API keys
        case 'create_api_key':
            if ($method !== 'POST') jsonError('POST required', 405);
            $d    = json_decode(file_get_contents('php://input'), true);
            $name = substr(trim($d['name'] ?? ''), 0, 80);
            if (!$name) jsonError('name required', 400);
            $key  = 'sm_' . bin2hex(random_bytes(24));
            $hash = hash('sha256', $key);
            Database::insert(
                'INSERT INTO api_keys (name, key_hash, key_prefix, created_at) VALUES (?, ?, ?, NOW())',
                [$name, $hash, substr($key, 0, 10)]
            );
            jsonOk(['key' => $key, 'name' => $name, 'prefix' => substr($key, 0, 10)]);
            break;

        case 'list_api_keys':
            jsonOk(Database::fetchAll('SELECT id, name, key_prefix, last_used_at, created_at FROM api_keys ORDER BY created_at DESC'));
            break;

        case 'delete_api_key':
            if ($method !== 'POST') jsonError('POST required', 405);
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) jsonError('id required', 400);
            Database::execute('DELETE FROM api_keys WHERE id = ?', [$id]);
            jsonOk(['deleted' => $id]);
            break;

        // Send report manually
        case 'send_report':
            if ($method !== 'POST') jsonError('POST required', 405);
            $d    = json_decode(file_get_contents('php://input'), true);
            $type = $d['type'] ?? 'weekly';
            $to   = trim($d['email'] ?? FROM_EMAIL);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) jsonError('Invalid email', 400);
            require_once MONITOR_ROOT . '/includes/ReportMailer.php';
            if ($type === 'monthly') {
                ReportMailer::sendMonthlyReport($to);
            } else {
                ReportMailer::sendWeeklyReport($to);
            }
            jsonOk(['sent' => true, 'to' => $to]);
            break;
        // Run cron manually
        case 'run_cron':
            if ($method !== 'POST') jsonError('POST required', 405);
            if (!checkRateLimit('run_cron', 5)) {
                jsonError('Rate limit exceeded for manual cron (5 per minute)', 429);
            }
            $output = [];
            $retval = 0;
            $php     = defined('PHP_BINARY') ? PHP_BINARY : 'php';
            $script  = escapeshellarg(MONITOR_ROOT . '/cron_runner.php');
            exec("{$php} {$script} 2>&1", $output, $retval);
            jsonOk(['output' => $output, 'success' => ($retval === 0)]);
            break;

        // Test email alert notification
        case 'test_email':
            if ($method !== 'POST') jsonError('POST required', 405);
            if (!checkRateLimit('test_email', 10)) {
                jsonError('Rate limit exceeded for test emails (10 per minute)', 429);
            }
            
            // Validate SMTP configuration
            if (empty(SMTP_HOST) || empty(SMTP_USER) || empty(SMTP_PASS)) {
                jsonError('SMTP configuration is incomplete. Check config.php: SMTP_HOST, SMTP_USER, SMTP_PASS', 400);
            }
            
            if (empty(FROM_EMAIL)) {
                jsonError('FROM_EMAIL not configured in config.php', 400);
            }
            
            // Check if PHPMailer is installed
            if (!file_exists(MONITOR_ROOT . '/vendor/autoload.php')) {
                jsonError('PHPMailer not installed. Run: composer install', 400);
            }
            
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                require_once MONITOR_ROOT . '/vendor/autoload.php';
            }
            
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                jsonError('PHPMailer library not found. Run: composer install', 400);
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $alertType = $data['alert_type'] ?? 'down';
            
            if (!in_array($alertType, ['down', 'recovery', 'ssl_expiry'])) {
                jsonError('Invalid alert type. Must be: down, recovery, or ssl_expiry', 400);
            }
            
            // Create a test site object
            $testSite = [
                'id' => 0,
                'name' => 'Test Monitor - ' . ucfirst(str_replace('_', ' ', $alertType)),
                'url' => APP_URL,
                'check_type' => $alertType === 'ssl_expiry' ? 'ssl' : 'http',
                'alert_email' => FROM_EMAIL
            ];
            
            // Create test result based on alert type
            $testResult = [
                'status' => $alertType === 'recovery' ? 'up' : 'down',
                'response_time' => $alertType === 'recovery' ? 125 : 5000,
                'error_message' => $alertType === 'down' ? 'Connection timeout (test alert)' : null,
                'ssl_expiry_days' => $alertType === 'ssl_expiry' ? 5 : null
            ];
            
            // Send test email via Alert class
            require_once MONITOR_ROOT . '/includes/Alert.php';
            
            try {
                Alert::sendTestEmail($testSite, $testResult, $alertType);
                jsonOk([
                    'message' => 'Test email sent successfully',
                    'alert_type' => $alertType,
                    'email' => FROM_EMAIL
                ]);
            } catch (Throwable $sendError) {
                jsonError('Email test failed: ' . $sendError->getMessage(), 500);
            }
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

function addSite(array $d): void {
    $fields = ['name', 'url', 'check_type', 'port', 'hostname', 'keyword',
               'expected_status', 'alert_email', 'alert_phone', 'alert_telegram', 'alert_teams',
               'alert_slack', 'alert_discord', 'alert_webhook', 'alert_pagerduty',
               'is_active', 'tags', 'failure_threshold', 'recovery_threshold', 'check_interval',
               'check_locations'];

    $clean = [];
    foreach ($fields as $f) {
        $clean[$f] = isset($d[$f]) ? trim((string) $d[$f]) : null;
    }

    if (empty($clean['name'])) jsonError('Name is required');
    if (empty($clean['url'])) jsonError('URL is required');

    if (!empty($clean['alert_email'])) {
        $split = array_filter(array_map('trim', explode(',', $clean['alert_email'])));
        foreach ($split as $email) {
            if (!validateEmail($email)) {
                jsonError('Invalid alert email: ' . htmlspecialchars($email));
            }
        }
        $clean['alert_email'] = implode(',', $split);
    }

    $allowedTypes = ['http','ssl','port','dns','keyword'];
    if (!in_array($clean['check_type'], $allowedTypes, true)) {
        jsonError('Invalid check type');
    }

    if (in_array($clean['check_type'], ['http', 'keyword', 'ssl'], true)) {
        if (!filter_var($clean['url'], FILTER_VALIDATE_URL)) {
            jsonError('Invalid URL format');
        }
    }

    if ($clean['check_type'] === 'port') {
        if (empty($clean['hostname']) && empty($clean['url'])) {
            jsonError('Hostname or URL required for port check');
        }
        $port = (int) $clean['port'];
        if ($port < 1 || $port > 65535) {
            jsonError('Invalid port number');
        }
        $clean['port'] = $port;
    }

    if ($clean['check_type'] === 'dns' && empty($clean['hostname'])) {
        jsonError('Hostname required for DNS check');
    }

    if ($clean['check_type'] === 'keyword' && empty($clean['keyword'])) {
        jsonError('Keyword required for keyword check');
    }

    $clean['is_active']           = isset($d['is_active']) ? (int) $d['is_active'] : 1;
    $clean['expected_status']     = (int) ($d['expected_status'] ?? 200);
    $clean['failure_threshold']   = (int) ($d['failure_threshold'] ?? 3);
    $clean['recovery_threshold']  = (int) ($d['recovery_threshold'] ?? 3);
    $clean['check_interval']      = in_array((int)($d['check_interval'] ?? 1), [1,5,10,15,30,60]) ? (int)$d['check_interval'] : 1;
    if (empty($clean['check_locations'])) $clean['check_locations'] = 'local';

    if ($clean['failure_threshold'] < 1 || $clean['failure_threshold'] > 10) $clean['failure_threshold'] = 3;
    if ($clean['recovery_threshold'] < 1 || $clean['recovery_threshold'] > 10) $clean['recovery_threshold'] = 3;

    $id = Database::insert(
        'INSERT INTO sites (name, url, check_type, port, hostname, keyword,
            expected_status, alert_email, alert_phone, alert_telegram, alert_teams,
            alert_slack, alert_discord, alert_webhook, alert_pagerduty,
            is_active, tags, failure_threshold, recovery_threshold, check_interval, check_locations)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        array_values($clean)
    );
    jsonOk(['created' => $id, 'message' => 'Monitor added successfully']);
}

function updateSite(array $d): void {
    if (empty($d['id'])) jsonError('Missing ID');
    $id = (int) $d['id'];

    $fields = ['name', 'url', 'check_type', 'port', 'hostname', 'keyword',
               'expected_status', 'alert_email', 'alert_phone', 'alert_telegram', 'alert_teams',
               'alert_slack', 'alert_discord', 'alert_webhook', 'alert_pagerduty',
               'is_active', 'tags', 'failure_threshold', 'recovery_threshold', 'check_interval',
               'check_locations'];

    $clean = [];
    foreach ($fields as $f) {
        $clean[$f] = isset($d[$f]) ? trim((string) $d[$f]) : null;
    }

    if (empty($clean['name'])) jsonError('Name is required');
    if (empty($clean['url'])) jsonError('URL is required');

    if (!empty($clean['alert_email'])) {
        $split = array_filter(array_map('trim', explode(',', $clean['alert_email'])));
        foreach ($split as $email) {
            if (!validateEmail($email)) jsonError('Invalid alert email: ' . htmlspecialchars($email));
        }
        $clean['alert_email'] = implode(',', $split);
    }

    $allowedTypes = ['http','ssl','port','dns','keyword'];
    if (!in_array($clean['check_type'], $allowedTypes, true)) jsonError('Invalid check type');

    if (in_array($clean['check_type'], ['http', 'keyword', 'ssl'], true)) {
        if (!filter_var($clean['url'], FILTER_VALIDATE_URL)) jsonError('Invalid URL format');
    }

    if ($clean['check_type'] === 'port') {
        if (empty($clean['hostname']) && empty($clean['url'])) jsonError('Hostname or URL required for port check');
        $port = (int) $clean['port'];
        if ($port < 1 || $port > 65535) jsonError('Invalid port number');
        $clean['port'] = $port;
    }

    if ($clean['check_type'] === 'dns' && empty($clean['hostname'])) jsonError('Hostname required for DNS check');
    if ($clean['check_type'] === 'keyword' && empty($clean['keyword'])) jsonError('Keyword required for keyword check');

    $clean['is_active']          = isset($d['is_active']) ? (int) $d['is_active'] : 1;
    $clean['expected_status']    = (int) ($d['expected_status'] ?? 200);
    $clean['failure_threshold']  = (int) ($d['failure_threshold'] ?? 3);
    $clean['recovery_threshold'] = (int) ($d['recovery_threshold'] ?? 3);
    $clean['check_interval']     = in_array((int)($d['check_interval'] ?? 1), [1,5,10,15,30,60]) ? (int)$d['check_interval'] : 1;
    if (empty($clean['check_locations'])) $clean['check_locations'] = 'local';

    if ($clean['failure_threshold'] < 1 || $clean['failure_threshold'] > 10) $clean['failure_threshold'] = 3;
    if ($clean['recovery_threshold'] < 1 || $clean['recovery_threshold'] > 10) $clean['recovery_threshold'] = 3;

    Database::execute(
        'UPDATE sites SET name=?, url=?, check_type=?, port=?, hostname=?, keyword=?,
            expected_status=?, alert_email=?, alert_phone=?, alert_telegram=?, alert_teams=?,
            alert_slack=?, alert_discord=?, alert_webhook=?, alert_pagerduty=?,
            is_active=?, tags=?, failure_threshold=?, recovery_threshold=?, check_interval=?, check_locations=?
            WHERE id=?',
        array_merge(array_values($clean), [$id])
    );
    jsonOk(['updated' => $id, 'message' => 'Monitor updated successfully']);
}

function jsonOk(mixed $data): never {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function jsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
