<?php
// =============================================================================
// config.php - Central configuration for the monitoring system
// =============================================================================

// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'euclideesolution_moniter');
define('DB_USER', 'euclideesolution_monitor');
define('DB_PASS', 'V@x8g!VschC&4%sh');
define('DB_CHARSET', 'utf8mb4');

// SMTP / Email alerts
define('SMTP_HOST', 'mail.euclideesolutions.com');
define('SMTP_PORT', 465);
define('SMTP_USER', 'no-reply@euclideesolutions.com');
define('SMTP_PASS', '');
define('FROM_EMAIL', 'no-reply@euclideesolutions.com');
define('FROM_NAME', 'Site Monitor');

// Monitoring behaviour
define('ALERT_COOLDOWN', 3600);   // seconds between repeat alerts per site
define('CHECK_TIMEOUT', 10);       // seconds before a check times out
define('LOG_RETENTION_DAYS', 90);  // days to keep raw logs

// Dashboard basic-auth (set to false to disable)
define('DASHBOARD_AUTH', false);
define('DASHBOARD_USER', 'admin');
define('DASHBOARD_PASS', 'changeme');

// App base URL (no trailing slash)
define('APP_URL', 'https://monitoring.euclideesolutions.com');

// Timezone
date_default_timezone_set('UTC');
