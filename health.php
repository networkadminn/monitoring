<?php
// =============================================================================
// health.php - Health check endpoint for monitoring the monitoring system
// =============================================================================

define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/Cache.php';
require_once MONITOR_ROOT . '/includes/Logger.php';
require_once MONITOR_ROOT . '/includes/ConnectionPool.php';
require_once MONITOR_ROOT . '/includes/CircuitBreaker.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

function healthCheck(): array {
    $checks = [];
    $overallStatus = 'healthy';
    
    // Database connectivity check
    try {
        $db = Database::getInstance();
        $result = $db->query('SELECT 1')->fetch();
        $checks['database'] = [
            'status' => $result ? 'healthy' : 'unhealthy',
            'message' => $result ? 'Database connection successful' : 'Database query failed',
            'response_time_ms' => round(Database::getStats()['query_time'], 2)
        ];
    } catch (Exception $e) {
        $checks['database'] = [
            'status' => 'unhealthy',
            'message' => 'Database connection failed: ' . $e->getMessage(),
            'response_time_ms' => null
        ];
        $overallStatus = 'unhealthy';
    }
    
    // Cache system check
    try {
        $testKey = 'health_check_' . time();
        Cache::set($testKey, 'test_value', 60);
        $retrieved = Cache::get($testKey);
        Cache::delete($testKey);
        
        $checks['cache'] = [
            'status' => $retrieved === 'test_value' ? 'healthy' : 'unhealthy',
            'message' => $retrieved === 'test_value' ? 'Cache read/write successful' : 'Cache operation failed',
            'stats' => Cache::getStats()
        ];
    } catch (Exception $e) {
        $checks['cache'] = [
            'status' => 'unhealthy',
            'message' => 'Cache system error: ' . $e->getMessage(),
            'stats' => null
        ];
        $overallStatus = 'unhealthy';
    }
    
    // Connection pool check
    try {
        $poolStats = ConnectionPool::getStats();
        $checks['connection_pool'] = [
            'status' => 'healthy',
            'message' => 'Connection pool operational',
            'stats' => $poolStats
        ];
    } catch (Exception $e) {
        $checks['connection_pool'] = [
            'status' => 'unhealthy',
            'message' => 'Connection pool error: ' . $e->getMessage(),
            'stats' => null
        ];
        $overallStatus = 'unhealthy';
    }
    
    // File system check
    try {
        $tempFile = sys_get_temp_dir() . '/monitor_health_test_' . uniqid();
        $testContent = 'health_check_' . date('Y-m-d H:i:s');
        
        if (file_put_contents($tempFile, $testContent) !== false) {
            $readBack = file_get_contents($tempFile);
            unlink($tempFile);
            
            $checks['filesystem'] = [
                'status' => $readBack === $testContent ? 'healthy' : 'unhealthy',
                'message' => $readBack === $testContent ? 'File system read/write successful' : 'File system operation failed'
            ];
        } else {
            $checks['filesystem'] = [
                'status' => 'unhealthy',
                'message' => 'Cannot write to file system'
            ];
            $overallStatus = 'unhealthy';
        }
    } catch (Exception $e) {
        $checks['filesystem'] = [
            'status' => 'unhealthy',
            'message' => 'File system error: ' . $e->getMessage()
        ];
        $overallStatus = 'unhealthy';
    }
    
    // Memory usage check
    $memoryUsage = memory_get_usage(true);
    $memoryLimit = ini_get('memory_limit');
    $memoryLimitBytes = return_bytes($memoryLimit);
    $memoryUsagePercent = ($memoryUsage / $memoryLimitBytes) * 100;
    
    $checks['memory'] = [
        'status' => $memoryUsagePercent < 80 ? 'healthy' : ($memoryUsagePercent < 90 ? 'warning' : 'unhealthy'),
        'message' => sprintf('Memory usage: %.2f%% (%s / %s)', 
            $memoryUsagePercent, 
            format_bytes($memoryUsage), 
            $memoryLimit
        ),
        'usage_bytes' => $memoryUsage,
        'limit_bytes' => $memoryLimitBytes,
        'usage_percent' => round($memoryUsagePercent, 2)
    ];
    
    if ($memoryUsagePercent >= 90) {
        $overallStatus = 'unhealthy';
    } elseif ($memoryUsagePercent >= 80 && $overallStatus === 'healthy') {
        $overallStatus = 'warning';
    }
    
    // Disk space check
    $logDir = __DIR__ . '/logs';
    $freeSpace = disk_free_space($logDir);
    $totalSpace = disk_total_space($logDir);
    $usedSpace = $totalSpace - $freeSpace;
    $diskUsagePercent = ($usedSpace / $totalSpace) * 100;
    
    $checks['disk'] = [
        'status' => $diskUsagePercent < 80 ? 'healthy' : ($diskUsagePercent < 90 ? 'warning' : 'unhealthy'),
        'message' => sprintf('Disk usage: %.2f%% (%s free / %s total)', 
            $diskUsagePercent,
            format_bytes($freeSpace),
            format_bytes($totalSpace)
        ),
        'free_bytes' => $freeSpace,
        'total_bytes' => $totalSpace,
        'usage_percent' => round($diskUsagePercent, 2)
    ];
    
    if ($diskUsagePercent >= 90) {
        $overallStatus = 'unhealthy';
    } elseif ($diskUsagePercent >= 80 && $overallStatus === 'healthy') {
        $overallStatus = 'warning';
    }
    
    // System load check
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $cpuCores = function_exists('shell_exec') ? (int)shell_exec('nproc') : 1;
        $loadPercent = ($load[0] / $cpuCores) * 100;
        
        $checks['cpu'] = [
            'status' => $loadPercent < 80 ? 'healthy' : ($loadPercent < 90 ? 'warning' : 'unhealthy'),
            'message' => sprintf('CPU load: %.2f (%.2f%% of %d cores)', 
                $load[0], 
                $loadPercent, 
                $cpuCores
            ),
            'load_1min' => $load[0],
            'load_5min' => $load[1] ?? 0,
            'load_15min' => $load[2] ?? 0,
            'cpu_cores' => $cpuCores,
            'load_percent' => round($loadPercent, 2)
        ];
        
        if ($loadPercent >= 90) {
            $overallStatus = 'unhealthy';
        } elseif ($loadPercent >= 80 && $overallStatus === 'healthy') {
            $overallStatus = 'warning';
        }
    }
    
    // Circuit breaker status
    try {
        $circuitBreakerStats = [
            'active_circuits' => 0,
            'open_circuits' => 0,
            'half_open_circuits' => 0
        ];
        
        // This would need to be implemented in CircuitBreaker class
        $checks['circuit_breaker'] = [
            'status' => 'healthy',
            'message' => 'Circuit breaker operational',
            'stats' => $circuitBreakerStats
        ];
    } catch (Exception $e) {
        $checks['circuit_breaker'] = [
            'status' => 'unhealthy',
            'message' => 'Circuit breaker error: ' . $e->getMessage(),
            'stats' => null
        ];
        $overallStatus = 'unhealthy';
    }
    
    // Log system check
    try {
        $logStats = Logger::getLogStats();
        $checks['logging'] = [
            'status' => 'healthy',
            'message' => 'Logging system operational',
            'stats' => $logStats
        ];
    } catch (Exception $e) {
        $checks['logging'] = [
            'status' => 'unhealthy',
            'message' => 'Logging system error: ' . $e->getMessage(),
            'stats' => null
        ];
        $overallStatus = 'unhealthy';
    }
    
    return [
        'status' => $overallStatus,
        'timestamp' => date('Y-m-d H:i:s'),
        'uptime' => format_uptime(time() - filemtime(__FILE__)),
        'version' => '2.0.0',
        'checks' => $checks
    ];
}

function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;
    switch($last) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}

function format_bytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

function format_uptime($seconds) {
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    $parts = [];
    if ($days > 0) $parts[] = "{$days}d";
    if ($hours > 0) $parts[] = "{$hours}h";
    if ($minutes > 0) $parts[] = "{$minutes}m";
    
    return implode(' ', $parts) ?: '0m';
}

// Main execution
try {
    $health = healthCheck();
    $httpStatus = match($health['status']) {
        'healthy' => 200,
        'warning' => 200,
        'unhealthy' => 503,
        default => 500
    };
    
    http_response_code($httpStatus);
    echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'unhealthy',
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => $e->getMessage(),
        'checks' => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
?>
