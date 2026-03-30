<?php
// =============================================================================
// site_details.php - Individual site analytics page
// =============================================================================

define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/Statistics.php';
require_once MONITOR_ROOT . '/includes/auth.php';

session_start();
requireLogin();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$siteId = (int) ($_GET['id'] ?? 0);
if (!$siteId) { header('Location: index.php'); exit; }

$site = Database::fetchOne('SELECT * FROM sites WHERE id = ?', [$siteId]);
if (!$site) { header('Location: index.php'); exit; }

$uptime30  = Statistics::getUptime($siteId, 30);
$uptime7   = Statistics::getUptime($siteId, 7);
$sla       = Statistics::getMonthlySLA($siteId);
$incidents = Statistics::getIncidents($siteId, 20);
$logs      = Statistics::getRecentLogs($siteId, 100);
$trend     = Statistics::getResponseTimeTrend($siteId, 168); // 7 days
$daily     = Statistics::getDailyUptime($siteId, 30);
$histogram = Statistics::getResponseHistogram($siteId);

// Latest log
$latest = Database::fetchOne(
    'SELECT * FROM logs WHERE site_id = ? ORDER BY created_at DESC LIMIT 1',
    [$siteId]
);

$statusClass = match($latest['status'] ?? 'unknown') {
    'up'      => 'green',
    'down'    => 'red',
    'warning' => 'yellow',
    default   => 'muted',
};
$userInitial = strtoupper(substr($_SESSION['user'] ?? 'A', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <title><?= htmlspecialchars($site['name']) ?> — Site Monitor</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="assets/css/dashboard.css?v=1.0.9">
</head>
<body class="dark-theme">
<div class="layout">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-logo">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
      </svg>
      <div class="sidebar-logo-text">Site<span>Monitor</span></div>
    </div>

    <div class="sidebar-section">Monitoring</div>
    <nav>
      <a class="nav-item" href="index.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        <span>Dashboard</span>
      </a>
      <a class="nav-item" href="sites.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        <span>Monitors</span>
      </a>
      <a class="nav-item" href="settings.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
        <span>Settings</span>
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-avatar"><?= $userInitial ?></div>
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

  <!-- Main -->
  <div class="main">

    <!-- Top bar -->
    <div class="topbar">
      <div class="topbar-left">
        <div style="display:flex;align-items:center;gap:12px">
          <a href="index.php" class="btn btn-ghost btn-sm" style="padding:4px 8px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
          </a>
          <div class="topbar-title"><?= htmlspecialchars($site['name']) ?></div>
        </div>
      </div>
      <div class="topbar-actions">
        <button class="btn btn-ghost btn-sm" onclick="exportCSV(<?= $siteId ?>)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Export CSV
        </button>
        <button class="btn btn-primary btn-sm" onclick="openSiteModal(<?= $siteId ?>)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          Edit Monitor
        </button>
      </div>
    </div>

    <!-- Page content -->
    <div class="page-content">

      <!-- Details grid -->
      <div class="cards" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
        <div class="card blue">
          <div class="card-label">Uptime Percentage</div>
          <div class="card-value"><?= $uptime30 ?>%</div>
          <div class="card-sub">Last 30 days</div>
        </div>
        <div class="card blue">
          <div class="card-label">Check Type</div>
          <div class="card-value"><?= strtoupper($site['check_type']) ?></div>
          <div class="card-sub"><?= htmlspecialchars($site['url']) ?></div>
        </div>
        <div class="card <?= $site['is_active'] ? 'green' : 'red' ?>">
          <div class="card-label">Status</div>
          <div class="card-value"><?= $site['is_active'] ? 'Active' : 'Paused' ?></div>
          <div class="card-sub">Monitoring is enabled</div>
        </div>
      </div>

      <!-- Charts grid -->
      <div class="charts-grid">
        <div class="chart-panel">
          <div class="chart-header"><div class="chart-title">30-Day Uptime</div></div>
          <canvas id="chart-daily-uptime"></canvas>
        </div>
        <div class="chart-panel">
          <div class="chart-header"><div class="chart-title">Response Distribution</div></div>
          <canvas id="chart-hist"></canvas>
        </div>
      </div>

      <!-- Incidents -->
      <div class="table-panel">
        <div class="table-panel-header"><div class="table-panel-title">Incidents History</div></div>
        <div class="table-wrap">
          <table id="incidents-table">
            <thead>
              <tr><th>Started</th><th>Resolved</th><th>Duration</th><th>Error</th></tr>
            </thead>
            <tbody>
              <?php foreach ($incidents as $inc): ?>
              <tr>
                <td><?= htmlspecialchars($inc['started_at']) ?></td>
                <td><?= $inc['ended_at'] ? htmlspecialchars($inc['ended_at']) : '<span class="text-red">Ongoing</span>' ?></td>
                <td><?= $inc['duration_seconds'] ? formatDuration((int)$inc['duration_seconds']) : '—' ?></td>
                <td class="text-muted"><?= htmlspecialchars($inc['error_message'] ?? '') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (empty($incidents)): ?>
            <div class="empty-state">No incidents recorded</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Logs -->
      <div class="table-panel">
        <div class="table-panel-header"><div class="table-panel-title">Recent Check Logs</div></div>
        <div class="table-wrap">
          <table id="logs-table">
            <thead>
              <tr><th>Time</th><th>Status</th><th>RT</th><th>Error / Info</th></tr>
            </thead>
            <tbody>
              <?php foreach ($logs as $l): ?>
              <tr>
                <td style="font-size:12px"><?= htmlspecialchars($l['created_at']) ?></td>
                <td><span class="badge <?= $l['status'] ?>"><span class="badge-dot"></span><?= $l['status'] ?></span></td>
                <td><?= round($l['response_time']) ?> ms</td>
                <td class="text-muted" style="font-size:12px"><?= htmlspecialchars($l['error_message'] ?? '') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (empty($logs)): ?>
            <div class="empty-state">No logs recorded yet</div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<!-- Modals -->
<?php require_once 'includes/modals.php'; ?>

<div id="toast-container"></div>

<!-- Inline chart data to avoid extra AJAX on load -->
<script>
const TREND_DATA    = <?= json_encode($trend) ?>;
const DAILY_DATA    = <?= json_encode($daily) ?>;
const HISTOGRAM_DATA = <?= json_encode($histogram) ?>;
const SITE_ID       = <?= $siteId ?>;
</script>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="assets/js/dashboard.js?v=1.0.9"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  // 30-day daily uptime bar chart
  const dailyCtx = document.getElementById('chart-daily-uptime');
  if (dailyCtx && DAILY_DATA.length) {
    new Chart(dailyCtx, {
      type: 'bar',
      data: {
        labels: DAILY_DATA.map(r => r.date),
        datasets: [{
          label: 'Uptime %',
          data: DAILY_DATA.map(r => r.uptime_percentage),
          backgroundColor: DAILY_DATA.map(r =>
            r.uptime_percentage >= 99 ? '#22c55e' :
            r.uptime_percentage >= 95 ? '#f59e0b' : '#ef4444'
          ),
          borderRadius: 3,
        }],
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { min: 0, max: 100, title: { display: true, text: 'Uptime %' } } },
      },
    });
  }

  // Histogram
  const histCtx = document.getElementById('chart-hist');
  if (histCtx) {
    new Chart(histCtx, {
      type: 'bar',
      data: {
        labels: Object.keys(HISTOGRAM_DATA).map(k => k + ' ms'),
        datasets: [{
          label: 'Checks',
          data: Object.values(HISTOGRAM_DATA),
          backgroundColor: '#8b5cf6',
          borderRadius: 4,
        }],
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } },
      },
    });
  }

  // DataTables
  if ($.fn.DataTable) {
    $('#logs-table').DataTable({ pageLength: 25, order: [[0, 'desc']] });
    $('#incidents-table').DataTable({ pageLength: 10, order: [[0, 'desc']] });
  }
});
</script>
</body>
</html>

<?php
function formatDuration(int $secs): string {
    if ($secs < 60)   return $secs . 's';
    if ($secs < 3600) return floor($secs / 60) . 'm ' . ($secs % 60) . 's';
    return floor($secs / 3600) . 'h ' . floor(($secs % 3600) / 60) . 'm';
}
?>
