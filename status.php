<?php
// =============================================================================
// status.php - Public status page (no login required)
// =============================================================================

define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/Statistics.php';
require_once MONITOR_ROOT . '/includes/StatusPage.php';

// Handle subscriber actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscribe_email'])) {
    $email = trim($_POST['subscribe_email'] ?? '');
    $ok    = StatusPage::addSubscriber($email);
    $subMsg = $ok ? 'success' : 'error';
    header('Location: status.php?sub=' . $subMsg);
    exit;
}

if (isset($_GET['unsubscribe'])) {
    StatusPage::removeSubscriber($_GET['unsubscribe']);
    $unsubDone = true;
}

$config   = StatusPage::getConfig();

if (!$config['is_public']) {
    http_response_code(403);
    die('Status page is not public.');
}

$sites     = StatusPage::getPublicSites();
$incidents = StatusPage::getRecentIncidents(10);
$health    = Statistics::getSystemHealth();

$allUp     = $health['sites_down'] === 0;
$accent    = htmlspecialchars($config['accent_color'] ?? '#3b82f6');
$title     = htmlspecialchars($config['title'] ?? 'Service Status');
$desc      = htmlspecialchars($config['description'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $title ?></title>
  <meta name="description" content="<?= $desc ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root { --accent: <?= $accent ?>; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', system-ui, sans-serif; background: #f8fafc; color: #1e293b; font-size: 14px; line-height: 1.6; }
    a { color: var(--accent); text-decoration: none; }
    .container { max-width: 860px; margin: 0 auto; padding: 0 20px; }

    /* Header */
    .header { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 20px 0; }
    .header-inner { display: flex; align-items: center; justify-content: space-between; }
    .header-logo { font-size: 20px; font-weight: 800; color: #0f172a; }
    .header-logo span { color: var(--accent); }
    .header-desc { font-size: 13px; color: #64748b; margin-top: 2px; }

    /* Overall status banner */
    .status-banner {
      padding: 28px 0;
      text-align: center;
    }
    .status-banner-inner {
      display: inline-flex; align-items: center; gap: 12px;
      background: #fff; border: 1px solid #e2e8f0;
      border-radius: 12px; padding: 16px 28px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .status-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
    .status-dot.green { background: #10b981; box-shadow: 0 0 8px #10b981; animation: pulse 2s infinite; }
    .status-dot.red   { background: #ef4444; box-shadow: 0 0 8px #ef4444; animation: pulse 1s infinite; }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.5} }
    .status-text { font-size: 18px; font-weight: 700; }
    .status-text.green { color: #10b981; }
    .status-text.red   { color: #ef4444; }

    /* Sections */
    .section { margin-bottom: 32px; }
    .section-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #64748b; margin-bottom: 12px; }

    /* Site rows */
    .site-row {
      background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
      padding: 14px 18px; margin-bottom: 8px;
      display: flex; align-items: center; gap: 14px;
    }
    .site-row:hover { border-color: #cbd5e1; }
    .site-status-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .site-status-dot.up      { background: #10b981; }
    .site-status-dot.down    { background: #ef4444; animation: pulse 1s infinite; }
    .site-status-dot.warning { background: #f59e0b; }
    .site-status-dot.unknown { background: #94a3b8; }
    .site-info { flex: 1; min-width: 0; }
    .site-name { font-weight: 600; font-size: 14px; }
    .site-url  { font-size: 11px; color: #94a3b8; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .site-meta { display: flex; align-items: center; gap: 16px; flex-shrink: 0; }
    .site-uptime { font-size: 13px; font-weight: 700; }
    .site-uptime.green  { color: #10b981; }
    .site-uptime.yellow { color: #f59e0b; }
    .site-uptime.red    { color: #ef4444; }
    .site-rt { font-size: 12px; color: #94a3b8; }
    .site-badge {
      font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 20px;
      text-transform: uppercase; letter-spacing: .04em;
    }
    .site-badge.up      { background: #d1fae5; color: #065f46; }
    .site-badge.down    { background: #fee2e2; color: #991b1b; }
    .site-badge.warning { background: #fef3c7; color: #92400e; }
    .site-badge.unknown { background: #f1f5f9; color: #64748b; }

    /* 90-day uptime blocks */
    .uptime-blocks { display: flex; gap: 2px; margin-top: 6px; }
    .uptime-block  { width: 6px; height: 16px; border-radius: 2px; background: #e2e8f0; flex-shrink: 0; }
    .uptime-block.up      { background: #10b981; }
    .uptime-block.partial { background: #f59e0b; }
    .uptime-block.down    { background: #ef4444; }
    .uptime-block.empty   { background: #e2e8f0; }

    /* Incidents */
    .incident-card {
      background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
      padding: 16px 18px; margin-bottom: 10px;
      border-left: 4px solid #ef4444;
    }
    .incident-card.resolved { border-left-color: #10b981; }
    .incident-title { font-weight: 600; font-size: 14px; }
    .incident-meta  { font-size: 12px; color: #64748b; margin-top: 4px; }
    .incident-error { font-size: 12px; color: #ef4444; margin-top: 4px; }

    /* Subscribe */
    .subscribe-box {
      background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
      padding: 20px 24px; text-align: center;
    }
    .subscribe-box h3 { font-size: 15px; font-weight: 700; margin-bottom: 6px; }
    .subscribe-box p  { font-size: 13px; color: #64748b; margin-bottom: 14px; }
    .subscribe-form { display: flex; gap: 8px; max-width: 400px; margin: 0 auto; }
    .subscribe-form input {
      flex: 1; padding: 9px 14px; border: 1px solid #e2e8f0; border-radius: 7px;
      font-size: 13px; outline: none; font-family: inherit;
    }
    .subscribe-form input:focus { border-color: var(--accent); }
    .subscribe-form button {
      padding: 9px 18px; background: var(--accent); color: #fff;
      border: none; border-radius: 7px; font-size: 13px; font-weight: 600;
      cursor: pointer; white-space: nowrap;
    }
    .alert-msg { padding: 10px 14px; border-radius: 7px; margin-bottom: 16px; font-size: 13px; }
    .alert-msg.success { background: #d1fae5; color: #065f46; }
    .alert-msg.error   { background: #fee2e2; color: #991b1b; }

    /* Footer */
    .footer { text-align: center; padding: 32px 0; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0; margin-top: 40px; }
    .footer a { color: var(--accent); }

    /* Responsive */
    @media (max-width: 600px) {
      .site-meta { flex-direction: column; align-items: flex-end; gap: 4px; }
      .subscribe-form { flex-direction: column; }
    }
  </style>
</head>
<body>

<div class="header">
  <div class="container">
    <div class="header-inner">
      <div>
        <div class="header-logo"><?= $title ?></div>
        <?php if ($desc): ?><div class="header-desc"><?= $desc ?></div><?php endif; ?>
      </div>
      <div style="font-size:12px;color:#94a3b8"><?= date('M d, Y H:i T') ?></div>
    </div>
  </div>
</div>

<div class="container">

  <!-- Overall status -->
  <div class="status-banner">
    <div class="status-banner-inner">
      <div class="status-dot <?= $allUp ? 'green' : 'red' ?>"></div>
      <div class="status-text <?= $allUp ? 'green' : 'red' ?>">
        <?= $allUp ? 'All Systems Operational' : $health['sites_down'] . ' Service(s) Disrupted' ?>
      </div>
    </div>
  </div>

  <?php if (isset($_GET['sub'])): ?>
    <div class="alert-msg <?= $_GET['sub'] === 'success' ? 'success' : 'error' ?>">
      <?= $_GET['sub'] === 'success' ? '✓ You\'ve been subscribed to status updates.' : '✗ Invalid email or already subscribed.' ?>
    </div>
  <?php endif; ?>

  <?php if (isset($unsubDone)): ?>
    <div class="alert-msg success">✓ You have been unsubscribed from status updates.</div>
  <?php endif; ?>

  <!-- Services -->
  <div class="section">
    <div class="section-title">Services (<?= count($sites) ?>)</div>
    <?php foreach ($sites as $s):
      $status   = $s['status'] ?? 'unknown';
      $uptime   = (float)($s['uptime_percentage'] ?? 100);
      $upCls    = $uptime >= 99 ? 'green' : ($uptime >= 95 ? 'yellow' : 'red');
      $rt       = $s['response_time'] ? round($s['response_time']) . 'ms' : '—';
      $daily    = StatusPage::getUptimeLast90Days((int)$s['id']);
      // Build 90 blocks
      $blockMap = [];
      foreach ($daily as $d) $blockMap[$d['date']] = $d;
      $blocks = '';
      for ($i = 89; $i >= 0; $i--) {
          $date = date('Y-m-d', strtotime("-$i days"));
          if (isset($blockMap[$date])) {
              $pct = (float)$blockMap[$date]['uptime_percentage'];
              $cls = $pct >= 99 ? 'up' : ($pct >= 90 ? 'partial' : 'down');
          } else {
              $cls = 'empty';
          }
          $blocks .= "<div class='uptime-block $cls' title='$date'></div>";
      }
    ?>
    <div class="site-row">
      <div class="site-status-dot <?= $status ?>"></div>
      <div class="site-info">
        <div class="site-name"><?= htmlspecialchars($s['name']) ?></div>
        <div class="site-url"><?= htmlspecialchars($s['url']) ?></div>
        <?php if ($config['show_values']): ?>
        <div class="uptime-blocks"><?= $blocks ?></div>
        <?php endif; ?>
      </div>
      <div class="site-meta">
        <?php if ($config['show_values']): ?>
          <span class="site-uptime <?= $upCls ?>"><?= $uptime ?>%</span>
          <span class="site-rt"><?= $rt ?></span>
        <?php endif; ?>
        <span class="site-badge <?= $status ?>"><?= $status ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Recent Incidents -->
  <?php if (!empty($incidents)): ?>
  <div class="section">
    <div class="section-title">Recent Incidents (Last 30 Days)</div>
    <?php foreach ($incidents as $i):
      $resolved = !empty($i['ended_at']);
      $dur = $i['duration_seconds'] ? round($i['duration_seconds'] / 60) . ' min' : 'Ongoing';
    ?>
    <div class="incident-card <?= $resolved ? 'resolved' : '' ?>">
      <div class="incident-title">
        <?= $resolved ? '✅' : '🔴' ?> <?= htmlspecialchars($i['site_name']) ?>
        — <?= $resolved ? 'Resolved' : 'Ongoing' ?>
      </div>
      <div class="incident-meta">
        Started: <?= htmlspecialchars($i['started_at']) ?>
        <?= $resolved ? ' · Resolved: ' . htmlspecialchars($i['ended_at']) : '' ?>
        · Duration: <?= $dur ?>
      </div>
      <?php if ($i['error_message']): ?>
        <div class="incident-error"><?= htmlspecialchars($i['error_message']) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Subscribe -->
  <div class="section">
    <div class="subscribe-box">
      <h3>📬 Subscribe to Updates</h3>
      <p>Get notified by email when incidents are created or resolved.</p>
      <form class="subscribe-form" method="POST">
        <input type="email" name="subscribe_email" placeholder="your@email.com" required>
        <button type="submit">Subscribe</button>
      </form>
    </div>
  </div>

</div>

<div class="footer">
  <div class="container">
    <?php if ($config['footer_text']): ?>
      <p><?= htmlspecialchars($config['footer_text']) ?></p>
    <?php endif; ?>
    <p>Powered by <a href="<?= htmlspecialchars(APP_URL) ?>">Site Monitor</a> · Updated every minute</p>
  </div>
</div>

</body>
</html>
