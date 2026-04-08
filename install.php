<?php
// =============================================================================
// install.php - Database installer with schema + sample data
// =============================================================================

define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';

$errors   = [];
$messages = [];
$done     = false;

$is_cli = (php_sapi_name() === 'cli');

if ($is_cli || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install']))) {
    try {
        // Connect without selecting a DB first so we can CREATE it
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . DB_NAME . '`');
        $messages[] = 'Database created / verified.';

        // ── Schema ────────────────────────────────────────────────────────────
        $schema = <<<SQL

CREATE TABLE IF NOT EXISTS sites (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(120)  NOT NULL,
    url              VARCHAR(500)  NOT NULL,
    check_type       ENUM('http','ssl','port','dns','keyword') NOT NULL DEFAULT 'http',
    port             SMALLINT UNSIGNED NULL,
    hostname         VARCHAR(255)  NULL,
    keyword          VARCHAR(500)  NULL,
    expected_status  SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    alert_email      VARCHAR(255)  NULL,
    alert_phone      VARCHAR(30)   NULL,
    alert_telegram   VARCHAR(60)   NULL,
    alert_teams      VARCHAR(500)  NULL,
    is_active        TINYINT(1)    NOT NULL DEFAULT 1,
    tags             VARCHAR(255)  NULL,
    uptime_percentage DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    failure_threshold INT UNSIGNED NOT NULL DEFAULT 3,
    consecutive_recoveries INT UNSIGNED NOT NULL DEFAULT 0,
    recovery_threshold INT UNSIGNED NOT NULL DEFAULT 3,
    last_down_alert_time TIMESTAMP NULL,
    last_recovery_alert_time TIMESTAMP NULL,
    created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS logs (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id          INT UNSIGNED    NOT NULL,
    status           ENUM('up','down','warning') NOT NULL,
    response_time    DECIMAL(10,2)   NOT NULL DEFAULT 0,
    error_message    TEXT            NULL,
    ssl_expiry_days  SMALLINT        NULL,
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_site_created (site_id, created_at),
    INDEX idx_site_created_desc (site_id, created_at DESC),
    INDEX idx_created (created_at),
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alert_log (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id          INT UNSIGNED    NOT NULL,
    alert_type       VARCHAR(30)     NOT NULL,
    sent_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_site_type (site_id, alert_type),
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daily_uptime (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id          INT UNSIGNED    NOT NULL,
    date             DATE            NOT NULL,
    uptime_percentage DECIMAL(5,2)   NOT NULL DEFAULT 100.00,
    total_checks     INT UNSIGNED    NOT NULL DEFAULT 0,
    failed_checks    INT UNSIGNED    NOT NULL DEFAULT 0,
    avg_response_time DECIMAL(10,2)  NOT NULL DEFAULT 0,
    UNIQUE KEY uq_site_date (site_id, date),
    INDEX idx_date (date),
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hourly_stats (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id          INT UNSIGNED    NOT NULL,
    hour             DATETIME        NOT NULL,
    avg_response_time DECIMAL(10,2)  NOT NULL DEFAULT 0,
    min_response_time DECIMAL(10,2)  NOT NULL DEFAULT 0,
    max_response_time DECIMAL(10,2)  NOT NULL DEFAULT 0,
    UNIQUE KEY uq_site_hour (site_id, hour),
    INDEX idx_hour (hour),
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS incidents (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id          INT UNSIGNED    NOT NULL,
    started_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at         TIMESTAMP       NULL,
    duration_seconds INT UNSIGNED    NULL,
    error_message    TEXT            NULL,
    INDEX idx_site_started (site_id, started_at),
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SQL;

        foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
            $pdo->exec($stmt);
        }
        $messages[] = 'All tables created.';

        // ── Migrations ────────────────────────────────────────────────────────
        // Add tags column if it doesn't exist
        $cols = $pdo->query('SHOW COLUMNS FROM sites')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tags', $cols)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN tags VARCHAR(255) NULL AFTER is_active');
            $messages[] = 'Migration: Added "tags" column to sites table.';
        }

        // Rename incident_log to incidents if it exists
        try {
            $pdo->query('SELECT 1 FROM incident_log LIMIT 1');
            $pdo->exec('RENAME TABLE incident_log TO incidents');
            $messages[] = 'Migration: Renamed incident_log table to incidents.';
        } catch (Exception $e) {
            // Table doesn't exist, ignore
        }

        // Add missing indexes for performance (Phase 1 optimization)
        try {
            $indexes = $pdo->query(
                "SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'logs'"
            )->fetchAll(PDO::FETCH_COLUMN);
            
            if (!in_array('idx_site_created_desc', $indexes)) {
                $pdo->exec('ALTER TABLE logs ADD INDEX idx_site_created_desc (site_id, created_at DESC)');
                $messages[] = 'Migration: Added performance index idx_site_created_desc to logs table.';
            }
        } catch (Exception $e) {
            error_log('Index migration skipped: ' . $e->getMessage());
        }

        // Add failure threshold columns for smarter alerting (Phase 2.5)
        $siteCols = $pdo->query('SHOW COLUMNS FROM sites')->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('failure_threshold', $siteCols)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0 AFTER uptime_percentage');
            $pdo->exec('ALTER TABLE sites ADD COLUMN failure_threshold INT UNSIGNED NOT NULL DEFAULT 3 AFTER consecutive_failures');
            $pdo->exec('ALTER TABLE sites ADD COLUMN consecutive_recoveries INT UNSIGNED NOT NULL DEFAULT 0 AFTER failure_threshold');
            $pdo->exec('ALTER TABLE sites ADD COLUMN recovery_threshold INT UNSIGNED NOT NULL DEFAULT 3 AFTER consecutive_recoveries');
            $pdo->exec('ALTER TABLE sites ADD COLUMN last_down_alert_time TIMESTAMP NULL AFTER recovery_threshold');
            $pdo->exec('ALTER TABLE sites ADD COLUMN last_recovery_alert_time TIMESTAMP NULL AFTER last_down_alert_time');
            $messages[] = 'Migration: Added smart threshold columns for failure detection.';
        }

        // Add Microsoft Teams webhook support (Phase 3)
        $siteCols = $pdo->query('SHOW COLUMNS FROM sites')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('alert_teams', $siteCols)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN alert_teams VARCHAR(500) NULL AFTER alert_telegram');
            $messages[] = 'Migration: Added Microsoft Teams webhook support.';
        }

        // ── New feature migrations ─────────────────────────────────────────────
        $siteCols = $pdo->query('SHOW COLUMNS FROM sites')->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('alert_slack', $siteCols)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN alert_slack VARCHAR(500) NULL AFTER alert_teams');
            $messages[] = 'Migration: Added Slack webhook column.';
        }
        if (!in_array('alert_discord', $siteCols)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN alert_discord VARCHAR(500) NULL AFTER alert_slack');
            $messages[] = 'Migration: Added Discord webhook column.';
        }
        if (!in_array('alert_webhook', $siteCols)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN alert_webhook VARCHAR(500) NULL AFTER alert_discord');
            $messages[] = 'Migration: Added generic webhook column.';
        }
        if (!in_array('alert_pagerduty', $siteCols)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN alert_pagerduty VARCHAR(255) NULL AFTER alert_webhook');
            $messages[] = 'Migration: Added PagerDuty integration key column.';
        }
        if (!in_array('check_interval', $siteCols)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN check_interval TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER recovery_threshold');
            $messages[] = 'Migration: Added per-site check_interval column.';
        }

        // Maintenance windows table
        $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_windows (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id     INT UNSIGNED NOT NULL,
            title       VARCHAR(200) NOT NULL,
            description TEXT NULL,
            start_time  DATETIME NOT NULL,
            end_time    DATETIME NOT NULL,
            is_active   TINYINT(1) NOT NULL DEFAULT 1,
            created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_site (site_id),
            INDEX idx_times (start_time, end_time),
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $messages[] = 'Migration: maintenance_windows table ready.';

        // Status page config table
        $pdo->exec("CREATE TABLE IF NOT EXISTS status_page_config (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title        VARCHAR(120) NOT NULL DEFAULT 'Service Status',
            description  VARCHAR(300) NULL,
            logo_url     VARCHAR(500) NULL,
            is_public    TINYINT(1) NOT NULL DEFAULT 1,
            show_values  TINYINT(1) NOT NULL DEFAULT 1,
            accent_color VARCHAR(10) NOT NULL DEFAULT '#3b82f6',
            footer_text  VARCHAR(300) NULL,
            updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $messages[] = 'Migration: status_page_config table ready.';

        // Status page subscribers
        $pdo->exec("CREATE TABLE IF NOT EXISTS status_page_subscribers (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email      VARCHAR(255) NOT NULL,
            token      VARCHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_email (email),
            UNIQUE KEY uq_token (token)
        ) ENGINE=InnoDB");
        $messages[] = 'Migration: status_page_subscribers table ready.';

        // API keys table
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name         VARCHAR(80) NOT NULL,
            key_hash     VARCHAR(64) NOT NULL,
            key_prefix   VARCHAR(12) NOT NULL,
            last_used_at TIMESTAMP NULL,
            created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hash (key_hash)
        ) ENGINE=InnoDB");
        $messages[] = 'Migration: api_keys table ready.';

        // ── Sample data ───────────────────────────────────────────────────────
        if (isset($_POST['sample_data'])) {
            $samples = [
                ['Google',       'https://www.google.com',       'http',    null, null,          null,      200, 'ops@example.com', null, null],
                ['GitHub',       'https://github.com',           'http',    null, null,          null,      200, 'ops@example.com', null, null],
                ['Google SSL',   'https://www.google.com',       'ssl',     443,  'www.google.com', null,   200, 'ops@example.com', null, null],
                ['DNS Check',    'https://cloudflare.com',       'dns',     null, 'cloudflare.com', null,   200, 'ops@example.com', null, null],
                ['Keyword Test', 'https://example.com',          'keyword', null, null,          'Example', 200, 'ops@example.com', null, null],
            ];

            $ins = $pdo->prepare(
                'INSERT IGNORE INTO sites (name, url, check_type, port, hostname, keyword, expected_status, alert_email, alert_phone, alert_telegram)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($samples as $s) $ins->execute($s);

            // Seed some log data for charts
            $siteRows = $pdo->query('SELECT id FROM sites LIMIT 5')->fetchAll(PDO::FETCH_COLUMN);
            $logIns   = $pdo->prepare(
                'INSERT INTO logs (site_id, status, response_time, created_at) VALUES (?,?,?,?)'
            );
            foreach ($siteRows as $sid) {
                for ($i = 0; $i < 200; $i++) {
                    $ts  = date('Y-m-d H:i:s', strtotime("-$i minutes"));
                    $rt  = rand(50, 800);
                    $st  = $rt > 700 ? 'down' : 'up';
                    $logIns->execute([$sid, $st, $rt, $ts]);
                }
            }
            $messages[] = 'Sample data inserted.';
        }

        $done = true;

        if ($is_cli) {
            echo implode("\n", $messages) . "\n";
            echo "✅ Installation complete!\n";
            exit(0);
        }

    } catch (PDOException $e) {
        if ($is_cli) {
            echo "❌ Database error: " . $e->getMessage() . "\n";
            exit(1);
        }
        $errors[] = 'Database error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Install — Site Monitor</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/dashboard.css">
  <style>
    body { display:flex; align-items:center; justify-content:center; min-height:100vh; }
    .install-box { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:40px; width:480px; max-width:95vw; }
    .install-box h1 { font-size:22px; margin-bottom:8px; }
    .install-box p  { color:var(--muted); margin-bottom:24px; }
    .msg { padding:10px 14px; border-radius:6px; margin-bottom:10px; font-size:13px; }
    .msg.ok  { background:rgba(34,197,94,.15); color:#22c55e; }
    .msg.err { background:rgba(239,68,68,.15);  color:#ef4444; }
    .check-row { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
  </style>
</head>
<body>
<div class="install-box">
  <h1>🚀 Site Monitor Installer</h1>
  <p>This will create the database schema and optionally insert sample data.</p>

  <?php foreach ($messages as $m): ?>
    <div class="msg ok">✓ <?= htmlspecialchars($m) ?></div>
  <?php endforeach; ?>
  <?php foreach ($errors as $e): ?>
    <div class="msg err">✗ <?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <?php if ($done): ?>
    <div class="msg ok">✓ Installation complete!</div>
    <a href="index.php" class="btn btn-primary" style="display:block;text-align:center;margin-top:16px">Go to Dashboard →</a>
    <p style="margin-top:16px;font-size:12px;color:var(--muted)">
      ⚠️ Delete or protect install.php after setup.
    </p>
  <?php else: ?>
    <!-- Pre-flight checks -->
    <div style="margin-bottom:20px">
      <?php
      $checks = [
          'PHP >= 8.0'       => version_compare(PHP_VERSION, '8.0', '>='),
          'PDO MySQL'        => extension_loaded('pdo_mysql'),
          'cURL'             => extension_loaded('curl'),
          'OpenSSL'          => extension_loaded('openssl'),
          'config.php exists'=> file_exists(MONITOR_ROOT . '/config.php'),
      ];
      foreach ($checks as $label => $ok): ?>
        <div class="check-row">
          <span style="color:<?= $ok ? 'var(--green)' : 'var(--red)' ?>"><?= $ok ? '✓' : '✗' ?></span>
          <span><?= htmlspecialchars($label) ?></span>
          <?php if (!$ok): ?><span class="text-red" style="font-size:12px">Required</span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <form method="POST">
      <div class="form-group">
        <label>Database Host</label>
        <input class="form-control" value="<?= htmlspecialchars(DB_HOST) ?>" disabled>
      </div>
      <div class="form-group">
        <label>Database Name</label>
        <input class="form-control" value="<?= htmlspecialchars(DB_NAME) ?>" disabled>
      </div>
      <div class="check-row" style="margin-bottom:20px">
        <input type="checkbox" name="sample_data" id="sample_data" checked style="width:16px;height:16px">
        <label for="sample_data">Insert sample sites and log data</label>
      </div>
      <button type="submit" name="install" value="1" class="btn btn-primary" style="width:100%">
        Run Installation
      </button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
