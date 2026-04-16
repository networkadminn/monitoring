<?php
// =============================================================================
// includes/Logger.php - Comprehensive logging and monitoring system
// =============================================================================

class Logger {
    private static string $logDir;
    private static array $logLevels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4,
    ];
    
    public static function init(): void {
        self::$logDir = __DIR__ . '/../logs';
        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
    }
    
    public static function debug(string $message, array $context = []): void {
        self::log('DEBUG', $message, $context);
    }
    
    public static function info(string $message, array $context = []): void {
        self::log('INFO', $message, $context);
    }
    
    public static function warning(string $message, array $context = []): void {
        self::log('WARNING', $message, $context);
    }
    
    public static function error(string $message, array $context = []): void {
        self::log('ERROR', $message, $context);
    }
    
    public static function critical(string $message, array $context = []): void {
        self::log('CRITICAL', $message, $context);
    }
    
    public static function checkResult(int $siteId, array $site, array $result): void {
        $context = [
            'site_id' => $siteId,
            'site_name' => $site['name'] ?? 'Unknown',
            'site_url' => $site['url'] ?? '',
            'check_type' => $site['check_type'] ?? 'unknown',
            'status' => $result['status'],
            'response_time' => $result['response_time'],
            'error_message' => $result['error_message'],
            'error_category' => $result['error_category'] ?? null,
            'retry_count' => $result['retry_count'] ?? 0,
            'circuit_breaker_state' => $result['circuit_breaker_state'] ?? null,
        ];
        
        if ($result['status'] === 'up') {
            self::info("Site check successful", $context);
        } else {
            self::error("Site check failed", $context);
        }
        
        // Performance logging
        if (isset($result['performance']) && !empty($result['performance'])) {
            $context['performance'] = $result['performance'];
            self::debug("Performance metrics", $context);
        }
    }
    
    public static function circuitBreakerEvent(int $siteId, string $event, array $state): void {
        $context = [
            'site_id' => $siteId,
            'event' => $event,
            'state' => $state,
        ];
        
        self::warning("Circuit breaker event: {$event}", $context);
    }
    
    public static function connectionPoolEvent(string $event, array $details): void {
        $context = [
            'event' => $event,
            'details' => $details,
        ];
        
        self::debug("Connection pool event: {$event}", $context);
    }
    
    public static function securityEvent(string $event, array $context): void {
        $context['event_type'] = 'security';
        $context['timestamp'] = date('Y-m-d H:i:s');
        $context['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $context['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        self::warning("Security event: {$event}", $context);
        
        // Also log to separate security log
        self::writeToFile('security', self::formatLogEntry('WARNING', $event, $context));
    }
    
    public static function apiRequest(string $endpoint, string $method, int $responseCode, float $responseTime): void {
        $context = [
            'endpoint' => $endpoint,
            'method' => $method,
            'response_code' => $responseCode,
            'response_time_ms' => round($responseTime * 1000, 2),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ];
        
        $level = $responseCode >= 400 ? 'WARNING' : 'INFO';
        self::log($level, "API request: {$method} {$endpoint}", $context);
    }
    
    public static function systemMetrics(): void {
        $context = [
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'cpu_load' => sys_getloadavg()[0] ?? 0,
            'disk_usage' => self::getDiskUsage(),
            'active_connections' => function_exists('ConnectionPool') ? ConnectionPool::getStats()['active_connections'] : 0,
        ];
        
        self::debug("System metrics", $context);
    }
    
    private static function log(string $level, string $message, array $context = []): void {
        if (!isset(self::$logLevels[$level])) {
            return;
        }
        
        // Check if we should log this level based on configuration
        $minLevel = defined('LOG_LEVEL') ? LOG_LEVEL : 'INFO';
        if (self::$logLevels[$level] < self::$logLevels[$minLevel]) {
            return;
        }
        
        $formatted = self::formatLogEntry($level, $message, $context);
        self::writeToFile('monitor', $formatted);
        
        // Also write to error log for critical issues
        if ($level === 'CRITICAL') {
            error_log($formatted);
        }
    }
    
    private static function formatLogEntry(string $level, string $message, array $context): string {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        
        return "[{$timestamp}] {$level}: {$message}{$contextStr}";
    }
    
    private static function writeToFile(string $type, string $message): void {
        self::init();
        $filename = self::$logDir . "/{$type}_" . date('Y-m-d') . '.log';
        
        // Rotate logs if they get too large (>10MB)
        if (file_exists($filename) && filesize($filename) > 10 * 1024 * 1024) {
            $rotated = str_replace('.log', '_' . date('H-i-s') . '.log', $filename);
            rename($filename, $rotated);
        }
        
        file_put_contents($filename, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    
    private static function getDiskUsage(): array {
        $path = __DIR__ . '/..';
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;
        
        return [
            'total_bytes' => $total,
            'used_bytes' => $used,
            'free_bytes' => $free,
            'usage_percent' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
        ];
    }
    
    public static function cleanupOldLogs(int $days = 30): void {
        self::init();
        $files = glob(self::$logDir . '/*.log');
        $cutoff = time() - ($days * 24 * 60 * 60);
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
    
    public static function getLogStats(): array {
        self::init();
        $files = glob(self::$logDir . '/*.log');
        $stats = [
            'total_files' => count($files),
            'total_size' => 0,
            'files' => [],
        ];
        
        foreach ($files as $file) {
            $size = filesize($file);
            $stats['total_size'] += $size;
            $stats['files'][] = [
                'name' => basename($file),
                'size' => $size,
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }
        
        return $stats;
    }
}
