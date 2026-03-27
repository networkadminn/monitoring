<?php
// =============================================================================
// site_details.php - Individual site analytics page
// =============================================================================

define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/Statistics.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <title><?= htmlspecialchars($site['name']) ?> — Monitor</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
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
    </nav>
  </aside>

  <main class="main">
    <div class="topbar">
      <div>
        <a href="index.php" class="text-muted" style="font-size:13px">← Back to Dashboard</a>
        <h1 style="margin-top:4px"><?= htmlspecialchars($site['name']) ?></h1>
      </div>
      <div class="topbar-actions">
        <button class="btn btn-ghost" onclick="exportCSV(<?= $siteId ?>)">Export CSV</button>
        <button class="btn btn-primary" onclick="openSiteModal(<?= $siteId ?>)">Edit Site</button>
      </div>
    </div>

    <!-- Info cards -->
    <div class="cards">
      <div class="card <?= $statusClass ?>">
        <div class="card-label">Current Status</div>
        <div class="card-value <?= $statusClass ?>"><?= strtoupper($latest['status'] ?? 'Unknown') ?></div>
        <div class="card-sub"><?= htmlspecialchars($latest['error_message'] ?? 'All good') ?></div>
      </div>
      <div class="card blue">
        <div class="card-label">Response Time</div>
        <div class="card-value blue"><?= $latest['response_time'] ?? '—' ?> ms</div>
        <div class="card-sub">Last check</div>
      </div>
      <div class="card green">
        <div class="card-label">Uptime (30d)</div>
        <div class="card-value green"><?= $uptime30 ?>%</div>
        <div class="card-sub">7d: <?= $uptime7 ?>%</div>
      </div>
      <div class="card purple">
        <div class="card-label">Monthly SLA</div>
        <div class="card-value"><?= $sla ?>%</div>
        <div class="card-sub"><?= date('F Y') ?></div>
      </div>
      <?php if ($latest['ssl_expiry_days'] !== null): ?>
      <div class="card <?= $latest['ssl_expiry_days'] <= 7 ? 'red' : ($latest['ssl_expiry_days'] <= 30 ? 'yellow' : 'green') ?>">
        <div class="card-label">SSL Expiry</div>
        <div class="card-value"><?= $latest['ssl_expiry_days'] ?> days</div>
        <div class="card-sub">Certificate</div>
      </div>
      <?php endif; ?>
      <div class="card blue">
        <div class="card-label">Check Type</div>
        <div class="card-value blue" style="font-size:20px"><?= strtoupper(htmlspecialchars($site['check_type'])) ?></div>
        <div class="card-sub"><?= htmlspecialchars($site['url']) ?></div>
      </div>
    </div>

    <!-- 7-day response trend with min/max bands -->
    <div class="chart-panel full mb-4">
      <div class="chart-title">7-Day Response Time Trend <span>With min/max bands</span></div>
      <canvas id="chart-trend"></canvas>
    </div>

    <!-- 30-day daily uptime -->
    <div class="charts-grid">
      <div class="chart-panel">
        <div class="chart-title">30-Day Daily Uptime</div>
        <canvas id="chart-daily-uptime"></canvas>
      </div>
      <div class="chart-panel">
        <div class="chart-title">Response Time Distribution <span>Last 100 checks</span></div>
        <canvas id="chart-hist"></canvas>
      </div>
    </div>

    <!-- Incident history -->
    <div class="table-panel">
      <div class="chart-title mb-4">Incident History</div>
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
          <?php if (empty($incidents)): ?>
          <tr><td colspan="4" class="text-muted" style="text-align:center;padding:24px">No incidents recorded</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Full logs table -->
    <div class="table-panel">
      <div class="chart-title mb-4">Recent Logs <span>Last 100 checks</span></div>
      <table id="logs-table">
        <thead>
          <tr><th>Time</th><th>Status</th><th>Response (ms)</th><th>SSL Days</th><th>Error</th></tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
          <tr>
            <td><?= htmlspecialchars($log['created_at']) ?></td>
            <td><span class="badge <?= htmlspecialchars($log['status']) ?>"><?= htmlspecialchars($log['status']) ?></span></td>
            <td><?= htmlspecialchars($log['response_time']) ?></td>
            <td><?= $log['ssl_expiry_days'] !== null ? htmlspecialchars($log['ssl_expiry_days']) : '—' ?></td>
            <td class="text-muted"><?= htmlspecialchars($log['error_message'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Edit form -->
    <div class="table-panel">
      <div class="chart-title mb-4">Edit Configuration</div>
      <form id="site-form" onsubmit="saveSite(event)">
        <input type="hidden" id="site-id" value="<?= $siteId ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="form-group">
            <label>Site Name</label>
            <input type="text" id="site-name" class="form-control" value="<?= htmlspecialchars($site['name']) ?>" required>
          </div>
          <div class="form-group">
            <label>URL</label>
            <input type="url" id="site-url" class="form-control" value="<?= htmlspecialchars($site['url']) ?>" required>
          </div>
          <div class="form-group">
            <label>Check Type</label>
            <select id="site-check-type" class="form-control">
              <?php foreach (['http','ssl','port','dns','keyword'] as $t): ?>
              <option value="<?= $t ?>" <?= $site['check_type'] === $t ? 'selected' : '' ?>><?= strtoupper($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Expected HTTP Status</label>
            <input type="number" id="site-expected" class="form-control" value="<?= (int)$site['expected_status'] ?>">
          </div>
          <div class="form-group">
            <label>Port</label>
            <input type="number" id="site-port" class="form-control" value="<?= htmlspecialchars($site['port'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Hostname</label>
            <input type="text" id="site-hostname" class="form-control" value="<?= htmlspecialchars($site['hostname'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Keyword</label>
            <input type="text" id="site-keyword" class="form-control" value="<?= htmlspecialchars($site['keyword'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Alert Email</label>
            <input type="email" id="site-email" class="form-control" value="<?= htmlspecialchars($site['alert_email'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group" style="display:flex;align-items:center;gap:10px">
          <input type="checkbox" id="site-active" <?= $site['is_active'] ? 'checked' : '' ?> style="width:16px;height:16px">
          <label for="site-active" style="margin:0">Active</label>
        </div>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </form>
    </div>

  </main>
</div>

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
<script src="assets/js/dashboard.js"></script>
<script>
// ── Site detail charts (rendered from inline PHP data) ────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // 7-day trend with min/max bands
  const trendCtx = document.getElementById('chart-trend');
  if (trendCtx && TREND_DATA.length) {
    new Chart(trendCtx, {
      type: 'line',
      data: {
        labels: TREND_DATA.map(r => r.hour.slice(5, 16)),
        datasets: [
          {
            label: 'Avg RT (ms)',
            data: TREND_DATA.map(r => r.avg_rt),
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59,130,246,0.15)',
            fill: true,
            tension: 0.4,
            pointRadius: 2,
          },
          {
            label: 'Max RT',
            data: TREND_DATA.map(r => r.max_rt),
            borderColor: '#ef4444',
            borderDash: [4, 4],
            fill: false,
            tension: 0.4,
            pointRadius: 0,
          },
          {
            label: 'Min RT',
            data: TREND_DATA.map(r => r.min_rt),
            borderColor: '#22c55e',
            borderDash: [4, 4],
            fill: false,
            tension: 0.4,
            pointRadius: 0,
          },
        ],
      },
      options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'bottom' } },
        scales: {
          y: { title: { display: true, text: 'ms' }, beginAtZero: true },
          x: { ticks: { maxTicksLimit: 14 } },
        },
      },
    });
  }

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
