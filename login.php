<?php
// =============================================================================
// login.php - Login page
// =============================================================================

define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in
if (!empty($_SESSION['logged_in'])) {
    header('Location: index.php');
    exit;
}

$error    = '';
$redirect = preg_replace('/[^a-zA-Z0-9\/_\-\.?=]/', '', $_GET['redirect'] ?? 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!hash_equals($_SESSION['login_token'] ?? '', $_POST['login_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } elseif (attemptLogin(trim($_POST['username'] ?? ''), $_POST['password'] ?? '')) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        $_SESSION['user']      = trim($_POST['username']);
        unset($_SESSION['login_token']);
        header('Location: ' . $redirect);
        exit;
    } else {
        // Slow down brute force
        sleep(1);
        $error = 'Invalid username or password.';
    }
}

// Generate login CSRF token
$_SESSION['login_token'] = bin2hex(random_bytes(32));
$token = $_SESSION['login_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — Site Monitor</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/dashboard.css">
  <style>
    html, body { height:100%; margin:0; font-family:'Inter',system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif; }
    body { display:grid; place-items:center; min-height:100vh; overflow:hidden; background:linear-gradient(160deg, #0f172a 0%, #020617 100%); color:#fff; }
    #bg-video {
      position:fixed; inset:0; width:100%; height:100%; object-fit:cover; z-index:-3; filter:brightness(0.42) saturate(1.2);
    }
    #bg-overlay {
      position:fixed; inset:0; z-index:-2;
      background:linear-gradient(120deg, rgba(8,11,24,.45), rgba(18,22,50,.68));
      pointer-events:none;
    }
    .login-box {
      position:relative;
      background:rgba(7, 17, 40, 0.72);
      border:1px solid rgba(255,255,255,0.14);
      border-radius:20px;
      padding:36px;
      width:95%;
      max-width:420px;
      box-shadow:0 18px 35px rgba(0,0,0,0.35);
      backdrop-filter:blur(12px);
      -webkit-backdrop-filter:blur(12px);
      transition:transform .25s ease, border-color .25s ease, box-shadow .25s ease;
    }
    .login-box:hover { transform:translateY(-2px); border-color:rgba(94,206,255,0.45); box-shadow:0 26px 40px rgba(0,0,0,0.45); }
    .login-logo { display:flex; align-items:center; gap:10px; margin-bottom:24px; }
    .login-logo svg { width:28px; height:28px; color:#60a5fa; }
    .login-logo span { font-size:20px; font-weight:700; color:#e2e8f0; }
    .login-title { font-size:26px; font-weight:700; margin-bottom:6px; color:#fff; }
    .login-sub { color:#cbd5e1; font-size:14px; margin-bottom:24px; }
    .error-msg { background:rgba(248,113,113,0.14); border:1px solid rgba(239,68,68,0.48); color:#fecaca; border-radius:8px; padding:10px 14px; font-size:13px; margin-bottom:16px; }
    .form-group label { color:#cbd5e1; font-size:14px; margin-bottom:6px; display:block; }
    .form-control { width:100%; padding:10px 12px; border:1px solid rgba(148,163,184,.32); border-radius:8px; background:rgba(15, 27, 55, 0.68); color:#e2e8f0; margin-bottom:16px; outline:none; transition:border-color .2s ease, box-shadow .2s ease; }
    .form-control:focus { border-color:#60a5fa; box-shadow:0 0 0 2px rgba(96,165,250,0.22); }
    .btn-primary { width:100%; padding:11px 14px; background:#0ea5e9; border:none; border-radius:8px; font-weight:700; color:#fff; cursor:pointer; transition:transform .2s ease, background .2s ease; }
    .btn-primary:hover { background:#38bdf8; transform:translateY(-1px); }
  </style>
  <script>
    const saved = localStorage.getItem('theme') || 'dark';
    if (saved === 'light') document.documentElement.classList.add('light-theme');
  </script>
</head>
<body>
  <video id="bg-video" autoplay playsinline muted loop>
    <source src="https://cdn.coverr.co/videos/coverr-modern-city-at-night-2447/1080p.mp4" type="video/mp4">
    Your browser does not support the video tag.
  </video>
  <div id="bg-overlay"></div>
  <div class="login-box">
  <div class="login-logo">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
    </svg>
    <span>Monitor</span>
  </div>
  <div class="login-title">Sign in</div>
  <div class="login-sub">Enter your credentials to access the dashboard.</div>

  <?php if ($error): ?>
  <div class="error-msg"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="login.php<?= $redirect !== 'index.php' ? '?redirect=' . urlencode($redirect) : '' ?>">
    <input type="hidden" name="login_token" value="<?= htmlspecialchars($token) ?>">
    <div class="form-group">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" class="form-control" required
             autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px">Sign In</button>
  </form>
</div>
</body>
</html>
