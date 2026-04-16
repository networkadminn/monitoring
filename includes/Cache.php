<?php
// =============================================================================
// includes/Cache.php - Simple file-based caching system
// =============================================================================

class Cache {
    private static string $cacheDir;
    private static int $defaultTtl = 300; // 5 minutes
    
    public static function init(): void {
        self::$cacheDir = sys_get_temp_dir() . '/monitor_cache';
        if (!is_dir(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0755, true);
        }
    }
    
    public static function get(string $key, mixed $default = null): mixed {
        self::init();
        $cacheFile = self::getCacheFile($key);
        
        if (!file_exists($cacheFile)) {
            return $default;
        }
        
        $data = unserialize(file_get_contents($cacheFile));
        if (!$data || $data['expires'] < time()) {
            @unlink($cacheFile);
            return $default;
        }
        
        return $data['value'];
    }
    
    public static function set(string $key, mixed $value, int $ttl = null): bool {
        self::init();
        $cacheFile = self::getCacheFile($key);
        $ttl = $ttl ?? self::$defaultTtl;
        
        $data = [
            'value' => $value,
            'expires' => time() + $ttl,
            'created' => time(),
        ];
        
        $result = file_put_contents($cacheFile, serialize($data), LOCK_EX);
        return $result !== false;
    }
    
    public static function delete(string $key): bool {
        self::init();
        $cacheFile = self::getCacheFile($key);
        return @unlink($cacheFile);
    }
    
    public static function clear(): bool {
        self::init();
        $files = glob(self::$cacheDir . '/*.cache');
        $success = true;
        
        foreach ($files as $file) {
            if (!unlink($file)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    public static function remember(string $key, callable $callback, int $ttl = null): mixed {
        $value = self::get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        self::set($key, $value, $ttl);
        
        return $value;
    }
    
    public static function increment(string $key, int $step = 1): int {
        $value = (int) self::get($key, 0);
        $value += $step;
        self::set($key, $value);
        return $value;
    }
    
    public static function decrement(string $key, int $step = 1): int {
        return self::increment($key, -$step);
    }
    
    public static function has(string $key): bool {
        return self::get($key) !== null;
    }
    
    public static function getStats(): array {
        self::init();
        $files = glob(self::$cacheDir . '/*.cache');
        $totalSize = 0;
        $expiredCount = 0;
        $validCount = 0;
        
        foreach ($files as $file) {
            $size = filesize($file);
            $totalSize += $size;
            
            $data = unserialize(file_get_contents($file));
            if ($data && $data['expires'] < time()) {
                $expiredCount++;
            } else {
                $validCount++;
            }
        }
        
        return [
            'total_files' => count($files),
            'valid_files' => $validCount,
            'expired_files' => $expiredCount,
            'total_size' => $totalSize,
            'total_size_mb' => round($totalSize / (1024 * 1024), 2),
        ];
    }
    
    public static function cleanup(): int {
        self::init();
        $files = glob(self::$cacheDir . '/*.cache');
        $cleaned = 0;
        
        foreach ($files as $file) {
            $data = unserialize(file_get_contents($file));
            if (!$data || $data['expires'] < time()) {
                if (unlink($file)) {
                    $cleaned++;
                }
            }
        }
        
        return $cleaned;
    }
    
    private static function getCacheFile(string $key): string {
        $safeKey = hash('sha256', $key);
        return self::$cacheDir . '/' . $safeKey . '.cache';
    }
    
    // Database query caching helpers
    public static function cacheQuery(string $sql, array $params, callable $query, int $ttl = 300): mixed {
        $key = 'query:' . md5($sql . serialize($params));
        
        return self::remember($key, function() use ($query) {
            return $query();
        }, $ttl);
    }
    
    // Site uptime caching
    public static function cacheUptime(int $siteId, int $days, callable $calculator, int $ttl = 3600): float {
        $key = "uptime:{$siteId}:{$days}";
        
        return self::remember($key, function() use ($calculator) {
            return $calculator();
        }, $ttl);
    }
    
    // Statistics caching
    public static function cacheStats(string $type, array $params, callable $calculator, int $ttl = 600): mixed {
        $key = "stats:{$type}:" . md5(serialize($params));
        
        return self::remember($key, function() use ($calculator) {
            return $calculator();
        }, $ttl);
    }
}
