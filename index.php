<?php
// =============================================================================
// index.php - Main monitoring dashboard
// =============================================================================

define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/Statistics.php';

session_start();

// Basic auth
if (DASHBOARD_AUTH) {
    if (!isset($_SERVER['PHP_AUTH_USER']) ||
        $_SERVER['PHP_AUTH_USER'] !== DASHBOARD_USER ||
        !hash_equals(DASHBOARD_PASS, $_SERVER['PHP_AUTH_PW'] ?? '')) {
        header('WWW-Authenticate: Basic realm="Monitor"');
        http_response_code(401);
        exit('Unauthorized');
    }
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Quick server-side health for initial render (avoids flash)
try {
    $health = Statistics::getSystemHealth();
} catch (Throwable $e) {
    // Tables not installed yet — redirect to installer
    header('Location: install.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <title>Site Monitor — Dashboard</title>
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
      <span>Monitor</span>
    </div>
    <nav>
      <a class="nav-item active" href="index.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        <span>Dashboard</span>
      </a>
      <a class="nav-item" href="install.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
        <span>Settings</span>
      </a>
    </nav>
  </aside>

  <!-- Main content -->
  <main class="main">
    <div class="topbar">
      <h1>Dashboard</h1>
      <div class="topbar-actions">
        <span class="last-updated" id="last-updated"></span>
        <button class="btn btn-ghost" id="btn-refresh">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Refresh
        </button>
        <button class="btn btn-primary" id="btn-add-site">+ Add Site</button>
      </div>
    </div>

    <!-- Health cards -->
    <div class="cards">
      <div class="card blue">
        <div class="card-label">Total Sites</div>
        <div class="card-value blue" id="card-total"><?= $health['total_sites'] ?></div>
        <div class="card-sub">Active monitors</div>
      </div>
      <div class="card <?= $health['sites_down'] > 0 ? 'red' : 'green' ?>" id="card-down-wrap">
        <div class="card-label">Sites Down</div>
        <div class="card-value <?= $health['sites_down'] > 0 ? 'red' : 'green' ?>" id="card-down"><?= $health['sites_down'] ?></div>
        <div class="card-sub"><?= $health['sites_up'] ?> sites up</div>
      </div>
      <div class="card blue">
        <div class="card-label">Avg Response</div>
        <div class="card-value blue" id="card-avgrt"><?= $health['avg_response'] ?> ms</div>
        <div class="card-sub">Last hour</div>
      </div>
      <div class="card <?= $health['ssl_warnings'] > 0 ? 'yellow' : 'green' ?>">
        <div class="card-label">SSL Warnings</div>
        <div class="card-value <?= $health['ssl_warnings'] > 0 ? 'yellow' : 'green' ?>" id="card-ssl"><?= $health['ssl_warnings'] ?></div>
        <div class="card-sub">Expiring &lt;30 days</div>
      </div>
      <div class="card purple">
        <div class="card-label">Health Score</div>
        <div class="card-value" id="card-health"><?= $health['health_score'] ?>%</div>
        <div class="card-sub">Overall system</div>
      </div>
    </div>

    <!-- Charts row 1 -->
    <div class="charts-grid">
      <div class="chart-panel full">
        <div class="chart-title">Response Time — Last 24 Hours <span>All sites overlay</span></div>
        <canvas id="chart-response-trend"></canvas>
      </div>
    </div>

    <!-- Charts row 2 -->
    <div class="charts-grid">
      <div class="chart-panel">
        <div class="chart-title">SSL Certificate Expiry <span>Days remaining</span></div>
        <canvas id="chart-ssl"></canvas>
      </div>
      <div class="chart-panel">
        <div class="chart-title">30-Day Uptime <span>First site</span></div>
        <canvas id="chart-uptime"></canvas>
      </div>
    </div>

    <!-- Charts row 3 -->
    <div class="charts-grid">
      <div class="chart-panel">
        <div class="chart-title">Response Time Distribution <span>Last 100 checks</span></div>
        <canvas id="chart-histogram"></canvas>
      </div>
      <div class="chart-panel">
        <div class="chart-title">System Health Score</div>
        <div class="gauge-wrap" style="padding:20px 0">
          <canvas id="chart-gauge" style="max-height:200px;max-width:200px"></canvas>
          <div class="gauge-score" id="gauge-score"><?= $health['health_score'] ?>%</div>
          <div class="gauge-label">Overall Health</div>
        </div>
      </div>
    </div>

    <!-- Sites table -->
    <div class="table-panel">
      <div class="chart-title mb-4">All Sites</div>
      <table id="sites-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>URL</th>
            <th>Status</th>
            <th>Response</th>
            <th>Uptime (30d)</th>
            <th>Last Check</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="sites-tbody"></tbody>
      </table>
    </div>

    <!-- Recent incidents -->
    <div class="table-panel">
      <div class="chart-title mb-4">Recent Incidents</div>
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

  </main>
</div>

<!-- Add/Edit Site Modal -->
<div class="modal-overlay" id="modal-overlay">
  <div class="modal">
    <div class="modal-header">
      <h3 id="modal-title">Add Site</h3>
      <button class="modal-close" id="modal-close">&times;</button>
    </div>
    <form id="site-form">
      <input type="hidden" id="site-id">
      <div class="form-group">
        <label>Site Name *</label>
        <input type="text" id="site-name" class="form-control" required placeholder="My Website">
      </div>
      <div class="form-group">
        <label>URL *</label>
        <input type="url" id="site-url" class="form-control" required placeholder="https://example.com">
      </div>
      <div class="form-group">
        <label>Check Type</label>
        <select id="site-check-type" class="form-control">
          <option value="http">HTTP/HTTPS</option>
          <option value="ssl">SSL Certificate</option>
          <option value="port">Port</option>
          <option value="dns">DNS</option>
          <option value="keyword">Keyword</option>
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group">
          <label>Port (optional)</label>
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
        <label>Keyword (for keyword/DNS checks)</label>
        <input type="text" id="site-keyword" class="form-control" placeholder="Welcome to our site">
      </div>
      <hr style="border-color:var(--border);margin:16px 0">
      <div class="form-group">
        <label>Alert Email</label>
        <input type="email" id="site-email" class="form-control" placeholder="ops@example.com">
      </div>
      <div class="form-group" style="display:flex;align-items:center;gap:10px">
        <input type="checkbox" id="site-active" checked style="width:16px;height:16px">
        <label for="site-active" style="margin:0">Active</label>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Site</button>
      </div>
    </form>
  </div>
</div>

<!-- Toast container -->
<div id="toast-container"></div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="assets/js/dashboard.js"></script>
</body>
</html>
