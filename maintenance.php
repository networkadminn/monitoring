<?php
define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/auth.php';
require_once MONITOR_ROOT . '/includes/MaintenanceWindow.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$sites    = Database::fetchAll("SELECT id, name FROM sites WHERE is_active = 1 ORDER BY name ASC");
$windows  = MaintenanceWindow::getUpcoming(50);
$userInitial = strtoupper(substr($_SESSION['user'] ?? 'A', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <title>Maintenance Windows — Site Monitor</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/dashboard.css?v=2.0">
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="sidebar-logo">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
      <div class="sidebar-logo-text">Site<span>Monitor</span></div>
    </div>
    <nav>
      <a class="nav-item" href="index.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        <span>Dashboard</span>
      </a>
      <a class="nav-item" href="sites.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        <span>Monitors</span>
      </a>
      <a class="nav-item active" href="maintenance.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span>Maintenance</span>
      </a>
      <a class="nav-item" href="status.php" target="_blank">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span>Status Page</span>
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
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </a>
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <div class="topbar-title">Maintenance Windows</div>
      </div>
      <div class="topbar-actions">
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('mw-modal').classList.add('open')">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Schedule Maintenance
        </button>
      </div>
    </div>

    <div class="page-content">
      <p style="color:var(--muted);margin-bottom:20px;font-size:13px">
        During a maintenance window, alerts are suppressed for the selected monitor. Checks still run but no notifications are sent.
      </p>

      <div class="table-panel">
        <div class="table-panel-header">
          <div class="table-panel-title">Scheduled & Active Windows</div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Monitor</th>
                <th>Title</th>
                <th>Start</th>
                <th>End</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($windows)): ?>
              <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--muted)">No maintenance windows scheduled</td></tr>
              <?php else: foreach ($windows as $w):
                $now = time();
                $start = strtotime($w['start_time']);
                $end   = strtotime($w['end_time']);
                $isActive = $now >= $start && $now <= $end;
                $isPast   = $now > $end;
              ?>
              <tr>
                <td><a href="site_details.php?id=<?= $w['site_id'] ?>" class="site-name-link"><?= htmlspecialchars($w['site_name']) ?></a></td>
                <td><?= htmlspecialchars($w['title']) ?></td>
                <td style="font-size:12px"><?= htmlspecialchars($w['start_time']) ?></td>
                <td style="font-size:12px"><?= htmlspecialchars($w['end_time']) ?></td>
                <td>
                  <?php if ($isActive): ?>
                    <span class="badge warning"><span class="badge-dot"></span>Active</span>
                  <?php elseif ($isPast): ?>
                    <span class="badge up"><span class="badge-dot"></span>Completed</span>
                  <?php else: ?>
                    <span class="badge unknown"><span class="badge-dot"></span>Scheduled</span>
                  <?php endif; ?>
                </td>
                <td>
                  <button class="btn btn-danger btn-sm" onclick="deleteMW(<?= $w['id'] ?>)">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                    Delete
                  </button>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Add Maintenance Window Modal -->
<div class="modal-overlay" id="mw-modal">
  <div class="modal" style="width:480px">
    <div class="modal-header">
      <h3>Schedule Maintenance Window</h3>
      <button class="modal-close" onclick="document.getElementById('mw-modal').classList.remove('open')">&times;</button>
    </div>
    <div class="modal-body">
      <form id="mw-form">
        <div class="form-group">
          <label>Monitor *</label>
          <select id="mw-site" class="form-control">
            <option value="">Select a monitor...</option>
            <?php foreach ($sites as $s): ?>
            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Title *</label>
          <input type="text" id="mw-title" class="form-control" placeholder="Scheduled deployment v2.1">
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea id="mw-desc" class="form-control" rows="2" placeholder="Optional details..."></textarea>
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label>Start Time *</label>
            <input type="datetime-local" id="mw-start" class="form-control">
          </div>
          <div class="form-group">
            <label>End Time *</label>
            <input type="datetime-local" id="mw-end" class="form-control">
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="document.getElementById('mw-modal').classList.remove('open')">Cancel</button>
      <button class="btn btn-primary" onclick="saveMW()">Schedule</button>
    </div>
  </div>
</div>

<div id="toast-container"></div>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="assets/js/dashboard.js?v=2.0"></script>
<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;

async function saveMW() {
  const siteId = document.getElementById('mw-site').value;
  const title  = document.getElementById('mw-title').value.trim();
  const start  = document.getElementById('mw-start').value;
  const end    = document.getElementById('mw-end').value;
  const desc   = document.getElementById('mw-desc').value.trim();

  if (!siteId || !title || !start || !end) {
    showToast('Please fill all required fields', 'error'); return;
  }
  if (new Date(end) <= new Date(start)) {
    showToast('End time must be after start time', 'error'); return;
  }

  try {
    await fetch('api.php?action=add_maintenance', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ site_id: siteId, title, description: desc, start_time: start, end_time: end })
    }).then(r => r.json()).then(j => { if (!j.success) throw new Error(j.error); });
    showToast('Maintenance window scheduled', 'success');
    document.getElementById('mw-modal').classList.remove('open');
    setTimeout(() => location.reload(), 800);
  } catch (e) {
    showToast('Error: ' + e.message, 'error');
  }
}

async function deleteMW(id) {
  if (!confirm('Delete this maintenance window?')) return;
  try {
    await fetch('api.php?action=delete_maintenance&id=' + id, {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrf },
      body: JSON.stringify({})
    }).then(r => r.json()).then(j => { if (!j.success) throw new Error(j.error); });
    showToast('Deleted', 'success');
    setTimeout(() => location.reload(), 600);
  } catch (e) {
    showToast('Error: ' + e.message, 'error');
  }
}
</script>
</body>
</html>
