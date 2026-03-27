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
        <div class="topbar-title">Dashboard</div>
        <div class="last-updated" id="last-updated"></div>
      </div>
      <div class="topbar-center">
        <div class="search-wrap">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="site-search" placeholder="Search monitors..." autocomplete="off">
        </div>
      </div>
      <div class="topbar-actions">
        <button class="btn btn-ghost btn-sm" id="btn-refresh">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Refresh
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
            <div style="display:flex;justify-content:space-between;align-items:center;width:100%">
              <div>
                <div class="chart-title">Performance Overlays — Last 24 Hours</div>
                <div class="chart-subtitle">Comparing top 8 monitors</div>
              </div>
              <div class="chart-legend-custom" id="trend-legend"></div>
            </div>
          </div>
          <canvas id="chart-response-trend" style="max-height:350px"></canvas>
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
              <div class="chart-title">Status by Type</div>
              <div class="chart-subtitle">Distribution of check types</div>
            </div>
          </div>
          <div style="max-height:240px;display:flex;justify-content:center">
            <canvas id="chart-status-types"></canvas>
          </div>
        </div>
      </div>

      <!-- Histogram + Gauge + Slowest -->
      <div class="charts-grid" style="grid-template-columns: 1fr 1fr 1fr">
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
            <canvas id="chart-gauge" style="max-height:160px;max-width:160px"></canvas>
            <div class="gauge-score" id="gauge-score"><?= $health['health_score'] ?>%</div>
            <div class="gauge-label">Overall Health</div>
          </div>
        </div>

        <div class="chart-panel">
          <div class="chart-header">
            <div>
              <div class="chart-title">Slowest Monitors</div>
              <div class="chart-subtitle">Average last 24h</div>
            </div>
          </div>
          <div id="slowest-list" class="slowest-list">
            <div class="empty-state">Loading...</div>
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

<!-- Modals -->
<?php require_once 'includes/modals.php'; ?>

<div id="toast-container"></div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="assets/js/dashboard.js"></script>
</body>
</html>
