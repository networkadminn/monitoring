<?php
// =============================================================================
// site_details.php - Individual site analytics page
// =============================================================================

define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/Statistics.php';
require_once MONITOR_ROOT . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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
$uptime1   = Statistics::getUptime($siteId, 1);
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

// Avg response time last 24h
$avgRt24h = Database::fetchOne(
    'SELECT ROUND(AVG(response_time),0) AS avg_rt, MIN(response_time) AS min_rt, MAX(response_time) AS max_rt
     FROM logs WHERE site_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)',
    [$siteId]
);

// Total checks & failures
$checkStats = Database::fetchOne(
    'SELECT COUNT(*) AS total, SUM(status="down") AS failures FROM logs WHERE site_id = ?',
    [$siteId]
);

// Multi-location results
$locationResults = [];
try {
    require_once MONITOR_ROOT . '/includes/MultiLocation.php';
    $locationResults = MultiLocation::getLatestResults($siteId);
} catch (Throwable $e) {}

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

      <!-- Status hero banner -->
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;gap:20px;box-shadow:var(--shadow-sm)">
        <div style="width:52px;height:52px;border-radius:14px;background:rgba(<?= $statusClass==='green'?'16,185,129':($statusClass==='red'?'239,68,68':'245,158,11') ?>,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <?php if ($statusClass === 'green'): ?>
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          <?php elseif ($statusClass === 'red'): ?>
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?php else: ?>
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          <?php endif; ?>
        </div>
        <div style="flex:1">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span class="badge <?= $latest['status'] ?? 'unknown' ?>" style="font-size:12px;padding:4px 12px">
              <span class="badge-dot"></span><?= strtoupper($latest['status'] ?? 'UNKNOWN') ?>
            </span>
            <span style="font-size:13px;color:var(--muted)">Last checked <?= $latest ? timeAgoPhp($latest['created_at']) : 'never' ?></span>
            <?php if (!empty($site['tags'])): foreach(explode(',',$site['tags']) as $t): $t=trim($t); if($t): ?>
            <span class="tag-badge"><?= htmlspecialchars($t) ?></span>
            <?php endif; endforeach; endif; ?>
          </div>
          <div style="margin-top:6px;font-size:13px;color:var(--muted)">
            <a href="<?= htmlspecialchars($site['url']) ?>" target="_blank" style="color:var(--blue)"><?= htmlspecialchars($site['url']) ?></a>
            &nbsp;·&nbsp; <?= strtoupper($site['check_type']) ?> check
            &nbsp;·&nbsp; Every <?= $site['check_interval'] ?? 1 ?> min
            <?php if ($latest && $latest['response_time']): ?>
            &nbsp;·&nbsp; <span style="color:var(--<?= $latest['response_time'] < 500 ? 'green' : ($latest['response_time'] < 1500 ? 'yellow' : 'red') ?>);font-weight:600"><?= round($latest['response_time']) ?>ms</span>
            <?php endif; ?>
          </div>
          <?php if ($latest && $latest['error_message'] && $latest['status'] === 'down'): ?>
          <div style="margin-top:8px;padding:8px 12px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:7px;font-size:12px;color:#f87171">
            <?= htmlspecialchars($latest['error_message']) ?>
          </div>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0">
          <button class="btn btn-ghost btn-sm" onclick="exportCSV(<?= $siteId ?>)">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export
          </button>
          <button class="btn btn-primary btn-sm" onclick="openSiteModal(<?= $siteId ?>)">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit
          </button>
        </div>
      </div>

      <!-- Key metrics row -->
      <div class="metric-row" style="margin-bottom:20px">
        <div class="metric-box">
          <div class="metric-box-value" style="color:var(--<?= $uptime30>=99?'green':($uptime30>=95?'yellow':'red') ?>)"><?= $uptime30 ?>%</div>
          <div class="metric-box-label">30-Day Uptime</div>
          <div class="metric-box-sub"><?= $uptime7 ?>% last 7d</div>
        </div>
        <div class="metric-box">
          <div class="metric-box-value" style="color:var(--<?= $sla>=99.9?'green':($sla>=99?'blue':($sla>=95?'yellow':'red')) ?>)"><?= $sla ?>%</div>
          <div class="metric-box-label">Monthly SLA</div>
          <div class="metric-box-sub"><?= date('F Y') ?></div>
        </div>
        <div class="metric-box">
          <div class="metric-box-value" style="color:var(--<?= ($avgRt24h['avg_rt']??0)<500?'green':(($avgRt24h['avg_rt']??0)<1500?'yellow':'red') ?>)"><?= $avgRt24h['avg_rt'] ?? '—' ?>ms</div>
          <div class="metric-box-label">Avg Response</div>
          <div class="metric-box-sub">Min <?= round($avgRt24h['min_rt']??0) ?>ms · Max <?= round($avgRt24h['max_rt']??0) ?>ms</div>
        </div>
        <div class="metric-box">
          <div class="metric-box-value" style="color:var(--blue)"><?= number_format($checkStats['total']??0) ?></div>
          <div class="metric-box-label">Total Checks</div>
          <div class="metric-box-sub"><?= $checkStats['failures']??0 ?> failures</div>
        </div>
        <div class="metric-box">
          <div class="metric-box-value" style="color:var(--<?= count($incidents)===0?'green':'yellow' ?>)"><?= count($incidents) ?></div>
          <div class="metric-box-label">Incidents</div>
          <div class="metric-box-sub">All time</div>
        </div>
        <?php if ($latest && $latest['ssl_expiry_days'] !== null): ?>
        <div class="metric-box">
          <div class="metric-box-value" style="color:var(--<?= $latest['ssl_expiry_days']<=7?'red':($latest['ssl_expiry_days']<=30?'yellow':'green') ?>)"><?= $latest['ssl_expiry_days'] ?>d</div>
          <div class="metric-box-label">SSL Expiry</div>
          <div class="metric-box-sub"><?= $latest['ssl_expiry_days']<=30?'Renew soon':'Certificate valid' ?></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Multi-location results -->
      <?php if (!empty($locationResults)): ?>
      <div class="table-panel" style="margin-bottom:20px">
        <div class="table-panel-header">
          <div class="table-panel-title">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:6px"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            Multi-Location Status
          </div>
          <span style="font-size:12px;color:var(--muted)"><?= count($locationResults) ?> locations checked</span>
        </div>
        <div style="padding:16px">
          <div class="location-grid">
            <?php foreach ($locationResults as $lr): ?>
            <div class="location-card <?= $lr['status'] ?>">
              <div class="location-flag"><?= $lr['flag'] ?? '🌐' ?></div>
              <div class="location-info">
                <div class="location-name"><?= htmlspecialchars($lr['location_name']) ?></div>
                <div class="location-region"><?= htmlspecialchars($lr['region'] ?? '') ?></div>
              </div>
              <div>
                <div class="location-rt <?= $lr['status'] ?>"><?= $lr['status'] === 'up' ? round($lr['response_time']).'ms' : '—' ?></div>
                <span class="badge <?= $lr['status'] ?>" style="font-size:9px;padding:1px 6px;margin-top:3px;display:inline-flex">
                  <span class="badge-dot"></span><?= $lr['status'] ?>
                </span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Charts grid -->
      <div class="charts-grid" style="margin-bottom:20px">
        <div class="chart-panel">
          <div class="chart-header">
            <div>
              <div class="chart-title">Response Time — Last 7 Days</div>
              <div class="chart-subtitle">Hourly average with min/max range</div>
            </div>
          </div>
          <canvas id="chart-response-trend" style="max-height:220px"></canvas>
        </div>
        <div class="chart-panel">
          <div class="chart-header">
            <div>
              <div class="chart-title">30-Day Uptime</div>
              <div class="chart-subtitle">Daily uptime percentage</div>
            </div>
          </div>
          <canvas id="chart-daily-uptime" style="max-height:220px"></canvas>
        </div>
      </div>

      <div class="charts-grid" style="grid-template-columns:1fr 1fr 1fr;margin-bottom:20px">
        <div class="chart-panel">
          <div class="chart-header"><div class="chart-title">Response Distribution</div></div>
          <canvas id="chart-hist" style="max-height:180px"></canvas>
        </div>
        <div class="chart-panel" style="grid-column:span 2">
          <div class="chart-header">
            <div>
              <div class="chart-title">Uptime Heatmap — Last 90 Days</div>
              <div class="chart-subtitle">Each square = 1 day</div>
            </div>
          </div>
          <div id="uptime-heatmap" style="padding:8px 0"></div>
          <div style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:11px;color:var(--muted)">
            <span>Less</span>
            <div style="width:12px;height:12px;border-radius:2px;background:var(--surface3)"></div>
            <div style="width:12px;height:12px;border-radius:2px;background:#6ee7b7"></div>
            <div style="width:12px;height:12px;border-radius:2px;background:#34d399"></div>
            <div style="width:12px;height:12px;border-radius:2px;background:#10b981"></div>
            <div style="width:12px;height:12px;border-radius:2px;background:#ef4444"></div>
            <span>More / Down</span>
          </div>
        </div>
      </div>

      <!-- Incidents timeline -->
      <div class="table-panel" style="margin-bottom:20px">
        <div class="table-panel-header">
          <div class="table-panel-title">Incident History</div>
          <span style="font-size:12px;color:var(--muted)"><?= count($incidents) ?> incidents</span>
        </div>
        <?php if (empty($incidents)): ?>
        <div class="empty-state">
          <div class="empty-state-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
          <div class="empty-state-title">No incidents recorded</div>
          <div class="empty-state-desc">This monitor has been running without any downtime.</div>
        </div>
        <?php else: ?>
        <div style="padding:0 20px">
          <div class="timeline">
            <?php foreach ($incidents as $inc):
              $resolved = !empty($inc['ended_at']);
              $dur = $inc['duration_seconds'] ? formatDuration((int)$inc['duration_seconds']) : 'Ongoing';
            ?>
            <div class="timeline-item">
              <div class="timeline-dot <?= $resolved ? 'up' : 'down' ?>"></div>
              <div class="timeline-content">
                <div class="timeline-title"><?= $resolved ? '✅ Resolved' : '🔴 Ongoing Incident' ?></div>
                <div class="timeline-meta">
                  Started: <?= htmlspecialchars($inc['started_at']) ?>
                  <?= $resolved ? ' · Resolved: ' . htmlspecialchars($inc['ended_at']) : '' ?>
                  <?php if ($inc['error_message']): ?>
                  · <span style="color:var(--red)"><?= htmlspecialchars($inc['error_message']) ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="timeline-duration"><?= $dur ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Recent logs -->
      <div class="table-panel">
        <div class="table-panel-header">
          <div class="table-panel-title">Recent Check Logs</div>
          <button class="btn btn-ghost btn-sm" onclick="exportCSV(<?= $siteId ?>)">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
          </button>
        </div>
        <div class="table-wrap">
          <table id="logs-table">
            <thead>
              <tr><th>Time</th><th>Status</th><th>Response Time</th><th>SSL Days</th><th>Error / Info</th></tr>
            </thead>
            <tbody>
              <?php foreach ($logs as $l):
                $rtClass = $l['response_time'] < 500 ? 'green' : ($l['response_time'] < 1500 ? 'yellow' : 'red');
              ?>
              <tr>
                <td style="font-size:12px;white-space:nowrap"><?= htmlspecialchars($l['created_at']) ?></td>
                <td><span class="badge <?= $l['status'] ?>"><span class="badge-dot"></span><?= $l['status'] ?></span></td>
                <td>
                  <div class="rt-indicator <?= $rtClass ?>">
                    <div class="rt-bar"><div class="rt-bar-fill <?= $rtClass ?>" style="width:<?= min(100, round($l['response_time']/30)) ?>%"></div></div>
                    <?= round($l['response_time']) ?>ms
                  </div>
                </td>
                <td style="font-size:12px;color:var(--muted)"><?= $l['ssl_expiry_days'] !== null ? $l['ssl_expiry_days'].'d' : '—' ?></td>
                <td style="font-size:12px;color:var(--muted);max-width:300px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($l['error_message'] ?? '') ?></td>
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
const TREND_DATA     = <?= json_encode($trend) ?>;
const DAILY_DATA     = <?= json_encode($daily) ?>;
const HISTOGRAM_DATA = <?= json_encode($histogram) ?>;
const SITE_ID        = <?= $siteId ?>;
</script>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="assets/js/dashboard.js?v=2.0"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const isLight = document.body.classList.contains('light-theme');
  const gridColor = isLight ? 'rgba(0,0,0,0.05)' : 'rgba(255,255,255,0.05)';
  const textColor = isLight ? '#64748b' : '#7a87a8';

  // Response trend line chart
  const trendCtx = document.getElementById('chart-response-trend');
  if (trendCtx && TREND_DATA.length) {
    new Chart(trendCtx, {
      type: 'line',
      data: {
        labels: TREND_DATA.map(r => r.hour.slice(11,16)),
        datasets: [
          {
            label: 'Avg RT',
            data: TREND_DATA.map(r => r.avg_rt),
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59,130,246,0.08)',
            fill: true, tension: 0.4, pointRadius: 2, borderWidth: 2,
          },
          {
            label: 'Max RT',
            data: TREND_DATA.map(r => r.max_rt),
            borderColor: 'rgba(239,68,68,0.5)',
            borderDash: [4,4], fill: false, tension: 0.4, pointRadius: 0, borderWidth: 1,
          }
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor } },
          x: { grid: { display: false }, ticks: { color: textColor, maxTicksLimit: 12 } }
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
        labels: DAILY_DATA.map(r => r.date.slice(5)),
        datasets: [{
          label: 'Uptime %',
          data: DAILY_DATA.map(r => r.uptime_percentage),
          backgroundColor: DAILY_DATA.map(r =>
            r.uptime_percentage >= 99 ? '#10b981' :
            r.uptime_percentage >= 95 ? '#f59e0b' : '#ef4444'
          ),
          borderRadius: 4,
        }],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { min: 0, max: 100, grid: { color: gridColor }, ticks: { color: textColor } },
          x: { grid: { display: false }, ticks: { color: textColor, maxTicksLimit: 15 } }
        },
      },
    });
  }

  // Histogram
  const histCtx = document.getElementById('chart-hist');
  if (histCtx) {
    new Chart(histCtx, {
      type: 'bar',
      data: {
        labels: Object.keys(HISTOGRAM_DATA).map(k => k + 'ms'),
        datasets: [{
          label: 'Checks',
          data: Object.values(HISTOGRAM_DATA),
          backgroundColor: ['#10b981','#34d399','#f59e0b','#f97316','#ef4444'],
          borderRadius: 5,
        }],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor } },
          x: { grid: { display: false }, ticks: { color: textColor } }
        },
      },
    });
  }

  // Uptime heatmap (90 days)
  const heatmapEl = document.getElementById('uptime-heatmap');
  if (heatmapEl) {
    const dailyMap = {};
    DAILY_DATA.forEach(d => { dailyMap[d.date] = parseFloat(d.uptime_percentage); });
    let html = '<div class="heatmap">';
    for (let i = 89; i >= 0; i--) {
      const d = new Date(); d.setDate(d.getDate() - i);
      const key = d.toISOString().slice(0,10);
      const pct = dailyMap[key];
      let cls = 'empty';
      if (pct !== undefined) {
        cls = pct >= 99.9 ? 'up-100' : pct >= 99 ? 'up-99' : pct >= 95 ? 'up-95' : pct >= 90 ? 'up-90' : 'down';
      }
      html += `<div class="heatmap-day ${cls}" data-tooltip="${key}: ${pct !== undefined ? pct+'%' : 'No data'}"></div>`;
    }
    html += '</div>';
    heatmapEl.innerHTML = html;
  }

  // DataTables
  if ($.fn.DataTable) {
    if ($('#logs-table tbody tr').length > 0) {
      $('#logs-table').DataTable({ pageLength: 25, order: [[0, 'desc']], language: { search: 'Filter:' } });
    }
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
