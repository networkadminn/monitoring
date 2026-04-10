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

// Enhanced monitoring settings
define('ENABLE_DETAILED_MONITORING', getEnvValue('ENABLE_DETAILED_MONITORING', 'true') === 'true');
define('HTTP_TIMEOUT', (int) getEnvValue('HTTP_TIMEOUT', 30));
define('SSL_TIMEOUT', (int) getEnvValue('SSL_TIMEOUT', 15));
define('PORT_TIMEOUT', (int) getEnvValue('PORT_TIMEOUT', 10));
define('DNS_TIMEOUT', (int) getEnvValue('DNS_TIMEOUT', 10));
define('PING_TIMEOUT', (int) getEnvValue('PING_TIMEOUT', 5));
define('MAX_REDIRECTS', (int) getEnvValue('MAX_REDIRECTS', 5));
define('ENABLE_CONTENT_ANALYSIS', getEnvValue('ENABLE_CONTENT_ANALYSIS', 'true') === 'true');
define('ENABLE_SSL_CHAIN_ANALYSIS', getEnvValue('ENABLE_SSL_CHAIN_ANALYSIS', 'true') === 'true');
define('ENABLE_PERFORMANCE_METRICS', getEnvValue('ENABLE_PERFORMANCE_METRICS', 'true') === 'true');
define('RETRY_FAILED_CHECKS', getEnvValue('RETRY_FAILED_CHECKS', 'true') === 'true');
define('MAX_RETRIES', (int) getEnvValue('MAX_RETRIES', 3));
define('RETRY_DELAY', (int) getEnvValue('RETRY_DELAY', 1000)); // milliseconds

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
// Timezone
// =============================================================================
$timezone = getEnvValue('TIMEZONE', 'UTC');
date_default_timezone_set(!empty($timezone) ? $timezone : 'UTC');
