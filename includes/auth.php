<?php
// =============================================================================
// includes/auth.php - Session-based auth guard
// =============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin(): void {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: login.php' . ($redirect ? '?redirect=' . $redirect : ''));
        exit;
    }
}

function attemptLogin(string $user, string $pass): bool {
    if ($user !== DASHBOARD_USER) return false;
    return password_verify($pass, DASHBOARD_PASS);
}
