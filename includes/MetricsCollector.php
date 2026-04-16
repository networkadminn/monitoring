<?php
// =============================================================================
// includes/MetricsCollector.php - Advanced metrics collection and monitoring
// =============================================================================

class MetricsCollector {
    private static array $metrics = [];
    private static array $timers = [];
    private static array $gauges = [];
    private static array $counters = [];
    private static float $startTime;
    
    public static function init(): void {
        self::$startTime = microtime(true);
        self::$metrics = [
            'system' => [],
            'checks' => [],
            'alerts' => [],
            'performance' => [],
            'errors' => [],
        ];
        
        // Register shutdown function to save metrics
        register_shutdown_function([self::class, 'saveMetrics']);
    }
    
    public static function incrementCounter(string $name, float $value = 1.0, array $tags = []): void {
        $key = self::buildKey($name, $tags);
        if (!isset(self::$counters[$key])) {
            self::$counters[$key] = 0.0;
        }
        self::$counters[$key] += $value;
        
        self::$metrics['checks'][$key] = [
            'type' => 'counter',
            'value' => self::$counters[$key],
            'tags' => $tags,
            'timestamp' => microtime(true),
        ];
    }
    
    public static function setGauge(string $name, float $value, array $tags = []): void {
        $key = self::buildKey($name, $tags);
        self::$gauges[$key] = $value;
        
        self::$metrics['performance'][$key] = [
            'type' => 'gauge',
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true),
        ];
    }
    
    public static function recordTimer(string $name, float $duration, array $tags = []): void {
        $key = self::buildKey($name, $tags);
        
        if (!isset(self::$timers[$key])) {
            self::$timers[$key] = [];
        }
        
        self::$timers[$key][] = $duration;
        
        // Calculate statistics
        $count = count(self::$timers[$key]);
        $sum = array_sum(self::$timers[$key]);
        $avg = $sum / $count;
        $min = min(self::$timers[$key]);
        $max = max(self::$timers[$key]);
        
        self::$metrics['performance'][$key] = [
            'type' => 'timer',
            'count' => $count,
            'sum' => $sum,
            'avg' => $avg,
            'min' => $min,
            'max' => $max,
            'tags' => $tags,
            'timestamp' => microtime(true),
        ];
    }
    
    public static function startTimer(string $name, array $tags = []): string {
        $timerId = uniqid($name . '_');
        self::$timers[$timerId] = [
            'name' => $name,
            'tags' => $tags,
            'start_time' => microtime(true),
        ];
        return $timerId;
    }
    
    public static function endTimer(string $timerId): void {
        if (!isset(self::$timers[$timerId])) {
            return;
        }
        
        $timer = self::$timers[$timerId];
        $duration = microtime(true) - $timer['start_time'];
        
        self::recordTimer($timer['name'], $duration, $timer['tags']);
        unset(self::$timers[$timerId]);
    }
    
    public static function recordError(string $type, string $message, array $context = []): void {
        $error = [
            'type' => $type,
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true),
            'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        ];
        
        self::$metrics['errors'][] = $error;
        
        // Also log to error log
        Logger::error("{$type}: {$message}", $context);
    }
    
    public static function recordSystemMetrics(): void {
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        
        $loadAvg = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
        $diskUsage = self::getDiskUsage();
        
        self::setGauge('memory_usage_bytes', $memoryUsage, ['component' => 'system']);
        self::setGauge('memory_peak_bytes', $memoryPeak, ['component' => 'system']);
        self::setGauge('cpu_load_1min', $loadAvg[0], ['component' => 'system']);
        self::setGauge('cpu_load_5min', $loadAvg[1], ['component' => 'system']);
        self::setGauge('disk_free_bytes', $diskUsage['free'], ['component' => 'system']);
        self::setGauge('disk_usage_percent', $diskUsage['percent'], ['component' => 'system']);
        
        // Database metrics
        if (class_exists('Database')) {
            $dbStats = Database::getStats();
            self::setGauge('db_query_count', $dbStats['query_count'], ['component' => 'database']);
            self::setGauge('db_query_time_ms', floatval($dbStats['query_time']), ['component' => 'database']);
        }
        
        // Cache metrics
        if (class_exists('AdvancedCache')) {
            $cacheStats = AdvancedCache::getStats();
            self::setGauge('cache_hit_ratio', self::calculateCacheHitRatio($cacheStats), ['component' => 'cache']);
            self::setGauge('cache_memory_bytes', $cacheStats['memory_used'] ?? 0, ['component' => 'cache']);
        }
        
        // Connection pool metrics
        if (class_exists('ConnectionPool')) {
            $poolStats = ConnectionPool::getStats();
            self::setGauge('connection_pool_active', $poolStats['active_connections'], ['component' => 'connections']);
            self::setGauge('connection_pool_utilization', ($poolStats['active_connections'] / $poolStats['max_connections']) * 100, ['component' => 'connections']);
        }
    }
    
    public static function recordCheckMetrics(int $siteId, string $checkType, array $result): void {
        $tags = [
            'site_id' => (string) $siteId,
            'check_type' => $checkType,
            'status' => $result['status'],
        ];
        
        self::incrementCounter('checks_total', 1, $tags);
        
        if ($result['status'] === 'up') {
            self::incrementCounter('checks_success', 1, $tags);
        } else {
            self::incrementCounter('checks_failed', 1, $tags);
            self::incrementCounter('errors_total', 1, ['error_type' => $result['error_category'] ?? 'unknown']);
        }
        
        // Response time metrics
        if (isset($result['response_time'])) {
            self::recordTimer('check_duration', $result['response_time'], $tags);
        }
        
        // Retry metrics
        if (isset($result['retry_count']) && $result['retry_count'] > 0) {
            self::incrementCounter('checks_retried', $result['retry_count'], $tags);
        }
        
        // Circuit breaker metrics
        if (isset($result['circuit_breaker_state'])) {
            self::incrementCounter('circuit_breaker_trips', 1, [
                'state' => $result['circuit_breaker_state'],
                'site_id' => (string) $siteId,
            ]);
        }
    }
    
    public static function recordAlertMetrics(int $siteId, string $alertType, bool $sent): void {
        $tags = [
            'site_id' => (string) $siteId,
            'alert_type' => $alertType,
        ];
        
        self::incrementCounter('alerts_total', 1, $tags);
        
        if ($sent) {
            self::incrementCounter('alerts_sent', 1, $tags);
        } else {
            self::incrementCounter('alerts_failed', 1, $tags);
        }
    }
    
    public static function getMetrics(): array {
        return [
            'timestamp' => microtime(true),
            'uptime' => microtime(true) - self::$startTime,
            'metrics' => self::$metrics,
            'counters' => self::$counters,
            'gauges' => self::$gauges,
            'timers' => self::$timers,
        ];
    }
    
    public static function getPrometheusMetrics(): string {
        $output = [];
        
        // Counters
        foreach (self::$counters as $key => $value) {
            $parts = explode(':', $key);
            $name = str_replace('_', '_', $parts[0]);
            $tags = [];
            
            if (isset($parts[1])) {
                $tagPairs = explode(',', $parts[1]);
                foreach ($tagPairs as $pair) {
                    $kv = explode('=', $pair);
                    if (count($kv) === 2) {
                        $tags[] = $kv[0] . '="' . $kv[1] . '"';
                    }
                }
            }
            
            $tagStr = empty($tags) ? '' : '{' . implode(',', $tags) . '}';
            $output[] = "# HELP {$name} Total count of {$name}";
            $output[] = "# TYPE {$name} counter";
            $output[] = "{$name}{$tagStr} {$value}";
        }
        
        // Gauges
        foreach (self::$gauges as $key => $value) {
            $parts = explode(':', $key);
            $name = str_replace('_', '_', $parts[0]);
            $tags = [];
            
            if (isset($parts[1])) {
                $tagPairs = explode(',', $parts[1]);
                foreach ($tagPairs as $pair) {
                    $kv = explode('=', $pair);
                    if (count($kv) === 2) {
                        $tags[] = $kv[0] . '="' . $kv[1] . '"';
                    }
                }
            }
            
            $tagStr = empty($tags) ? '' : '{' . implode(',', $tags) . '}';
            $output[] = "# HELP {$name} Current value of {$name}";
            $output[] = "# TYPE {$name} gauge";
            $output[] = "{$name}{$tagStr} {$value}";
        }
        
        // Timers (histograms)
        foreach (self::$timers as $key => $values) {
            if (!is_array($values) || !isset($values['count'])) {
                continue;
            }
            
            $parts = explode(':', $key);
            $name = str_replace('_', '_', $parts[0]) . '_seconds';
            $tags = [];
            
            if (isset($parts[1])) {
                $tagPairs = explode(',', $parts[1]);
                foreach ($tagPairs as $pair) {
                    $kv = explode('=', $pair);
                    if (count($kv) === 2) {
                        $tags[] = $kv[0] . '="' . $kv[1] . '"';
                    }
                }
            }
            
            $tagStr = empty($tags) ? '' : '{' . implode(',', $tags) . '}';
            
            $output[] = "# HELP {$name} Duration of {$name}";
            $output[] = "# TYPE {$name} histogram";
            $output[] = "{$name}_sum{$tagStr} " . ($values['sum'] ?? 0);
            $output[] = "{$name}_count{$tagStr} " . ($values['count'] ?? 0);
            $output[] = "{$name}_bucket{$tagStr} {le=\"+Inf\"} " . ($values['count'] ?? 0);
        }
        
        return implode("\n", $output);
    }
    
    public static function exportMetrics(string $format = 'json'): string {
        $metrics = self::getMetrics();
        
        return match($format) {
            'json' => json_encode($metrics, JSON_PRETTY_PRINT),
            'prometheus' => self::getPrometheusMetrics(),
            'influx' => self::getInfluxFormat($metrics),
            default => json_encode($metrics, JSON_PRETTY_PRINT),
        };
    }
    
    public static function saveMetrics(): void {
        if (!ENABLE_METRICS_COLLECTION) {
            return;
        }
        
        $metricsDir = __DIR__ . '/../metrics';
        if (!is_dir($metricsDir)) {
            mkdir($metricsDir, 0755, true);
        }
        
        // Save current metrics
        $metricsFile = $metricsDir . '/metrics_' . date('Y-m-d_H-i-s') . '.json';
        file_put_contents($metricsFile, json_encode(self::getMetrics(), JSON_PRETTY_PRINT), LOCK_EX);
        
        // Save Prometheus format
        $prometheusFile = $metricsDir . '/metrics.prom';
        file_put_contents($prometheusFile, self::getPrometheusMetrics(), LOCK_EX);
        
        // Cleanup old metrics files
        $cutoff = time() - (METRICS_RETENTION_DAYS * 24 * 60 * 60);
        $files = glob($metricsDir . '/metrics_*.json');
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
        
        Logger::debug("Metrics saved", [
            'file' => $metricsFile,
            'counters' => count(self::$counters),
            'gauges' => count(self::$gauges),
            'timers' => count(self::$timers),
        ]);
    }
    
    private static function buildKey(string $name, array $tags): string {
        if (empty($tags)) {
            return $name;
        }
        
        $tagPairs = [];
        foreach ($tags as $key => $value) {
            $tagPairs[] = $key . '=' . $value;
        }
        
        return $name . ':' . implode(',', $tagPairs);
    }
    
    private static function getDiskUsage(): array {
        $path = __DIR__ . '/..';
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;
        
        return [
            'total' => $total,
            'free' => $free,
            'used' => $used,
            'percent' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
        ];
    }
    
    private static function calculateCacheHitRatio(array $stats): float {
        $hits = $stats['keyspace_hits'] ?? 0;
        $misses = $stats['keyspace_misses'] ?? 0;
        $total = $hits + $misses;
        
        return $total > 0 ? round(($hits / $total) * 100, 2) : 0.0;
    }
    
    private static function getInfluxFormat(array $metrics): string {
        $lines = [];
        $timestamp = $metrics['timestamp'];
        
        foreach ($metrics['metrics'] as $category => $values) {
            foreach ($values as $key => $data) {
                $tags = '';
                if (isset($data['tags']) && !empty($data['tags'])) {
                    $tagPairs = [];
                    foreach ($data['tags'] as $tagKey => $tagValue) {
                        $tagPairs[] = $tagKey . '="' . $tagValue . '"';
                    }
                    $tags = ',' . implode(',', $tagPairs);
                }
                
                $value = $data['value'] ?? ($data['avg'] ?? 0);
                $lines[] = "monitoring,category={$category},name={$key}{$tags} value={$value} " . (int)($timestamp * 1000000000);
            }
        }
        
        return implode("\n", $lines);
    }
    
    public static function resetMetrics(): void {
        self::$metrics = [
            'system' => [],
            'checks' => [],
            'alerts' => [],
            'performance' => [],
            'errors' => [],
        ];
        self::$counters = [];
        self::$gauges = [];
        self::$timers = [];
    }
}
