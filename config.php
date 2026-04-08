<?php
// =============================================================================
// config.php - Central configuration for the monitoring system
// Loads settings from .env file with safe defaults
// =============================================================================

// Helper function to load environment variable with fallback
function getEnv(string $key, $default = null) {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?? $default;
    return $value === false ? $default : $value;
}

// Load .env file if it exists
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, '\'"');
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $value;
        }
    }
}

// =============================================================================
// Database Configuration
// =============================================================================
define('DB_HOST', getEnv('DB_HOST', 'localhost'));
define('DB_NAME', getEnv('DB_NAME', 'site_monitor'));
define('DB_USER', getEnv('DB_USER', 'monitor_user'));
define('DB_PASS', getEnv('DB_PASS', ''));
define('DB_CHARSET', getEnv('DB_CHARSET', 'utf8mb4'));

// =============================================================================
// SMTP / Email Configuration
// =============================================================================
define('SMTP_HOST', getEnv('SMTP_HOST', 'localhost'));
define('SMTP_PORT', (int) getEnv('SMTP_PORT', 465));
define('SMTP_USER', getEnv('SMTP_USER', ''));
define('SMTP_PASS', getEnv('SMTP_PASS', ''));
define('FROM_EMAIL', getEnv('FROM_EMAIL', 'noreply@example.com'));
define('FROM_NAME', getEnv('FROM_NAME', 'Site Monitor'));

// =============================================================================
// Monitoring Behaviour
// =============================================================================
define('ALERT_COOLDOWN', (int) getEnv('ALERT_COOLDOWN', 3600));   // seconds between repeat alerts per site
define('CHECK_TIMEOUT', (int) getEnv('CHECK_TIMEOUT', 30));       // seconds before a check times out
define('LOG_RETENTION_DAYS', (int) getEnv('LOG_RETENTION_DAYS', 90));  // days to keep raw logs

// =============================================================================
// Dashboard Authentication
// To generate a new hash: php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"
// =============================================================================
define('DASHBOARD_AUTH', getEnv('DASHBOARD_AUTH', 'true') === 'true');
define('DASHBOARD_USER', getEnv('DASHBOARD_USER', 'admin'));
define('DASHBOARD_PASS', getEnv('DASHBOARD_PASS', ''));

// =============================================================================
// Application Settings
// =============================================================================
define('APP_URL', getEnv('APP_URL', 'http://localhost'));
define('APP_ENV', getEnv('APP_ENV', 'production'));

// =============================================================================
// Optional Alert Integrations
// =============================================================================
define('ENABLE_SMS_ALERTS', getEnv('ENABLE_SMS_ALERTS', 'false') === 'true');
define('SMS_API_ENDPOINT', getEnv('SMS_API_ENDPOINT', ''));
define('SMS_API_KEY', getEnv('SMS_API_KEY', ''));

define('ENABLE_TELEGRAM_ALERTS', getEnv('ENABLE_TELEGRAM_ALERTS', 'false') === 'true');
define('TELEGRAM_BOT_TOKEN', getEnv('TELEGRAM_BOT_TOKEN', ''));
define('TELEGRAM_CHAT_ID', getEnv('TELEGRAM_CHAT_ID', ''));

define('ENABLE_TEAMS_ALERTS', getEnv('ENABLE_TEAMS_ALERTS', 'true') === 'true');
define('TEAMS_WEBHOOK_URL', getEnv('TEAMS_WEBHOOK_URL', ''));

// =============================================================================
// SSL Certificate Monitoring
// =============================================================================
define('SSL_EXPIRY_WARNING_DAYS', (int) getEnv('SSL_EXPIRY_WARNING_DAYS', 30));  // Alert when cert expires in N days

// =============================================================================
// Timezone
// =============================================================================
date_default_timezone_set(getEnv('TIMEZONE', 'UTC'));
