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

// =============================================================================
// Utility functions
// =============================================================================

function validateEmail(string $email): bool {
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

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Rate limiting: simple per-session check
function checkRateLimit(string $action, int $maxPerMinute = 60): bool {
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

        // Purge old logs manually
        case 'purge_logs':
            if ($method !== 'POST') jsonError('POST required', 405);
            $deleted = Database::execute(
                'DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
                [LOG_RETENTION_DAYS]
            );
            jsonOk(['deleted' => $deleted]);
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
               'expected_status', 'alert_email', 'alert_phone', 'alert_telegram', 'is_active', 'tags'];

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

    $clean['is_active']       = isset($d['is_active']) ? (int) $d['is_active'] : 1;
    $clean['expected_status'] = (int) ($d['expected_status'] ?? 200);

    $id = Database::insert(
        'INSERT INTO sites (name, url, check_type, port, hostname, keyword,
            expected_status, alert_email, alert_phone, alert_telegram, is_active, tags)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        array_values($clean)
    );
    jsonOk(['created' => $id, 'message' => 'Monitor added successfully']);
}

function updateSite(array $d): void {
    if (empty($d['id'])) jsonError('Missing ID');
    $id = (int) $d['id'];

    $fields = ['name', 'url', 'check_type', 'port', 'hostname', 'keyword',
               'expected_status', 'alert_email', 'alert_phone', 'alert_telegram', 'is_active', 'tags'];

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

    $clean['is_active']       = isset($d['is_active']) ? (int) $d['is_active'] : 1;
    $clean['expected_status'] = (int) ($d['expected_status'] ?? 200);

    Database::execute(
        'UPDATE sites SET name=?, url=?, check_type=?, port=?, hostname=?, keyword=?,
            expected_status=?, alert_email=?, alert_phone=?, alert_telegram=?, is_active=?, tags=?
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
