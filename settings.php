<?php
define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

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
      <div class="sidebar-logo-text">Site<span>Monitor</span></div>
    </div>
    <nav>
      <a class="nav-item" href="index.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        <span>Dashboard</span>
      </a>
      <a class="nav-item" href="sites.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        <span>Monitors</span>
      </a>
      <a class="nav-item active" href="settings.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
        <span>Settings</span>
      </a>
    </nav>
    <div class="sidebar-footer">
      <div class="sidebar-avatar"><?= strtoupper(substr($_SESSION['user'] ?? 'A', 0, 1)) ?></div>
      <div class="sidebar-user-info">
        <div class="sidebar-user-name"><?= htmlspecialchars($_SESSION['user'] ?? 'Admin') ?></div>
        <div class="sidebar-user-role">Administrator</div>
      </div>
      <a href="logout.php" class="sidebar-logout" title="Sign out">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
      </a>
    </div>
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

    <!-- Test Email Alerts -->
    <div class="table-panel" style="margin-bottom:24px">
      <div class="chart-title mb-4">Test Email Notifications</div>
      <p style="color:var(--muted);margin:0 0 16px;font-size:13px">Send test email alerts to verify your SMTP configuration and email template. Admin email will receive FROM_EMAIL configured in config.php</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
        <button class="btn btn-primary" onclick="sendTestEmail('down')" style="justify-self:start">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px"><path d="M9 12h6m-6 4h6M9 8h6m-6-4h6m4 0v16m-16 0v-16m16 2H3"/></svg>
          Test Down Alert
        </button>
        <button class="btn btn-success" onclick="sendTestEmail('recovery')" style="justify-self:start">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          Test Recovery Alert
        </button>
        <button class="btn btn-warning" onclick="sendTestEmail('ssl_expiry')" style="justify-self:start">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Test SSL Expiry Alert
        </button>
      </div>
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
async function sendTestEmail(alertType) {
  const alertLabels = {
    'down': 'Down Alert',
    'recovery': 'Recovery Alert',
    'ssl_expiry': 'SSL Expiry Alert'
  };
  
  const buttons = document.querySelectorAll('button[onclick^="sendTestEmail"]');
  buttons.forEach(btn => btn.disabled = true);
  
  try {
    const response = await fetch('api.php?action=test_email', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
      },
      body: JSON.stringify({ alert_type: alertType })
    });
    
    const result = await response.json();
    
    if (!response.ok || result.success === false) {
      throw new Error(result.error || 'Failed to send test email');
    }
    
    showToast('Test ' + alertLabels[alertType] + ' sent to ' + result.data.email, 'success');
  } catch (err) {
    showToast('Email Error: ' + err.message, 'error');
  } finally {
    buttons.forEach(btn => btn.disabled = false);
  }
}

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
