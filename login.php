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
    body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--bg); transition: background .3s; }
    .login-box { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:40px; width:100%; max-width:380px; transition: background .3s, border-color .3s; }
    .login-logo { display:flex; align-items:center; gap:10px; margin-bottom:28px; }
    .login-logo svg { width:28px; height:28px; color:var(--blue); }
    .login-logo span { font-size:20px; font-weight:700; color:var(--text); }
    .login-title { font-size:22px; font-weight:700; margin-bottom:6px; color:var(--text); }
    .login-sub { color:var(--muted); font-size:13px; margin-bottom:24px; }
    .error-msg { background:rgba(239,68,68,0.12); border:1px solid var(--red); color:var(--red); border-radius:6px; padding:10px 14px; font-size:13px; margin-bottom:16px; }
  </style>
  <script>
    const saved = localStorage.getItem('theme') || 'dark';
    if (saved === 'light') document.documentElement.classList.add('light-theme');
  </script>
</head>
<body>
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
