<?php
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

try {
    $health = Statistics::getSystemHealth();
} catch (Throwable $e) {
    header('Location: install.php');
    exit;
}

$userInitial = strtoupper(substr($_SESSION['user'] ?? 'A', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <title>Dashboard — Site Monitor</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
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
      <a class="nav-item active" href="index.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        <span>Dashboard</span>
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
        <div class="topbar-title">Dashboard</div>
        <div class="last-updated" id="last-updated"></div>
      </div>
      <div class="topbar-actions">
        <button class="btn btn-ghost btn-sm" id="btn-refresh">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Refresh
        </button>
        <button class="btn btn-danger btn-sm" id="btn-bulk-delete" style="display:none">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
          Delete Selected (<span id="bulk-count">0</span>)
        </button>
        <button class="btn btn-primary btn-sm" id="btn-add-site">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Monitor
        </button>
      </div>
    </div>

    <!-- Status bar -->
    <div class="status-bar">
      <div class="status-bar-item">
        <div class="status-dot <?= $health['sites_down'] > 0 ? 'red' : 'green' ?>" id="sb-dot"></div>
        <span id="sb-status"><?= $health['sites_down'] > 0 ? $health['sites_down'] . ' site(s) down' : 'All systems operational' ?></span>
      </div>
      <div class="status-bar-divider"></div>
      <div class="status-bar-item">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Auto-refresh every 60s
      </div>
      <div class="status-bar-divider"></div>
      <div class="status-bar-item">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        <span id="sb-total"><?= $health['total_sites'] ?> monitors active</span>
      </div>
    </div>

    <!-- Page content -->
    <div class="page-content">

      <!-- Stat cards -->
      <div class="cards">
        <div class="card blue">
          <div class="card-icon blue">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
          </div>
          <div class="card-label">Total Monitors</div>
          <div class="card-value blue" id="card-total"><?= $health['total_sites'] ?></div>
          <div class="card-sub">Active checks</div>
        </div>

        <div class="card <?= $health['sites_up'] > 0 ? 'green' : 'red' ?>">
          <div class="card-icon green">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
          <div class="card-label">Sites Up</div>
          <div class="card-value green" id="card-up"><?= $health['sites_up'] ?></div>
          <div class="card-sub">Responding normally</div>
        </div>

        <div class="card <?= $health['sites_down'] > 0 ? 'red' : 'green' ?>" id="card-down-wrap">
          <div class="card-icon red">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          </div>
          <div class="card-label">Sites Down</div>
          <div class="card-value red" id="card-down"><?= $health['sites_down'] ?></div>
          <div class="card-sub">Needs attention</div>
        </div>

        <div class="card blue">
          <div class="card-icon cyan">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          </div>
          <div class="card-label">Avg Response</div>
          <div class="card-value blue" id="card-avgrt"><?= $health['avg_response'] ?> ms</div>
          <div class="card-sub">Last hour</div>
        </div>

        <div class="card purple">
          <div class="card-icon purple">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </div>
          <div class="card-label">Health Score</div>
          <div class="card-value" id="card-health"><?= $health['health_score'] ?>%</div>
          <div class="card-sub">Overall system</div>
        </div>
      </div>

      <!-- Response trend chart -->
      <div class="charts-grid" style="grid-template-columns:1fr">
        <div class="chart-panel">
          <div class="chart-header">
            <div>
              <div class="chart-title">Response Time — Last 24 Hours</div>
              <div class="chart-subtitle">All monitors overlay</div>
            </div>
          </div>
          <canvas id="chart-response-trend"></canvas>
        </div>
      </div>

      <!-- SSL + Uptime charts -->
      <div class="charts-grid">
        <div class="chart-panel">
          <div class="chart-header">
            <div>
              <div class="chart-title">SSL Certificate Expiry</div>
              <div class="chart-subtitle">Days remaining per site</div>
            </div>
          </div>
          <canvas id="chart-ssl"></canvas>
        </div>
        <div class="chart-panel">
          <div class="chart-header">
            <div>
              <div class="chart-title">30-Day Uptime Trend</div>
              <div class="chart-subtitle" id="uptime-chart-label">First monitor</div>
            </div>
          </div>
          <canvas id="chart-uptime"></canvas>
        </div>
      </div>

      <!-- Histogram + Gauge -->
      <div class="charts-grid">
        <div class="chart-panel">
          <div class="chart-header">
            <div>
              <div class="chart-title">Response Time Distribution</div>
              <div class="chart-subtitle">Last 100 checks</div>
            </div>
          </div>
          <canvas id="chart-histogram"></canvas>
        </div>
        <div class="chart-panel">
          <div class="chart-header">
            <div>
              <div class="chart-title">System Health Score</div>
              <div class="chart-subtitle">Overall platform health</div>
            </div>
          </div>
          <div class="gauge-wrap">
            <canvas id="chart-gauge" style="max-height:180px;max-width:180px"></canvas>
            <div class="gauge-score" id="gauge-score"><?= $health['health_score'] ?>%</div>
            <div class="gauge-label">Overall Health</div>
          </div>
        </div>
      </div>

      <!-- Sites table -->
      <div class="table-panel">
        <div class="table-panel-header">
          <div class="table-panel-title">All Monitors</div>
          <div class="table-panel-actions">
            <span class="text-muted" style="font-size:12px" id="sites-count"></span>
          </div>
        </div>
        <div class="loading-overlay" id="sites-loading-overlay">
          <div class="spinner" style="width:32px;height:32px"></div>
        </div>
        <div class="table-wrap">
          <table id="sites-table">
            <thead>
              <tr>
                <th style="width:36px"><input type="checkbox" id="select-all-sites" title="Select all" style="cursor:pointer"></th>
                <th>Monitor</th>
                <th>Status</th>
                <th>Response</th>
                <th>Uptime (30d)</th>
                <th>Last 30 Days</th>
                <th>Last Check</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="sites-tbody"></tbody>
          </table>
        </div>
      </div>

      <!-- Recent incidents -->
      <div class="table-panel">
        <div class="table-panel-header">
          <div class="table-panel-title">Recent Incidents</div>
        </div>
        <div class="table-wrap">
          <table id="incidents-table">
            <thead>
              <tr>
                <th>Site</th>
                <th>Started</th>
                <th>Resolved</th>
                <th>Duration</th>
                <th>Error</th>
              </tr>
            </thead>
            <tbody id="incidents-tbody"></tbody>
          </table>
        </div>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<!-- Add/Edit Site Modal -->
<div class="modal-overlay" id="modal-overlay">
  <div class="modal">
    <div class="modal-header">
      <h3 id="modal-title">Add Monitor</h3>
      <button class="modal-close" id="modal-close">&times;</button>
    </div>
    <div class="modal-body">
      <form id="site-form" novalidate>
        <input type="hidden" id="site-id">

        <div class="tabs">
          <div class="tab active" data-tab="basic">Basic</div>
          <div class="tab" data-tab="advanced">Advanced</div>
          <div class="tab" data-tab="alerts">Alerts</div>
        </div>

        <!-- Basic tab -->
        <div class="tab-pane active" id="tab-basic">
          <div class="form-group">
            <label>Monitor Name *</label>
            <input type="text" id="site-name" class="form-control" placeholder="My Website" autocomplete="off">
            <div class="form-error" id="err-name"></div>
          </div>
          <div class="form-group">
            <label>URL *</label>
            <input type="url" id="site-url" class="form-control" placeholder="https://example.com" autocomplete="off">
            <div class="form-error" id="err-url"></div>
          </div>
          <div class="form-group">
            <label>Check Type</label>
            <select id="site-check-type" class="form-control">
              <option value="http">HTTP / HTTPS</option>
              <option value="ssl">SSL Certificate</option>
              <option value="port">Port Check</option>
              <option value="dns">DNS Lookup</option>
              <option value="keyword">Keyword Match</option>
            </select>
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:10px">
            <input type="checkbox" id="site-active" checked style="width:15px;height:15px;cursor:pointer">
            <label for="site-active" style="margin:0;cursor:pointer">Active (enable monitoring)</label>
          </div>
        </div>

        <!-- Advanced tab -->
        <div class="tab-pane" id="tab-advanced">
          <div class="form-grid">
            <div class="form-group">
              <label>Port</label>
              <input type="number" id="site-port" class="form-control" placeholder="443">
            </div>
            <div class="form-group">
              <label>Expected HTTP Status</label>
              <input type="number" id="site-expected" class="form-control" value="200">
            </div>
          </div>
          <div class="form-group">
            <label>Hostname (for port/DNS checks)</label>
            <input type="text" id="site-hostname" class="form-control" placeholder="mail.example.com">
          </div>
          <div class="form-group">
            <label>Keyword (for keyword checks)</label>
            <input type="text" id="site-keyword" class="form-control" placeholder="Welcome to our site">
          </div>
        </div>

        <!-- Alerts tab -->
        <div class="tab-pane" id="tab-alerts">
          <div class="form-group">
            <label>Alert Email</label>
            <input type="email" id="site-email" class="form-control" placeholder="ops@example.com">
          </div>
          <p style="font-size:12px;color:var(--muted);margin-top:8px">
            Alerts are sent when a site goes down and when it recovers. A cooldown of <?= ALERT_COOLDOWN / 60 ?> minutes applies between repeat alerts.
          </p>
        </div>

        <!-- Test result -->
        <div id="test-result" style="margin-top:16px;padding:12px;border-radius:6px;display:none;font-size:13px;line-height:1.4"></div>

      </form>
    </div>
    <div class="modal-footer">
      <div style="flex-grow:1;display:flex;gap:8px">
        <button type="button" class="btn btn-ghost" id="modal-test">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          Test Connection
        </button>
      </div>
      <button type="button" class="btn btn-ghost" id="modal-cancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="modal-save">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        Save Monitor
      </button>
    </div>
  </div>
</div>

<!-- Confirm Delete Modal -->
<div class="modal-overlay" id="confirm-overlay">
  <div class="modal confirm-modal">
    <div class="modal-body" style="padding:28px 24px 20px">
      <div class="confirm-icon danger">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
      </div>
      <div class="confirm-title" id="confirm-title">Delete Monitor</div>
      <div class="confirm-msg" id="confirm-msg">This action cannot be undone.</div>
      <ul class="confirm-sites" id="confirm-sites" style="display:none"></ul>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="confirm-cancel">Cancel</button>
      <button class="btn btn-danger" id="confirm-ok">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
        Delete
      </button>
    </div>
  </div>
</div>

<div id="toast-container"></div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="assets/js/dashboard.js"></script>
</body>
</html>
