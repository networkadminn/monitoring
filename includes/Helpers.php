<?php
// =============================================================================
// includes/Helpers.php - Shared utility functions
// =============================================================================

/**
 * Validate email address according to RFC 5321/5322 standards
 * 
 * @param string $email Email address to validate
 * @return bool True if valid, false otherwise
 */
if (!function_exists('validateEmail')) {
    function validateEmail(string $email): bool {
    // Trim whitespace
    $email = trim($email);

    // Check length (RFC 5321 limit is 254 characters)
    if (strlen($email) > 254 || strlen($email) < 3) {
        return false;
    }

    // Basic format check - must contain exactly one @
    if (substr_count($email, '@') !== 1) {
        return false;
    }

    // Split into local and domain parts
    $atPos = strpos($email, '@');
    $local = substr($email, 0, $atPos);
    $domain = substr($email, $atPos + 1);

    // Check local and domain are not empty
    if (empty($local) || empty($domain)) {
        return false;
    }

    // Local part length check (RFC 5321 - max 64 characters)
    if (strlen($local) > 64) {
        return false;
    }

    // Domain length check
    if (strlen($domain) > 253) {
        return false;
    }

    // Check for consecutive dots
    if (strpos($email, '..') !== false) {
        return false;
    }

    // Local part cannot start or end with dot
    if ($local[0] === '.' || $local[strlen($local) - 1] === '.') {
        return false;
    }

    // Domain cannot start or end with dot or hyphen
    if ($domain[0] === '.' || $domain[0] === '-' ||
        $domain[strlen($domain) - 1] === '.' || $domain[strlen($domain) - 1] === '-') {
        return false;
    }

    // Check for invalid characters in domain (only allow valid domain chars)
    if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/i', $domain)) {
        return false;
    }

    // Comprehensive local part validation (RFC 5322 compliant)
    // This allows: letters, digits, and special chars: !#$%&'*+-/=?^_`{|}~
    // Local part can be quoted or unquoted
    $localRegex = '/^(?:(?:[a-zA-Z0-9!#$%&\'*+\-\/=?^_`{|}~]+(?:\.[a-zA-Z0-9!#$%&\'*+\-\/=?^_`{|}~]+)*)|(?:"(?:\\\\[\x00-\x7F]|[^\\\\"])*"))$/';

    return (bool) preg_match($localRegex, $local);
    }
}

/**
 * Basic rate limiting check using file-based counter
 * Prevents rapid-fire requests from same IP/user
 * 
 * @param string $identifier Unique identifier (IP, user ID, etc)
 * @param int $maxRequests Maximum requests allowed
 * @param int $timeWindow Time window in seconds
 * @return bool True if request is allowed, false if rate limited
 */
if (!function_exists('checkRateLimit')) {
    function checkRateLimit(string $identifier, int $maxRequests = 10, int $timeWindow = 60): bool {
    $cacheDir = sys_get_temp_dir() . '/monitor_rate_limits';
    @mkdir($cacheDir, 0755, true);
    
    $limitFile = $cacheDir . '/' . hash('md5', $identifier) . '.txt';
    $now = time();
    
    if (!file_exists($limitFile)) {
        file_put_contents($limitFile, $now . "\n");
        return true;
    }
    
    $timestamps = array_filter(explode("\n", file_get_contents($limitFile)));
    $recent = array_filter($timestamps, fn($ts) => ($now - (int)$ts) < $timeWindow);
    
    if (count($recent) >= $maxRequests) {
        return false;
    }
    
    file_put_contents($limitFile, implode("\n", [...$recent, $now]) . "\n");
    return true;
    }
}

/**
 * Log message to both console and file
 * 
 * @param string $message Message to log
 * @param string $level Log level (info, warn, error, debug)
 * @param string $logFile Optional log file path
 */
if (!function_exists('logMessage')) {
    function logMessage(string $message, string $level = 'info', string $logFile = null): void {
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[$timestamp] [$level] $message";
    
    echo $formatted . PHP_EOL;
    
    if ($logFile && is_writable(dirname($logFile))) {
        file_put_contents($logFile, $formatted . PHP_EOL, FILE_APPEND);
    }
    }
}

/**
 * Safely execute command with timeout and error handling
 * 
 * @param callable $fn Function to execute
 * @param int $timeout Timeout in seconds
 * @return array Result array with 'success' and 'result'/'error' keys
 */
if (!function_exists('safeExecute')) {
    function safeExecute(callable $fn, int $timeout = 30): array {
    try {
        $result = $fn();
        return ['success' => true, 'result' => $result];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
    }
}
