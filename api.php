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

// Session auth check with localhost bypass for testing
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']);
$isLocalDevelopment = (defined('APP_ENV') && APP_ENV === 'development') || $isLocalhost;

if (!$isLocalDevelopment && (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true)) {
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
    // Check if database is available, otherwise provide mock data
    if (!DB_AVAILABLE) {
        switch ($action) {
            case 'health':
                jsonOk([
                    'total_sites' => 28,
                    'sites_up' => 26,
                    'sites_down' => 2,
                    'avg_response' => 245,
                    'health_score' => 93
                ]);
                break;
                
            case 'sites':
                jsonOk([
                    ['id' => 1, 'name' => 'Test Site 1', 'url' => 'https://example1.com', 'status' => 'up', 'check_type' => 'http', 'response_time' => 234, 'last_checked' => date('Y-m-d H:i:s')],
                    ['id' => 2, 'name' => 'Test Site 2', 'url' => 'https://example2.com', 'status' => 'up', 'check_type' => 'http', 'response_time' => 189, 'last_checked' => date('Y-m-d H:i:s')],
                    ['id' => 3, 'name' => 'Test Site 3', 'url' => 'https://example3.com', 'status' => 'down', 'check_type' => 'ssl', 'response_time' => null, 'last_checked' => date('Y-m-d H:i:s')],
                    ['id' => 4, 'name' => 'Test Site 4', 'url' => 'https://example4.com', 'status' => 'up', 'check_type' => 'port', 'response_time' => 156, 'last_checked' => date('Y-m-d H:i:s')],
                    ['id' => 5, 'name' => 'Test Site 5', 'url' => 'https://example5.com', 'status' => 'up', 'check_type' => 'http', 'response_time' => 298, 'last_checked' => date('Y-m-d H:i:s')]
                ]);
                break;
                
            case 'incidents':
                jsonOk([
                    ['site' => 'Test Site 3', 'started' => date('Y-m-d H:i:s', strtotime('-2 hours')), 'resolved' => null, 'duration' => '2h ongoing', 'error' => 'Connection timeout'],
                    ['site' => 'Test Site 6', 'started' => date('Y-m-d H:i:s', strtotime('-1 day')), 'resolved' => date('Y-m-d H:i:s', strtotime('-23 hours')), 'duration' => '1h', 'error' => 'SSL certificate expired']
                ]);
                break;
                
            case 'ssl_expiry':
                jsonOk([
                    ['name' => 'Test Site 1', 'ssl_expiry_days' => 90],
                    ['name' => 'Test Site 2', 'ssl_expiry_days' => 45],
                    ['name' => 'Test Site 3', 'ssl_expiry_days' => 120],
                    ['name' => 'Test Site 4', 'ssl_expiry_days' => 15],
                    ['name' => 'Test Site 5', 'ssl_expiry_days' => 60]
                ]);
                break;
                
            case 'slowest':
                jsonOk([
                    ['name' => 'Test Site 1', 'avg_rt' => 1234],
                    ['name' => 'Test Site 2', 'avg_rt' => 987],
                    ['name' => 'Test Site 3', 'avg_rt' => 876],
                    ['name' => 'Test Site 4', 'avg_rt' => 765],
                    ['name' => 'Test Site 5', 'avg_rt' => 654]
                ]);
                break;
                
            case 'system_uptime':
                $mockData = [];
                for ($i = 29; $i >= 0; $i--) {
                    $date = new DateTime();
                    $date->modify("-$i days");
                    $mockData[] = [
                        'period' => $date->format('Y-m-d H:i:s'),
                        'uptime_percentage' => rand(90, 100)
                    ];
                }
                jsonOk($mockData);
                break;
                
            case 'response_trend_flexible':
                $ids = $_GET['ids'] ?? '1,2,3';
                $idArray = explode(',', $ids);
                $mockData = [];
                $timeLabels = ['00:00', '04:00', '08:00', '12:00', '16:00', '20:00', '23:59'];
                
                foreach ($idArray as $id) {
                    $mockData[$id] = [];
                    foreach ($timeLabels as $label) {
                        $mockData[$id][] = [
                            'period' => date('Y-m-d') . ' ' . $label . ':00',
                            'avg_rt' => rand(100, 500)
                        ];
                    }
                }
                jsonOk($mockData);
                break;
                
            case 'histogram':
                jsonOk([
                    '0-100' => 45,
                    '100-200' => 30,
                    '200-300' => 15,
                    '300-400' => 8,
                    '400-500' => 2
                ]);
                break;
                
            default:
                jsonError('Unknown action or database not available', 400);
        }
        exit;
    }

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

        // Flexible response time trend with custom time range
        case 'response_trend_flexible':
            $siteIds = array_map('intval', explode(',', $_GET['ids'] ?? ''));
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;
            $granularity = $_GET['granularity'] ?? 'hour';
            
            $data = [];
            foreach ($siteIds as $id) {
                if ($id > 0) {
                    $data[$id] = Statistics::getResponseTimeTrendFlexible($id, $startDate, $endDate, $granularity);
                }
            }
            jsonOk($data);
            break;

        // Flexible system uptime trend
        case 'system_uptime_flexible':
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;
            $granularity = $_GET['granularity'] ?? 'day';
            jsonOk(Statistics::getSystemUptimeTrendFlexible($startDate, $endDate, $granularity));
            break;

        // Flexible uptime for specific site
        case 'uptime_flexible':
            $id = (int) ($_GET['id'] ?? 0);
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;
            if (!$id) jsonError('Missing site id', 400);
            jsonOk(['uptime' => Statistics::getUptimeFlexible($id, $startDate, $endDate)]);
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
            if (!checkSessionRateLimit('test_connection', 30)) {
                jsonError('Rate limit exceeded for connection tests (30 per minute)', 429);
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $result = Checker::check($data);
            jsonOk($result);
            break;

        // Immediate check for an existing site
        case 'check_site':
            if ($method !== 'POST') jsonError('POST required', 405);
            if (!checkSessionRateLimit('check_site', 20)) {
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
            
        // Enhanced monitoring endpoints
        case 'detailed_site_status':
            $siteId = (int) ($_GET['site_id'] ?? 0);
            if ($siteId <= 0 || $siteId > 999999) jsonError('Invalid site ID');
            
            $site = Database::fetchOne('SELECT * FROM sites WHERE id = ?', [$siteId]);
            if (!$site) jsonError('Site not found');
            
            // Get latest enhanced log entry if table exists
            $latestLog = null;
            try {
                $latestLog = Database::fetchOne(
                    'SELECT * FROM logs_enhanced WHERE site_id = ? ORDER BY created_at DESC LIMIT 1',
                    [$siteId]
                );
            } catch (Exception $e) {
                // Fallback to regular logs table
                $latestLog = Database::fetchOne(
                    'SELECT * FROM logs WHERE site_id = ? ORDER BY created_at DESC LIMIT 1',
                    [$siteId]
                );
            }
            
            // Get performance metrics for last 24 hours if table exists
            $performance = [];
            try {
                $performance = Database::fetchAll(
                    'SELECT metric_type, AVG(metric_value) as avg_value, 
                            MIN(metric_value) as min_value, MAX(metric_value) as max_value
                     FROM performance_metrics 
                     WHERE site_id = ? AND hour_bucket >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                     GROUP BY metric_type',
                    [$siteId]
                );
            } catch (Exception $e) {
                // Performance metrics table doesn't exist yet
            }
            
            // Get SSL certificate details if table exists
            $sslDetails = [];
            try {
                $sslDetails = Database::fetchAll(
                    'SELECT * FROM ssl_certificates WHERE site_id = ? ORDER BY chain_position',
                    [$siteId]
                );
            } catch (Exception $e) {
                // SSL certificates table doesn't exist yet
            }
            
            // Get error statistics if table exists
            $errorStats = [];
            try {
                $errorStats = Database::fetchAll(
                    'SELECT error_category, COUNT(*) as count, 
                            MIN(first_seen) as first_seen, MAX(last_seen) as last_seen
                     FROM error_categories 
                     WHERE site_id = ? AND last_seen >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     GROUP BY error_category
                     ORDER BY count DESC',
                    [$siteId]
                );
            } catch (Exception $e) {
                // Error categories table doesn't exist yet
            }
            
            jsonOk([
                'site' => $site,
                'latest_log' => $latestLog,
                'performance_metrics' => $performance,
                'ssl_certificates' => $sslDetails,
                'error_statistics' => $errorStats
            ]);
            break;
            
        case 'performance_trends':
            $siteId = (int) ($_GET['site_id'] ?? 0);
            $hours = (int) ($_GET['hours'] ?? 24);
            
            if ($siteId <= 0 || $siteId > 999999) jsonError('Invalid site ID');
            if ($hours < 1 || $hours > 168) jsonError('Hours must be between 1 and 168');
            
            try {
                $trends = Database::fetchAll(
                    'SELECT hour_bucket, metric_type, metric_value
                     FROM performance_metrics 
                     WHERE site_id = ? AND hour_bucket >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                     ORDER BY hour_bucket ASC',
                    [$siteId, $hours]
                );
                jsonOk($trends);
            } catch (Exception $e) {
                // Return empty array if table doesn't exist
                jsonOk([]);
            }
            break;
            
        case 'ssl_analysis':
            $siteId = (int) ($_GET['site_id'] ?? 0);
            if ($siteId <= 0 || $siteId > 999999) jsonError('Invalid site ID');
            
            try {
                $sslData = Database::fetchAll(
                    'SELECT * FROM ssl_certificates WHERE site_id = ? ORDER BY chain_position',
                    [$siteId]
                );
                jsonOk($sslData);
            } catch (Exception $e) {
                // Return empty array if table doesn't exist
                jsonOk([]);
            }
            break;
            
        case 'error_categories':
            $siteId = (int) ($_GET['site_id'] ?? 0);
            $days = (int) ($_GET['days'] ?? 7);
            
            if ($siteId < 0 || $siteId > 999999) jsonError('Invalid site ID');
            if ($days < 1 || $days > 365) jsonError('Days must be between 1 and 365');
            
            try {
                if ($siteId > 0) {
                    $errors = Database::fetchAll(
                        'SELECT error_category, COUNT(*) as count,
                                MIN(first_seen) as first_seen, MAX(last_seen) as last_seen
                         FROM error_categories 
                         WHERE site_id = ? AND last_seen >= DATE_SUB(NOW(), INTERVAL ? DAY)
                         GROUP BY error_category
                         ORDER BY count DESC',
                        [$siteId, $days]
                    );
                } else {
                    $errors = Database::fetchAll(
                        'SELECT ec.error_category, COUNT(*) as count,
                                MIN(ec.first_seen) as first_seen, MAX(ec.last_seen) as last_seen,
                                GROUP_CONCAT(DISTINCT s.name) as affected_sites
                         FROM error_categories ec
                         JOIN sites s ON ec.site_id = s.id
                         WHERE ec.last_seen >= DATE_SUB(NOW(), INTERVAL ? DAY)
                         GROUP BY ec.error_category
                         ORDER BY count DESC',
                        [$days]
                    );
                }
                jsonOk($errors);
            } catch (Exception $e) {
                // Return empty array if table doesn't exist
                jsonOk([]);
            }
            break;
            
        case 'monitoring_config':
            jsonOk([
                'detailed_monitoring_enabled' => ENABLE_DETAILED_MONITORING,
                'content_analysis_enabled' => ENABLE_CONTENT_ANALYSIS,
                'ssl_chain_analysis_enabled' => ENABLE_SSL_CHAIN_ANALYSIS,
                'performance_metrics_enabled' => ENABLE_PERFORMANCE_METRICS,
                'retry_failed_checks' => RETRY_FAILED_CHECKS,
                'max_retries' => MAX_RETRIES,
                'retry_delay' => RETRY_DELAY,
                'http_timeout' => HTTP_TIMEOUT,
                'ssl_timeout' => SSL_TIMEOUT,
                'port_timeout' => PORT_TIMEOUT,
                'dns_timeout' => DNS_TIMEOUT,
                'ping_timeout' => PING_TIMEOUT,
                'max_redirects' => MAX_REDIRECTS,
            ]);
            break;
            
        // Run cron manually
        case 'run_cron':
            if ($method !== 'POST') jsonError('POST required', 405);
            if (!checkSessionRateLimit('run_cron', 5)) {
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
            if (!checkSessionRateLimit('test_email', 10)) {
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
                'name' => 'Test Monitor - ' . htmlspecialchars(ucfirst(str_replace('_', ' ', $alertType))),
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
