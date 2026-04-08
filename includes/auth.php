<?php
// =============================================================================
// includes/auth.php - Production-grade session auth with brute-force protection
// =============================================================================

if (session_status() === PHP_SESSION_NONE) {
    // Hardened session config
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '7200'); // 2 hours
    session_start();
}

// ── Session fixation protection ───────────────────────────────────────────
function requireLogin(): void {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: login.php' . ($redirect ? '?redirect=' . $redirect : ''));
        exit;
    }

    // Regenerate session ID every 30 minutes to prevent fixation
    if (!isset($_SESSION['last_regen']) || (time() - $_SESSION['last_regen']) > 1800) {
        session_regenerate_id(true);
        $_SESSION['last_regen'] = time();
    }

    // IP binding — detect session hijacking
    $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (isset($_SESSION['ip']) && $_SESSION['ip'] !== $currentIp) {
        session_destroy();
        header('Location: login.php?reason=security');
        exit;
    }
    $_SESSION['ip'] = $currentIp;
}

// ── Login attempt with brute-force protection ─────────────────────────────
function attemptLogin(string $user, string $pass): bool {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login_attempts_' . md5($ip);

    // Check lockout
    if (isLockedOut($key)) {
        return false;
    }

    if ($user !== DASHBOARD_USER) {
        recordFailedAttempt($key);
        return false;
    }

    if (!password_verify($pass, DASHBOARD_PASS)) {
        recordFailedAttempt($key);
        return false;
    }

    // Success — clear attempts
    clearLoginAttempts($key);
    return true;
}

function isLockedOut(string $key): bool {
    $cacheFile = sys_get_temp_dir() . '/monitor_' . $key . '.json';
    if (!file_exists($cacheFile)) return false;

    $data = json_decode(file_get_contents($cacheFile), true);
    if (!$data) return false;

    // Reset after 15 minutes
    if (time() - ($data['first_attempt'] ?? 0) > 900) {
        @unlink($cacheFile);
        return false;
    }

    return ($data['count'] ?? 0) >= 5; // 5 attempts max
}

function recordFailedAttempt(string $key): void {
    $cacheFile = sys_get_temp_dir() . '/monitor_' . $key . '.json';
    $data = ['count' => 1, 'first_attempt' => time()];

    if (file_exists($cacheFile)) {
        $existing = json_decode(file_get_contents($cacheFile), true);
        if ($existing && (time() - ($existing['first_attempt'] ?? 0)) < 900) {
            $data = ['count' => ($existing['count'] ?? 0) + 1, 'first_attempt' => $existing['first_attempt']];
        }
    }

    file_put_contents($cacheFile, json_encode($data), LOCK_EX);
}

function clearLoginAttempts(string $key): void {
    $cacheFile = sys_get_temp_dir() . '/monitor_' . $key . '.json';
    @unlink($cacheFile);
}

function getRemainingAttempts(string $ip): int {
    $key      = 'login_attempts_' . md5($ip);
    $cacheFile = sys_get_temp_dir() . '/monitor_' . $key . '.json';
    if (!file_exists($cacheFile)) return 5;
    $data = json_decode(file_get_contents($cacheFile), true);
    return max(0, 5 - ($data['count'] ?? 0));
}

function isIpLockedOut(string $ip): bool {
    return isLockedOut('login_attempts_' . md5($ip));
}
