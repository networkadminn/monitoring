<?php
// =============================================================================
// includes/AdvancedCache.php - Multi-strategy caching with Redis/Memcached support
// =============================================================================

interface CacheInterface {
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, int $ttl = null): bool;
    public function delete(string $key): bool;
    public function clear(): bool;
    public function has(string $key): bool;
    public function increment(string $key, int $step = 1): int;
    public function decrement(string $key, int $step = 1): int;
    public function getStats(): array;
}

class FileCache implements CacheInterface {
    private string $cacheDir;
    private int $defaultTtl = 300;
    
    public function __construct() {
        $this->cacheDir = sys_get_temp_dir() . '/monitor_cache';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    public function get(string $key, mixed $default = null): mixed {
        $cacheFile = $this->getCacheFile($key);
        
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
    
    public function set(string $key, mixed $value, int $ttl = null): bool {
        $cacheFile = $this->getCacheFile($key);
        $ttl = $ttl ?? $this->defaultTtl;
        
        $data = [
            'value' => $value,
            'expires' => time() + $ttl,
            'created' => time(),
        ];
        
        return file_put_contents($cacheFile, serialize($data), LOCK_EX) !== false;
    }
    
    public function delete(string $key): bool {
        $cacheFile = $this->getCacheFile($key);
        return @unlink($cacheFile);
    }
    
    public function clear(): bool {
        $files = glob($this->cacheDir . '/*.cache');
        $success = true;
        
        foreach ($files as $file) {
            if (!unlink($file)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    public function has(string $key): bool {
        return $this->get($key) !== null;
    }
    
    public function increment(string $key, int $step = 1): int {
        $value = (int) $this->get($key, 0);
        $value += $step;
        $this->set($key, $value);
        return $value;
    }
    
    public function decrement(string $key, int $step = 1): int {
        return $this->increment($key, -$step);
    }
    
    public function getStats(): array {
        $files = glob($this->cacheDir . '/*.cache');
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
            'type' => 'file',
            'total_files' => count($files),
            'valid_files' => $validCount,
            'expired_files' => $expiredCount,
            'total_size' => $totalSize,
            'total_size_mb' => round($totalSize / (1024 * 1024), 2),
        ];
    }
    
    private function getCacheFile(string $key): string {
        $safeKey = hash('sha256', $key);
        return $this->cacheDir . '/' . $safeKey . '.cache';
    }
}

class RedisCache implements CacheInterface {
    private ?Redis $redis = null;
    private string $prefix = 'monitor:';
    private int $defaultTtl = 300;
    
    public function __construct() {
        if (!class_exists('Redis')) {
            throw new RuntimeException('Redis extension not available');
        }
        
        $this->redis = new Redis();
        $host = defined('REDIS_HOST') ? REDIS_HOST : '127.0.0.1';
        $port = defined('REDIS_PORT') ? REDIS_PORT : 6379;
        $password = defined('REDIS_PASSWORD') ? REDIS_PASSWORD : null;
        $database = defined('REDIS_DATABASE') ? REDIS_DATABASE : 0;
        
        try {
            $this->redis->connect($host, $port);
            if ($password) {
                $this->redis->auth($password);
            }
            $this->redis->select($database);
        } catch (Exception $e) {
            throw new RuntimeException("Redis connection failed: " . $e->getMessage());
        }
    }
    
    public function get(string $key, mixed $default = null): mixed {
        $value = $this->redis->get($this->prefix . $key);
        return $value === false ? $default : unserialize($value);
    }
    
    public function set(string $key, mixed $value, int $ttl = null): bool {
        $ttl = $ttl ?? $this->defaultTtl;
        $serialized = serialize($value);
        return $this->redis->setex($this->prefix . $key, $ttl, $serialized);
    }
    
    public function delete(string $key): bool {
        return $this->redis->del($this->prefix . $key) > 0;
    }
    
    public function clear(): bool {
        return $this->redis->flushDB();
    }
    
    public function has(string $key): bool {
        return $this->redis->exists($this->prefix . $key);
    }
    
    public function increment(string $key, int $step = 1): int {
        return $this->redis->incrBy($this->prefix . $key, $step);
    }
    
    public function decrement(string $key, int $step = 1): int {
        return $this->redis->decrBy($this->prefix . $key, $step);
    }
    
    public function getStats(): array {
        $info = $this->redis->info();
        return [
            'type' => 'redis',
            'memory_used' => $info['used_memory'] ?? 0,
            'memory_peak' => $info['used_memory_peak'] ?? 0,
            'connected_clients' => $info['connected_clients'] ?? 0,
            'total_commands_processed' => $info['total_commands_processed'] ?? 0,
            'keyspace_hits' => $info['keyspace_hits'] ?? 0,
            'keyspace_misses' => $info['keyspace_misses'] ?? 0,
        ];
    }
}

class MemcachedCache implements CacheInterface {
    private ?Memcached $memcached = null;
    private string $prefix = 'monitor_';
    private int $defaultTtl = 300;
    
    public function __construct() {
        if (!class_exists('Memcached')) {
            throw new RuntimeException('Memcached extension not available');
        }
        
        $this->memcached = new Memcached();
        $servers = defined('MEMCACHED_SERVERS') ? MEMCACHED_SERVERS : [['127.0.0.1', 11237]];
        
        foreach ($servers as $server) {
            $this->memcached->addServer($server[0], $server[1]);
        }
    }
    
    public function get(string $key, mixed $default = null): mixed {
        $value = $this->memcached->get($this->prefix . $key);
        return $value === false ? $default : $value;
    }
    
    public function set(string $key, mixed $value, int $ttl = null): bool {
        $ttl = $ttl ?? $this->defaultTtl;
        return $this->memcached->set($this->prefix . $key, $value, $ttl);
    }
    
    public function delete(string $key): bool {
        return $this->memcached->delete($this->prefix . $key);
    }
    
    public function clear(): bool {
        return $this->memcached->flush();
    }
    
    public function has(string $key): bool {
        $this->memcached->get($this->prefix . $key);
        return $this->memcached->getResultCode() !== Memcached::RES_NOTFOUND;
    }
    
    public function increment(string $key, int $step = 1): int {
        return $this->memcached->increment($this->prefix . $key, $step);
    }
    
    public function decrement(string $key, int $step = 1): int {
        return $this->memcached->decrement($this->prefix . $key, $step);
    }
    
    public function getStats(): array {
        $stats = $this->memcached->getStats();
        $serverStats = reset($stats);
        
        return [
            'type' => 'memcached',
            'version' => $serverStats['version'] ?? 'unknown',
            'bytes' => $serverStats['bytes'] ?? 0,
            'curr_connections' => $serverStats['curr_connections'] ?? 0,
            'total_items' => $serverStats['total_items'] ?? 0,
            'get_hits' => $serverStats['get_hits'] ?? 0,
            'get_misses' => $serverStats['get_misses'] ?? 0,
        ];
    }
}

class AdvancedCache {
    private static CacheInterface $instance;
    private static array $config = [];
    
    public static function init(): void {
        self::$config = [
            'strategy' => CACHE_STRATEGY,
            'default_ttl' => 300,
            'compression' => ENABLE_RESULT_COMPRESSION,
        ];
        
        self::$instance = match(self::$config['strategy']) {
            'redis' => new RedisCache(),
            'memcached' => new MemcachedCache(),
            'file' => new FileCache(),
            default => new FileCache(),
        };
    }
    
    public static function get(string $key, mixed $default = null): mixed {
        self::ensureInitialized();
        $value = self::$instance->get($key, $default);
        
        if (self::$config['compression'] && is_string($value) && strlen($value) > 1024) {
            $value = self::decompress($value);
        }
        
        return $value;
    }
    
    public static function set(string $key, mixed $value, int $ttl = null): bool {
        self::ensureInitialized();
        
        if (self::$config['compression'] && is_string($value) && strlen($value) > 1024) {
            $value = self::compress($value);
        }
        
        return self::$instance->set($key, $value, $ttl);
    }
    
    public static function delete(string $key): bool {
        self::ensureInitialized();
        return self::$instance->delete($key);
    }
    
    public static function clear(): bool {
        self::ensureInitialized();
        return self::$instance->clear();
    }
    
    public static function has(string $key): bool {
        self::ensureInitialized();
        return self::$instance->has($key);
    }
    
    public static function increment(string $key, int $step = 1): int {
        self::ensureInitialized();
        return self::$instance->increment($key, $step);
    }
    
    public static function decrement(string $key, int $step = 1): int {
        self::ensureInitialized();
        return self::$instance->decrement($key, $step);
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
    
    public static function getStats(): array {
        self::ensureInitialized();
        $stats = self::$instance->getStats();
        $stats['strategy'] = self::$config['strategy'];
        $stats['compression_enabled'] = self::$config['compression'];
        return $stats;
    }
    
    public static function cleanup(): int {
        self::ensureInitialized();
        
        if (self::$instance instanceof FileCache) {
            $files = glob(sys_get_temp_dir() . '/monitor_cache/*.cache');
            $cleaned = 0;
            $cutoff = time() - (7 * 24 * 60 * 60); // 7 days
            
            foreach ($files as $file) {
                if (filemtime($file) < $cutoff) {
                    if (unlink($file)) {
                        $cleaned++;
                    }
                }
            }
            
            return $cleaned;
        }
        
        return 0; // Redis/Memcached handle TTL automatically
    }
    
    private static function ensureInitialized(): void {
        if (!isset(self::$instance)) {
            self::init();
        }
    }
    
    private static function compress(string $data): string {
        if (function_exists('gzcompress')) {
            return gzcompress($data, 6);
        }
        return $data;
    }
    
    private static function decompress(string $data): string {
        if (function_exists('gzuncompress')) {
            $decompressed = @gzuncompress($data);
            if ($decompressed !== false) {
                return $decompressed;
            }
        }
        return $data;
    }
    
    // Specialized cache methods for monitoring data
    public static function cacheQueryResult(string $sql, array $params, callable $query, int $ttl = 300): mixed {
        $key = 'query:' . md5($sql . serialize($params));
        return self::remember($key, $query, $ttl);
    }
    
    public static function cacheSiteStatus(int $siteId, callable $checker, int $ttl = 60): mixed {
        $key = "site_status:{$siteId}";
        return self::remember($key, $checker, $ttl);
    }
    
    public static function cacheUptimeCalculation(int $siteId, int $days, callable $calculator, int $ttl = 3600): float {
        $key = "uptime:{$siteId}:{$days}";
        return self::remember($key, $calculator, $ttl);
    }
    
    public static function cacheAlertSent(string $siteId, string $type, int $ttl = 300): bool {
        $key = "alert_sent:{$siteId}:{$type}";
        return self::has($key);
    }
    
    public static function markAlertSent(string $siteId, string $type, int $ttl = 300): void {
        $key = "alert_sent:{$siteId}:{$type}";
        self::set($key, time(), $ttl);
    }
    
    public static function getMulti(array $keys): array {
        self::ensureInitialized();
        $results = [];
        
        foreach ($keys as $key) {
            $results[$key] = self::get($key);
        }
        
        return $results;
    }
    
    public static function setMulti(array $data, int $ttl = null): bool {
        self::ensureInitialized();
        $success = true;
        
        foreach ($data as $key => $value) {
            if (!self::set($key, $value, $ttl)) {
                $success = false;
            }
        }
        
        return $success;
    }
}
