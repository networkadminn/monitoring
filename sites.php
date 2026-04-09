<?php
define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/Statistics.php';
require_once MONITOR_ROOT . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$userInitial = strtoupper(substr($_SESSION['user'] ?? 'A', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <title>Monitors — Site Monitor</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="assets/css/dashboard.css?v=1.0.8">
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
      <a class="nav-item active" id="nav-all" href="sites.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        <span>All Monitors</span>
      </a>
      <a class="nav-item" id="nav-websites" href="sites.php?type=websites" style="padding-left:32px;font-size:12px;opacity:0.8">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        <span>Websites</span>
      </a>
      <a class="nav-item" id="nav-ssl" href="sites.php?type=ssl" style="padding-left:32px;font-size:12px;opacity:0.8">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <span>SSL Certs</span>
      </a>
      <a class="nav-item" id="nav-ports" href="sites.php?type=ports" style="padding-left:32px;font-size:12px;opacity:0.8">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
        <span>Port Checks</span>
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
        <div class="topbar-title">Manage Monitors</div>
        <div class="last-updated" id="last-updated"></div>
      </div>
      <div class="topbar-center">
        <div class="filter-controls">
          <!-- Search -->
          <div class="search-wrap">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="site-search" placeholder="Search monitors..." autocomplete="off">
          </div>
          
          <!-- Status Filter -->
          <select id="status-filter" class="btn btn-ghost btn-sm" style="margin-left:8px">
            <option value="">All Status</option>
            <option value="up">Up Only</option>
            <option value="down">Down Only</option>
            <option value="warning">Warning</option>
            <option value="checking">Checking</option>
          </select>
          
          <!-- Type Filter -->
          <select id="type-filter" class="btn btn-ghost btn-sm" style="margin-left:8px">
            <option value="">All Types</option>
            <option value="http">HTTP/HTTPS</option>
            <option value="ssl">SSL Certificate</option>
            <option value="port">Port Check</option>
            <option value="keyword">Keyword Check</option>
            <option value="ping">Ping</option>
          </select>
          
          <!-- Response Time Filter -->
          <select id="response-filter" class="btn btn-ghost btn-sm" style="margin-left:8px">
            <option value="">All Response Times</option>
            <option value="fast">Fast (&lt;200ms)</option>
            <option value="medium">Medium (200-500ms)</option>
            <option value="slow">Slow (&gt;500ms)</option>
          </select>
          
          <!-- Tag Filter -->
          <select id="tag-filter" class="btn btn-ghost btn-sm" style="margin-left:8px">
            <option value="">All Tags</option>
            <option value="critical">Critical</option>
            <option value="production">Production</option>
            <option value="staging">Staging</option>
            <option value="development">Development</option>
            <option value="external">External</option>
            <option value="internal">Internal</option>
          </select>
          
          <!-- Clear Filters -->
          <button id="clear-filters" class="btn btn-ghost btn-sm" style="margin-left:8px" title="Clear all filters">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
      </div>
      <div class="topbar-actions">
        <button class="btn btn-ghost btn-icon btn-sm" id="btn-theme-toggle" title="Toggle Day/Night Mode">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="sun-icon"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="moon-icon" style="display:none"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        </button>
        <button class="btn btn-ghost btn-sm" id="btn-refresh">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Refresh
        </button>
        <button class="btn btn-ghost btn-sm" id="btn-run-cron">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          Run Check
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

    <!-- Page content -->
    <div class="page-content">

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

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<!-- Modals -->
<?php require_once 'includes/modals.php'; ?>

<div id="toast-container"></div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="assets/js/dashboard.js?v=1.0.9"></script>
  <script>
    console.log('Sites page script block executed');
    document.addEventListener('DOMContentLoaded', () => {
      console.log('DOMContentLoaded in sites.php');
      if (document.getElementById('sites-table')) {
        console.log('sites-table element found');
        
        // Advanced filtering functionality
        const statusFilter = document.getElementById('status-filter');
        const typeFilter = document.getElementById('type-filter');
        const responseFilter = document.getElementById('response-filter');
        const tagFilter = document.getElementById('tag-filter');
        const clearFilters = document.getElementById('clear-filters');
        const siteSearch = document.getElementById('site-search');
        
        // Apply filters to DataTable
        function applyFilters() {
          const table = $('#sites-table').DataTable();
          
          // Status filter
          if (statusFilter && statusFilter.value) {
            table.column(3).search(statusFilter.value === 'up' ? '^up$' : 
                                              statusFilter.value === 'down' ? '^down$' : 
                                              statusFilter.value === 'warning' ? '^warning$' : 
                                              statusFilter.value === 'checking' ? '^checking$' : '', true, false).draw();
          }
          
          // Type filter
          if (typeFilter && typeFilter.value) {
            table.column(2).search(typeFilter.value, true, false).draw();
          }
          
          // Response time filter
          if (responseFilter && responseFilter.value) {
            const searchTerms = {
              'fast': '^[0-9]{1,3}$',
              'medium': '^[2-9][0-9]{2}$|^[1-4][0-9]{3}$|500',
              'slow': '^[5-9][0-9]{2}$|^[1-9][0-9]{3,}$'
            };
            table.column(4).search(searchTerms[responseFilter.value] || '', true, false).draw();
          }
          
          // Tag filter
          if (tagFilter && tagFilter.value) {
            table.column(8).search(tagFilter.value, true, false).draw();
          }
        }
        
        // Add event listeners
        if (statusFilter) {
          statusFilter.addEventListener('change', applyFilters);
        }
        
        if (typeFilter) {
          typeFilter.addEventListener('change', applyFilters);
        }
        
        if (responseFilter) {
          responseFilter.addEventListener('change', applyFilters);
        }
        
        if (tagFilter) {
          tagFilter.addEventListener('change', applyFilters);
        }
        
        if (clearFilters) {
          clearFilters.addEventListener('click', () => {
            if (statusFilter) statusFilter.value = '';
            if (typeFilter) typeFilter.value = '';
            if (responseFilter) responseFilter.value = '';
            if (tagFilter) tagFilter.value = '';
            if (siteSearch) siteSearch.value = '';
            
            const table = $('#sites-table').DataTable();
            table.search('').columns().search('').draw();
            
            showToast('Filters cleared', 'success');
          });
        }
        
        // Enhanced search with debouncing
        if (siteSearch) {
          let searchTimeout;
          siteSearch.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
              const table = $('#sites-table').DataTable();
              table.search(e.target.value).draw();
            }, 300);
          });
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
          // Ctrl/Cmd + F to focus search
          if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            e.preventDefault();
            siteSearch?.focus();
          }
          
          // Escape to clear filters
          if (e.key === 'Escape') {
            clearFilters?.click();
          }
        });
        
        // Filter indicators in URL
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('status')) {
          statusFilter.value = urlParams.get('status');
          applyFilters();
        }
        if (urlParams.get('type')) {
          typeFilter.value = urlParams.get('type');
          applyFilters();
        }
        
      } else {
        console.warn('sites-table element NOT found!');
      }
    });
  </script>
</body>
</html>
