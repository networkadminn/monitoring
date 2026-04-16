<?php
// =============================================================================
// includes/ConnectionPool.php - HTTP connection pooling and keep-alive optimization
// =============================================================================

class ConnectionPool {
    private static array $connections = [];
    private static array $lastUsed = [];
    private static int $maxConnections = 50;
    private static int $connectionTimeout = 300; // 5 minutes
    
    public static function getConnection(string $host, int $port = 80): ?CurlHandle {
        $key = self::getConnectionKey($host, $port);
        
        // Clean up expired connections
        self::cleanupExpiredConnections();
        
        // Check if we have a reusable connection
        if (isset(self::$connections[$key])) {
            $ch = self::$connections[$key];
            if (is_resource($ch)) {
                self::$lastUsed[$key] = time();
                return $ch;
            } else {
                // Connection is dead, remove it
                unset(self::$connections[$key]);
                unset(self::$lastUsed[$key]);
            }
        }
        
        // Don't exceed max connections
        if (count(self::$connections) >= self::$maxConnections) {
            self::cleanupOldestConnection();
        }
        
        // Create new connection with keep-alive
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_FORBID_REUSE => false, // Allow reuse
            CURLOPT_FRESH_CONNECT => false,  // Try to reuse
            CURLOPT_TCP_KEEPALIVE => 1,     // Enable TCP keep-alive
            CURLOPT_TCP_KEEPIDLE => 30,     // Keep-alive idle time
            CURLOPT_TCP_KEEPINTVL => 10,    // Keep-alive interval
        ]);
        
        self::$connections[$key] = $ch;
        self::$lastUsed[$key] = time();
        
        return $ch;
    }
    
    public static function releaseConnection(CurlHandle $ch, string $host, int $port = 80): void {
        $key = self::getConnectionKey($host, $port);
        
        // Reset connection state for reuse
        curl_setopt_array($ch, [
            CURLOPT_URL => null,
            CURLOPT_HTTPGET => true,
            CURLOPT_POST => false,
            CURLOPT_POSTFIELDS => null,
            CURLOPT_HTTPHEADER => [],
        ]);
        
        self::$connections[$key] = $ch;
        self::$lastUsed[$key] = time();
    }
    
    public static function closeConnection(string $host, int $port = 80): void {
        $key = self::getConnectionKey($host, $port);
        
        if (isset(self::$connections[$key])) {
            $ch = self::$connections[$key];
            if (is_resource($ch)) {
                curl_close($ch);
            }
            unset(self::$connections[$key]);
            unset(self::$lastUsed[$key]);
        }
    }
    
    public static function closeAllConnections(): void {
        foreach (self::$connections as $ch) {
            if (is_resource($ch)) {
                curl_close($ch);
            }
        }
        self::$connections = [];
        self::$lastUsed = [];
    }
    
    private static function getConnectionKey(string $host, int $port): string {
        return $host . ':' . $port;
    }
    
    private static function cleanupExpiredConnections(): void {
        $now = time();
        foreach (self::$lastUsed as $key => $lastUsed) {
            if ($now - $lastUsed > self::$connectionTimeout) {
                if (isset(self::$connections[$key])) {
                    $ch = self::$connections[$key];
                    if (is_resource($ch)) {
                        curl_close($ch);
                    }
                    unset(self::$connections[$key]);
                    unset(self::$lastUsed[$key]);
                }
            }
        }
    }
    
    private static function cleanupOldestConnection(): void {
        if (empty(self::$lastUsed)) {
            return;
        }
        
        $oldestKey = array_keys(self::$lastUsed, min(self::$lastUsed))[0];
        
        if (isset(self::$connections[$oldestKey])) {
            $ch = self::$connections[$oldestKey];
            if (is_resource($ch)) {
                curl_close($ch);
            }
            unset(self::$connections[$oldestKey]);
            unset(self::$lastUsed[$oldestKey]);
        }
    }
    
    public static function getStats(): array {
        return [
            'active_connections' => count(self::$connections),
            'max_connections' => self::$maxConnections,
            'connection_timeout' => self::$connectionTimeout,
            'connections' => array_map(function($key) {
                return [
                    'key' => $key,
                    'last_used' => self::$lastUsed[$key] ?? 0,
                    'age_seconds' => time() - (self::$lastUsed[$key] ?? 0),
                ];
            }, array_keys(self::$connections)),
        ];
    }
}
