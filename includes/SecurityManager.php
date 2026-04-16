<?php
// =============================================================================
// includes/SecurityManager.php - Advanced security management and protection
// =============================================================================

class SecurityManager {
    private static array $blockedIPs = [];
    private static array $suspiciousPatterns = [
        '/union\s+select/i',
        '/drop\s+table/i',
        '/delete\s+from/i',
        '/insert\s+into/i',
        '/update\s+set/i',
        '/<script[^>]*>.*?<\/script>/si',
        '/javascript:/i',
        '/vbscript:/i',
        '/onload\s*=/i',
        '/onerror\s*=/i',
        '/onclick\s*=/i',
        '/<iframe[^>]*>/i',
        '/<object[^>]*>/i',
        '/<embed[^>]*>/i',
    ];
    
    public static function init(): void {
        self::loadBlockedIPs();
        self::setupSecurityHeaders();
        self::monitorSuspiciousActivity();
    }
    
    public static function isIPBlocked(string $ip): bool {
        return in_array($ip, self::$blockedIPs);
    }
    
    public static function blockIP(string $ip, string $reason = '', int $duration = 3600): void {
        $blockFile = sys_get_temp_dir() . '/monitor_blocked_ips.json';
        $blockedIPs = self::loadBlockedIPs();
        
        $blockedIPs[$ip] = [
            'blocked_at' => time(),
            'reason' => $reason,
            'duration' => $duration,
            'expires_at' => time() + $duration,
        ];
        
        file_put_contents($blockFile, json_encode($blockedIPs), LOCK_EX);
        self::$blockedIPs = array_keys($blockedIPs);
        
        Logger::securityEvent("IP blocked: {$ip}", ['reason' => $reason, 'duration' => $duration]);
    }
    
    public static function unblockIP(string $ip): void {
        $blockFile = sys_get_temp_dir() . '/monitor_blocked_ips.json';
        $blockedIPs = self::loadBlockedIPs();
        
        if (isset($blockedIPs[$ip])) {
            unset($blockedIPs[$ip]);
            file_put_contents($blockFile, json_encode($blockedIPs), LOCK_EX);
            self::$blockedIPs = array_keys($blockedIPs);
            
            Logger::securityEvent("IP unblocked: {$ip}");
        }
    }
    
    public static function validateInput(string $input, string $type = 'general'): string {
        if (empty($input)) {
            return $input;
        }
        
        // Check for suspicious patterns
        foreach (self::$suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                Logger::securityEvent("Suspicious input detected", [
                    'input' => substr($input, 0, 100),
                    'pattern' => $pattern,
                    'type' => $type,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                
                // Block IP for repeated suspicious activity
                $key = 'suspicious_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
                $count = Cache::increment($key, 1);
                Cache::set($key, $count, 300); // 5 minutes
                
                if ($count >= 3) {
                    self::blockIP($_SERVER['REMOTE_ADDR'] ?? '', 'Multiple suspicious inputs', 1800);
                }
                
                throw new SecurityException("Suspicious input detected");
            }
        }
        
        // Type-specific validation
        return match($type) {
            'email' => self::sanitizeEmail($input),
            'url' => self::sanitizeURL($input),
            'html' => self::sanitizeHTML($input),
            'sql' => self::sanitizeSQL($input),
            'filename' => self::sanitizeFilename($input),
            'general' => self::sanitizeGeneral($input),
            default => self::sanitizeGeneral($input),
        };
    }
    
    public static function checkRateLimitAdvanced(string $identifier, int $maxRequests = 60, int $windowSeconds = 60): bool {
        $key = "rate_limit_advanced_" . md5($identifier);
        $cacheData = Cache::get($key, ['requests' => 0, 'window_start' => time()]);
        
        // Reset window if expired
        if (time() - $cacheData['window_start'] >= $windowSeconds) {
            $cacheData = ['requests' => 0, 'window_start' => time()];
        }
        
        $cacheData['requests']++;
        
        if ($cacheData['requests'] > $maxRequests) {
            Logger::securityEvent("Rate limit exceeded", [
                'identifier' => $identifier,
                'requests' => $cacheData['requests'],
                'max_allowed' => $maxRequests,
                'window' => $windowSeconds
            ]);
            
            // Implement progressive blocking
            $blockKey = 'rate_block_' . md5($identifier);
            $blockCount = Cache::increment($blockKey, 1);
            
            if ($blockCount >= 3) {
                self::blockIP($_SERVER['REMOTE_ADDR'] ?? '', 'Repeated rate limit violations', 900);
            }
            
            return false;
        }
        
        Cache::set($key, $cacheData, $windowSeconds);
        return true;
    }
    
    public static function validateCSRFToken(string $token): bool {
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        
        $valid = hash_equals($_SESSION['csrf_token'], $token);
        
        if (!$valid) {
            Logger::securityEvent("Invalid CSRF token", [
                'provided_token' => substr($token, 0, 10) . '...',
                'session_token' => substr($_SESSION['csrf_token'], 0, 10) . '...',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        }
        
        return $valid;
    }
    
    public static function generateCSRFToken(): string {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
    
    public static function encryptData(string $data, string $key = null): string {
        $key = $key ?? self::getEncryptionKey();
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    public static function decryptData(string $encrypted, string $key = null): string {
        $key = $key ?? self::getEncryptionKey();
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = substr($data, openssl_cipher_iv_length('aes-256-cbc'));
        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    }
    
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }
    
    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }
    
    public static function checkSessionHijacking(): bool {
        if (!isset($_SESSION['ip']) || !isset($_SESSION['user_agent'])) {
            return false;
        }
        
        $currentIP = $_SERVER['REMOTE_ADDR'] ?? '';
        $currentUA = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $ipChanged = $_SESSION['ip'] !== $currentIP;
        $uaChanged = $_SESSION['user_agent'] !== $currentUA;
        
        if ($ipChanged || $uaChanged) {
            Logger::securityEvent("Session hijacking attempt", [
                'session_ip' => $_SESSION['ip'],
                'current_ip' => $currentIP,
                'session_ua' => substr($_SESSION['user_agent'], 0, 100),
                'current_ua' => substr($currentUA, 0, 100),
                'ip_changed' => $ipChanged,
                'ua_changed' => $uaChanged
            ]);
            
            return true;
        }
        
        return false;
    }
    
    public static function secureSession(): void {
        // Enhanced session security
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '7200');
        ini_set('session.sid_length', '32');
        ini_set('session.sid_bits_per_character', '6');
        
        session_start();
        
        // Regenerate session ID periodically
        if (!isset($_SESSION['last_regen']) || (time() - $_SESSION['last_regen']) > 1800) {
            session_regenerate_id(true);
            $_SESSION['last_regen'] = time();
        }
        
        // Store session fingerprint
        $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    
    private static function loadBlockedIPs(): array {
        $blockFile = sys_get_temp_dir() . '/monitor_blocked_ips.json';
        
        if (!file_exists($blockFile)) {
            self::$blockedIPs = [];
            return [];
        }
        
        $blockedIPs = json_decode(file_get_contents($blockFile), true) ?: [];
        
        // Clean expired blocks
        $now = time();
        foreach ($blockedIPs as $ip => $data) {
            if ($now > ($data['expires_at'] ?? 0)) {
                unset($blockedIPs[$ip]);
            }
        }
        
        // Update file with cleaned data
        file_put_contents($blockFile, json_encode($blockedIPs), LOCK_EX);
        self::$blockedIPs = array_keys($blockedIPs);
        
        return $blockedIPs;
    }
    
    private static function setupSecurityHeaders(): void {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
            
            $csp = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; connect-src 'self'; frame-ancestors 'none';";
            header('Content-Security-Policy: ' . $csp);
        }
    }
    
    private static function monitorSuspiciousActivity(): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        // Check for common attack patterns
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
        
        // Suspicious user agents
        $suspiciousUAs = [
            'sqlmap', 'nikto', 'dirb', 'nmap', 'masscan', 
            'zap', 'burp', 'w3af', 'acunetix', 'arachni'
        ];
        
        foreach ($suspiciousUAs as $ua) {
            if (stripos($userAgent, $ua) !== false) {
                self::blockIP($ip, "Suspicious user agent: {$ua}", 3600);
                return;
            }
        }
        
        // Suspicious request patterns
        $suspiciousPatterns = [
            '/\.\./',  // Directory traversal
            '/\/etc\//',  // System file access
            '/\/proc\//', // Process file access
            '/union.*select/i', // SQL injection
            '/<script/i', // XSS
            '/javascript:/i', // XSS
        ];
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $requestUri)) {
                self::blockIP($ip, "Suspicious request pattern", 1800);
                return;
            }
        }
    }
    
    private static function sanitizeEmail(string $email): string {
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
    
    private static function sanitizeURL(string $url): string {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
    
    private static function sanitizeHTML(string $html): string {
        return htmlspecialchars($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    private static function sanitizeSQL(string $sql): string {
        // Remove SQL keywords and special characters
        $sql = preg_replace('/[\'";]/', '', $sql);
        $sql = preg_replace('/\b(union|select|drop|delete|insert|update|create|alter|exec|execute)\b/i', '', $sql);
        return trim($sql);
    }
    
    private static function sanitizeFilename(string $filename): string {
        // Remove directory traversal and special characters
        $filename = preg_replace('/[\/\\\\]/', '', $filename);
        $filename = preg_replace('/[<>:"|?*]/', '', $filename);
        return basename($filename);
    }
    
    private static function sanitizeGeneral(string $input): string {
        return htmlspecialchars(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    private static function getEncryptionKey(): string {
        // Use environment variable or generate a persistent key
        $keyFile = sys_get_temp_dir() . '/monitor_encryption.key';
        
        if (file_exists($keyFile)) {
            return file_get_contents($keyFile);
        }
        
        // Generate new key if none exists
        $key = random_bytes(32);
        file_put_contents($keyFile, $key, 0600);
        return $key;
    }
    
    public static function getSecurityStats(): array {
        return [
            'blocked_ips' => count(self::$blockedIPs),
            'blocked_ips_list' => self::$blockedIPs,
            'security_headers' => headers_list(),
            'php_version' => PHP_VERSION,
            'openssl_version' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'Unknown',
        ];
    }
}

class SecurityException extends Exception {
    public function __construct(string $message, int $code = 0, Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}
