<?php
// =============================================================================
// includes/CircuitBreaker.php - Circuit breaker pattern for failing sites
// =============================================================================

class CircuitBreaker {
    private static array $cache = [];
    private static string $cacheDir;
    
    public static function init(): void {
        self::$cacheDir = sys_get_temp_dir() . '/monitor_circuit_breaker';
        if (!is_dir(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0755, true);
        }
    }
    
    public static function check(int $siteId): bool {
        self::init();
        $cacheFile = self::$cacheDir . "/site_{$siteId}.json";
        
        if (!file_exists($cacheFile)) {
            return true; // No breaker state, allow check
        }
        
        $data = json_decode(file_get_contents($cacheFile), true);
        if (!$data) {
            return true; // Invalid cache, allow check
        }
        
        $now = time();
        
        // Reset if timeout has passed
        if ($now > $data['reset_time']) {
            @unlink($cacheFile);
            return true;
        }
        
        // If in open state, block checks
        if ($data['state'] === 'open') {
            return false;
        }
        
        // If in half-open state, allow limited checks
        if ($data['state'] === 'half_open') {
            return $data['allowed_checks'] > 0;
        }
        
        return true; // Closed state, allow all checks
    }
    
    public static function recordSuccess(int $siteId): void {
        self::init();
        $cacheFile = self::$cacheDir . "/site_{$siteId}.json";
        
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if ($data && $data['state'] === 'half_open') {
                // Success in half-open state, close the circuit
                @unlink($cacheFile);
            }
        }
    }
    
    public static function recordFailure(int $siteId): void {
        self::init();
        $cacheFile = self::$cacheDir . "/site_{$siteId}.json";
        
        $data = [
            'state' => 'closed',
            'failure_count' => 0,
            'last_failure' => 0,
            'reset_time' => 0,
            'allowed_checks' => 0
        ];
        
        if (file_exists($cacheFile)) {
            $existing = json_decode(file_get_contents($cacheFile), true);
            if ($existing) {
                $data = $existing;
            }
        }
        
        $now = time();
        $data['failure_count']++;
        $data['last_failure'] = $now;
        
        // Circuit breaker thresholds
        $failureThreshold = 5; // Open after 5 consecutive failures
        $timeout = 300; // 5 minutes timeout for open state
        
        if ($data['failure_count'] >= $failureThreshold) {
            if ($data['state'] === 'closed') {
                // Open the circuit
                $data['state'] = 'open';
                $data['reset_time'] = $now + $timeout;
            } elseif ($data['state'] === 'half_open') {
                // Failed in half-open, open again
                $data['state'] = 'open';
                $data['reset_time'] = $now + $timeout;
            }
        }
        
        file_put_contents($cacheFile, json_encode($data), LOCK_EX);
    }
    
    public static function attemptHalfOpen(int $siteId): void {
        self::init();
        $cacheFile = self::$cacheDir . "/site_{$siteId}.json";
        
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if ($data && $data['state'] === 'open' && time() >= $data['reset_time']) {
                $data['state'] = 'half_open';
                $data['allowed_checks'] = 1; // Allow 1 check in half-open state
                file_put_contents($cacheFile, json_encode($data), LOCK_EX);
            }
        }
    }
    
    public static function getState(int $siteId): array {
        self::init();
        $cacheFile = self::$cacheDir . "/site_{$siteId}.json";
        
        if (!file_exists($cacheFile)) {
            return ['state' => 'closed', 'failure_count' => 0];
        }
        
        $data = json_decode(file_get_contents($cacheFile), true);
        return $data ?: ['state' => 'closed', 'failure_count' => 0];
    }
}
