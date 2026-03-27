<?php
define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';

session_start();

if (DASHBOARD_AUTH) {
    if (!isset($_SERVER['PHP_AUTH_USER']) ||
        $_SERVER['PHP_AUTH_USER'] !== DASHBOARD_USER ||
        !hash_equals(DASHBOARD_PASS, $_SERVER['PHP_AUTH_PW'] ?? '')) {
        header('WWW-Authenticate: Basic realm="Monitor"');
        http_response_code(401); exit('Unauthorized');
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// System info
$phpVersion  = PHP_VERSION;
$dbVersion   = Database::fetchOne('SELECT VERSION() AS v')['v'] ?? 'Unknown';
$logCount    = Database::fetchOne('SELECT COUNT(*) AS c FROM logs')['c'] ?? 0;
$siteCount   = Database::fetchOne('SELECT COUNT(*) AS c FROM sites')['c'] ?? 0;
$oldestLog   = Database::fetchOne('SELECT MIN(created_at) AS d FROM logs')['d'] ?? 'N/A';
$diskFree    = function_exists('disk_free_space') ? round(disk_free_space('/') / 1073741824, 1) . ' GB' : 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <title>Settings — Site Monitor</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
<div class="layout">

  <aside class="sidebar">
    <div class="sidebar-logo">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
      </svg>
      <span>Monitor</span>
    </div>
    <nav>
      <a class="nav-item" href="index.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        <span>Dashboard</span>
      </a>
      <a class="nav-item active" href="settings.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
        <span>Settings</span>
      </a>
    </nav>
  </aside>

  <main class="main">
    <div class="topbar">
      <h1>Settings</h1>
    </div>

    <!-- System Info -->
    <div class="table-panel" style="margin-bottom:24px">
      <div class="chart-title mb-4">System Information</div>
      <table>
        <tbody>
          <tr><td style="color:var(--muted);width:220px">PHP Version</td><td><?= htmlspecialchars($phpVersion) ?></td></tr>
          <tr><td style="color:var(--muted)">MySQL Version</td><td><?= htmlspecialchars($dbVersion) ?></td></tr>
          <tr><td style="color:var(--muted)">Database</td><td><?= htmlspecialchars(DB_NAME) ?></td></tr>
          <tr><td style="color:var(--muted)">Total Sites</td><td><?= (int)$siteCount ?></td></tr>
          <tr><td style="color:var(--muted)">Total Log Rows</td><td><?= number_format((int)$logCount) ?></td></tr>
          <tr><td style="color:var(--muted)">Oldest Log</td><td><?= htmlspecialchars($oldestLog) ?></td></tr>
          <tr><td style="color:var(--muted)">Log Retention</td><td><?= LOG_RETENTION_DAYS ?> days</td></tr>
          <tr><td style="color:var(--muted)">Check Timeout</td><td><?= CHECK_TIMEOUT ?> seconds</td></tr>
          <tr><td style="color:var(--muted)">Alert Cooldown</td><td><?= ALERT_COOLDOWN / 60 ?> minutes</td></tr>
          <tr><td style="color:var(--muted)">Disk Free</td><td><?= $diskFree ?></td></tr>
          <tr><td style="color:var(--muted)">App URL</td><td><?= htmlspecialchars(APP_URL) ?></td></tr>
          <tr><td style="color:var(--muted)">Timezone</td><td><?= date_default_timezone_get() ?></td></tr>
        </tbody>
      </table>
    </div>

    <!-- Extension checks -->
    <div class="table-panel" style="margin-bottom:24px">
      <div class="chart-title mb-4">PHP Extensions</div>
      <table>
        <tbody>
          <?php
          $exts = ['pdo_mysql', 'curl', 'openssl', 'mbstring', 'json'];
          foreach ($exts as $ext):
            $ok = extension_loaded($ext);
          ?>
          <tr>
            <td style="color:var(--muted);width:220px"><?= $ext ?></td>
            <td style="color:var(--<?= $ok ? 'green' : 'red' ?>)"><?= $ok ? '✓ Loaded' : '✗ Missing' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Danger zone -->
    <div class="table-panel" style="border-top:3px solid var(--red)">
      <div class="chart-title mb-4">Danger Zone</div>
      <div style="display:flex;flex-direction:column;gap:16px">

        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;background:var(--surface2);border-radius:8px">
          <div>
            <div style="font-weight:500">Purge Old Logs</div>
            <div style="color:var(--muted);font-size:12px;margin-top:2px">Delete logs older than <?= LOG_RETENTION_DAYS ?> days right now</div>
          </div>
          <button class="btn btn-danger" onclick="purgeLogs()">Purge Logs</button>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;background:var(--surface2);border-radius:8px">
          <div>
            <div style="font-weight:500">Cron Status</div>
            <div style="color:var(--muted);font-size:12px;margin-top:2px">Add this to your crontab to run checks every minute</div>
            <code style="font-size:11px;color:var(--blue);margin-top:6px;display:block">* * * * * php <?= htmlspecialchars(MONITOR_ROOT) ?>/cron_runner.php >> /var/log/monitor.log 2>&1</code>
          </div>
        </div>

      </div>
    </div>

  </main>
</div>

<div id="toast-container"></div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="assets/js/dashboard.js"></script>
<script>
async function purgeLogs() {
  if (!confirm('Delete all logs older than <?= LOG_RETENTION_DAYS ?> days? This cannot be undone.')) return;
  try {
    await apiPost('purge_logs', {});
    showToast('Old logs purged', 'success');
  } catch (err) {
    showToast('Failed: ' + err.message, 'error');
  }
}
</script>
</body>
</html>
