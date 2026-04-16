<?php
// =============================================================================
// config.php - Central configuration for the monitoring system
// =============================================================================

// Load .env file using parse_ini_file (most reliable method)
$envPath = __DIR__ . '/.env';
$envVars = [];
if (file_exists($envPath)) {
    $envVars = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    if ($envVars === false) {
        // Fallback: manual parsing
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                $envVars[$key] = $value;
            }
        }
    }
}

// Helper function to get environment variable
function getEnvValue($key, $default = null) {
    global $envVars;
    return $envVars[$key] ?? $default;
}

// Alias for backward compatibility
if (!function_exists('getEnv')) {
    function getEnv($key, $default = null) {
        return getEnvValue($key, $default);
    }
}

// =============================================================================
// Database Configuration
// =============================================================================
define('DB_HOST', getEnvValue('DB_HOST', 'localhost'));
define('DB_NAME', getEnvValue('DB_NAME', 'site_monitor'));
define('DB_USER', getEnvValue('DB_USER', 'monitor_user'));
define('DB_PASS', getEnvValue('DB_PASS', ''));
define('DB_CHARSET', getEnvValue('DB_CHARSET', 'utf8mb4'));

// Fallback for local testing when database is not available
$envFileExists = file_exists(__DIR__ . '/.env');
$dbPasswordSet = getEnvValue('DB_PASS') !== '';
define('DB_AVAILABLE', $envFileExists && $dbPasswordSet);

// =============================================================================
// SMTP / Email Configuration
// =============================================================================
define('SMTP_HOST', getEnvValue('SMTP_HOST', 'localhost'));
define('SMTP_PORT', (int) getEnvValue('SMTP_PORT', 465));
define('SMTP_USER', getEnvValue('SMTP_USER', ''));
define('SMTP_PASS', getEnvValue('SMTP_PASS', ''));
define('FROM_EMAIL', getEnvValue('FROM_EMAIL', 'noreply@example.com'));
define('FROM_NAME', getEnvValue('FROM_NAME', 'Site Monitor'));

// =============================================================================
// Monitoring Behaviour
// =============================================================================
define('ALERT_COOLDOWN', (int) getEnvValue('ALERT_COOLDOWN', 3600));
define('CHECK_TIMEOUT', (int) getEnvValue('CHECK_TIMEOUT', 30));
define('LOG_RETENTION_DAYS', (int) getEnvValue('LOG_RETENTION_DAYS', 90));

// Enhanced monitoring settings with validation
define('ENABLE_DETAILED_MONITORING', filter_var(getEnvValue('ENABLE_DETAILED_MONITORING', 'true'), FILTER_VALIDATE_BOOLEAN));
define('HTTP_TIMEOUT', max(5, min(300, (int) getEnvValue('HTTP_TIMEOUT', 30)))); // 5-300s range
define('SSL_TIMEOUT', max(5, min(60, (int) getEnvValue('SSL_TIMEOUT', 15)))); // 5-60s range
define('PORT_TIMEOUT', max(1, min(30, (int) getEnvValue('PORT_TIMEOUT', 10)))); // 1-30s range
define('DNS_TIMEOUT', max(1, min(30, (int) getEnvValue('DNS_TIMEOUT', 10)))); // 1-30s range
define('PING_TIMEOUT', max(1, min(15, (int) getEnvValue('PING_TIMEOUT', 5)))); // 1-15s range
define('MAX_REDIRECTS', max(0, min(10, (int) getEnvValue('MAX_REDIRECTS', 5)))); // 0-10 range
define('ENABLE_CONTENT_ANALYSIS', filter_var(getEnvValue('ENABLE_CONTENT_ANALYSIS', 'true'), FILTER_VALIDATE_BOOLEAN));
define('ENABLE_SSL_CHAIN_ANALYSIS', filter_var(getEnvValue('ENABLE_SSL_CHAIN_ANALYSIS', 'true'), FILTER_VALIDATE_BOOLEAN));
define('ENABLE_PERFORMANCE_METRICS', filter_var(getEnvValue('ENABLE_PERFORMANCE_METRICS', 'true'), FILTER_VALIDATE_BOOLEAN));
define('RETRY_FAILED_CHECKS', filter_var(getEnvValue('RETRY_FAILED_CHECKS', 'true'), FILTER_VALIDATE_BOOLEAN));
define('MAX_RETRIES', max(0, min(10, (int) getEnvValue('MAX_RETRIES', 3)))); // 0-10 range
define('RETRY_DELAY', max(100, min(10000, (int) getEnvValue('RETRY_DELAY', 1000)))); // 100ms-10s range

// =============================================================================
// Dashboard Authentication
// =============================================================================
define('DASHBOARD_AUTH', getEnvValue('DASHBOARD_AUTH', 'true') === 'true');
define('DASHBOARD_USER', getEnvValue('DASHBOARD_USER', 'admin'));
define('DASHBOARD_PASS', getEnvValue('DASHBOARD_PASS', ''));

// =============================================================================
// Application Settings
// =============================================================================
define('APP_URL', getEnvValue('APP_URL', 'http://localhost'));
define('APP_ENV', getEnvValue('APP_ENV', 'production'));

// =============================================================================
// Optional Alert Integrations
// =============================================================================
define('ENABLE_SMS_ALERTS', getEnvValue('ENABLE_SMS_ALERTS', 'false') === 'true');
define('SMS_API_ENDPOINT', getEnvValue('SMS_API_ENDPOINT', ''));
define('SMS_API_KEY', getEnvValue('SMS_API_KEY', ''));

define('ENABLE_TELEGRAM_ALERTS', getEnvValue('ENABLE_TELEGRAM_ALERTS', 'false') === 'true');
define('TELEGRAM_BOT_TOKEN', getEnvValue('TELEGRAM_BOT_TOKEN', ''));
define('TELEGRAM_CHAT_ID', getEnvValue('TELEGRAM_CHAT_ID', ''));

define('ENABLE_TEAMS_ALERTS', getEnvValue('ENABLE_TEAMS_ALERTS', 'true') === 'true');
define('TEAMS_WEBHOOK_URL', getEnvValue('TEAMS_WEBHOOK_URL', ''));

// =============================================================================
// SSL Certificate Monitoring
// =============================================================================
define('SSL_EXPIRY_WARNING_DAYS', (int) getEnvValue('SSL_EXPIRY_WARNING_DAYS', 30));

// =============================================================================
// Advanced Security Settings
// =============================================================================
define('ENABLE_SECURITY_MANAGER', filter_var(getEnvValue('ENABLE_SECURITY_MANAGER', 'true'), FILTER_VALIDATE_BOOLEAN));
define('ENABLE_IP_BLOCKING', filter_var(getEnvValue('ENABLE_IP_BLOCKING', 'true'), FILTER_VALIDATE_BOOLEAN));
define('MAX_LOGIN_ATTEMPTS', max(3, min(20, (int) getEnvValue('MAX_LOGIN_ATTEMPTS', 5))));
define('LOGIN_LOCKOUT_DURATION', max(300, min(7200, (int) getEnvValue('LOGIN_LOCKOUT_DURATION', 900)))); // 5min-2hr
define('ENABLE_SESSION_FINGERPRINTING', filter_var(getEnvValue('ENABLE_SESSION_FINGERPRINTING', 'true'), FILTER_VALIDATE_BOOLEAN));
define('ENABLE_ADVANCED_RATE_LIMITING', filter_var(getEnvValue('ENABLE_ADVANCED_RATE_LIMITING', 'true'), FILTER_VALIDATE_BOOLEAN));
define('SECURITY_LOG_LEVEL', getEnvValue('SECURITY_LOG_LEVEL', 'WARNING')); // DEBUG, INFO, WARNING, ERROR

// =============================================================================
// Performance and Scaling Settings
// =============================================================================
define('ENABLE_ASYNC_CHECKS', filter_var(getEnvValue('ENABLE_ASYNC_CHECKS', 'false'), FILTER_VALIDATE_BOOLEAN));
define('MAX_CONCURRENT_CHECKS', max(1, min(50, (int) getEnvValue('MAX_CONCURRENT_CHECKS', 10))));
define('ENABLE_QUERY_OPTIMIZATION', filter_var(getEnvValue('ENABLE_QUERY_OPTIMIZATION', 'true'), FILTER_VALIDATE_BOOLEAN));
define('ENABLE_RESULT_COMPRESSION', filter_var(getEnvValue('ENABLE_RESULT_COMPRESSION', 'true'), FILTER_VALIDATE_BOOLEAN));
define('CACHE_STRATEGY', getEnvValue('CACHE_STRATEGY', 'file')); // file, redis, memcached

// =============================================================================
// Monitoring and Observability
// =============================================================================
define('ENABLE_METRICS_COLLECTION', filter_var(getEnvValue('ENABLE_METRICS_COLLECTION', 'true'), FILTER_VALIDATE_BOOLEAN));
define('METRICS_RETENTION_DAYS', max(7, min(90, (int) getEnvValue('METRICS_RETENTION_DAYS', 30))));
define('ENABLE_PERFORMANCE_PROFILING', filter_var(getEnvValue('ENABLE_PERFORMANCE_PROFILING', 'false'), FILTER_VALIDATE_BOOLEAN));
define('ENABLE_HEALTH_CHECK_ENDPOINT', filter_var(getEnvValue('ENABLE_HEALTH_CHECK_ENDPOINT', 'true'), FILTER_VALIDATE_BOOLEAN));

// =============================================================================
// Advanced Alert Configuration
// =============================================================================
define('ALERT_ESCALATION_ENABLED', filter_var(getEnvValue('ALERT_ESCALATION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN));
define('ALERT_ESCALATION_LEVELS', getEnvValue('ALERT_ESCALATION_LEVELS', 'warning,critical'));
define('ENABLE_ALERT_DEDUPING', filter_var(getEnvValue('ENABLE_ALERT_DEDUPING', 'true'), FILTER_VALIDATE_BOOLEAN));
define('ALERT_DEDUP_WINDOW', max(60, min(3600, (int) getEnvValue('ALERT_DEDUP_WINDOW', 300)))); // 1min-1hr

// =============================================================================
// Timezone
// =============================================================================
$timezone = getEnvValue('TIMEZONE', 'UTC');
date_default_timezone_set(!empty($timezone) ? $timezone : 'UTC');

// =============================================================================
// Runtime Configuration Validation
// =============================================================================
function validateConfiguration(): array {
    $errors = [];
    $warnings = [];
    
    // Validate critical paths
    if (!is_writable(sys_get_temp_dir())) {
        $errors[] = 'Temp directory is not writable';
    }
    
    if (defined('DB_AVAILABLE') && !DB_AVAILABLE) {
        $warnings[] = 'Database not available - running in demo mode';
    }
    
    // Validate security settings
    if (ENABLE_SECURITY_MANAGER && !class_exists('SecurityManager')) {
        $warnings[] = 'Security Manager enabled but class not found';
    }
    
    // Validate performance settings
    if (ENABLE_ASYNC_CHECKS && !function_exists('pcntl_fork')) {
        $warnings[] = 'Async checks enabled but pcntl extension not available';
    }
    
    // Validate cache strategy
    $validCacheStrategies = ['file', 'redis', 'memcached'];
    if (!in_array(CACHE_STRATEGY, $validCacheStrategies)) {
        $errors[] = "Invalid cache strategy: " . CACHE_STRATEGY;
    }
    
    // Validate timeouts
    if (HTTP_TIMEOUT > 300) {
        $warnings[] = 'HTTP timeout is very high (>5min)';
    }
    
    if (CHECK_TIMEOUT > 300) {
        $warnings[] = 'Check timeout is very high (>5min)';
    }
    
    return ['errors' => $errors, 'warnings' => $warnings];
}

// Run configuration validation
$configValidation = validateConfiguration();
if (!empty($configValidation['errors'])) {
    error_log('Configuration Errors: ' . implode(', ', $configValidation['errors']));
}
if (!empty($configValidation['warnings'])) {
    error_log('Configuration Warnings: ' . implode(', ', $configValidation['warnings']));
}
