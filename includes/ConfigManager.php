<?php
// =============================================================================
// includes/ConfigManager.php - Advanced configuration management with validation
// =============================================================================

class ConfigManager {
    private static array $config = [];
    private static array $schema = [];
    private static string $configFile;
    private static array $watchers = [];
    private static int $lastModified = 0;
    
    public static function init(): void {
        self::$configFile = __DIR__ . '/../config.json';
        self::loadSchema();
        self::loadConfig();
        self::validateConfig();
        self::setupWatcher();
    }
    
    public static function get(string $key, mixed $default = null): mixed {
        self::ensureLoaded();
        
        $keys = explode('.', $key);
        $value = self::$config;
        
        foreach ($keys as $k) {
            if (!is_array($value) || !isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    public static function set(string $key, mixed $value): bool {
        self::ensureLoaded();
        
        $keys = explode('.', $key);
        $config = &self::$config;
        
        // Navigate to the parent of the target key
        foreach (array_slice($keys, 0, -1) as $k) {
            if (!isset($config[$k])) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }
        
        // Set the value
        $finalKey = end($keys);
        $oldValue = $config[$finalKey] ?? null;
        $config[$finalKey] = $value;
        
        // Validate the new configuration
        $validation = self::validateKey($key, $value);
        if (!$validation['valid']) {
            // Revert change
            $config[$finalKey] = $oldValue;
            throw new InvalidArgumentException("Invalid configuration for {$key}: {$validation['error']}");
        }
        
        // Save configuration
        $success = self::saveConfig();
        
        if ($success) {
            self::notifyWatchers($key, $oldValue, $value);
        }
        
        return $success;
    }
    
    public static function has(string $key): bool {
        return self::get($key) !== null;
    }
    
    public static function delete(string $key): bool {
        self::ensureLoaded();
        
        $keys = explode('.', $key);
        $config = &self::$config;
        
        // Navigate to the parent of the target key
        foreach (array_slice($keys, 0, -1) as $k) {
            if (!isset($config[$k])) {
                return false;
            }
            $config = &$config[$k];
        }
        
        $finalKey = end($keys);
        $oldValue = $config[$finalKey] ?? null;
        
        if (isset($config[$finalKey])) {
            unset($config[$finalKey]);
            $success = self::saveConfig();
            
            if ($success) {
                self::notifyWatchers($key, $oldValue, null);
            }
            
            return $success;
        }
        
        return false;
    }
    
    public static function reload(): bool {
        self::loadConfig();
        return self::validateConfig();
    }
    
    public static function validate(): array {
        self::ensureLoaded();
        return self::validateConfig();
    }
    
    public static function getSchema(): array {
        return self::$schema;
    }
    
    public static function addWatcher(callable $watcher, string $key = null): void {
        self::$watchers[] = [
            'watcher' => $watcher,
            'key' => $key,
        ];
    }
    
    public static function export(string $format = 'json'): string {
        self::ensureLoaded();
        
        return match($format) {
            'json' => json_encode(self::$config, JSON_PRETTY_PRINT),
            'php' => '<?php return ' . var_export(self::$config, true) . ';',
            'yaml' => self::toYaml(self::$config),
            'env' => self::toEnvFormat(self::$config),
            'ini' => self::toIniFormat(self::$config),
            default => json_encode(self::$config, JSON_PRETTY_PRINT),
        };
    }
    
    public static function import(string $data, string $format = 'json'): bool {
        $config = match($format) {
            'json' => json_decode($data, true),
            'php' => include('data://text/plain;base64,' . base64_encode($data)),
            'yaml' => self::fromYaml($data),
            'env' => self::fromEnvFormat($data),
            'ini' => parse_ini_string($data),
            default => json_decode($data, true),
        };
        
        if ($config === null) {
            throw new InvalidArgumentException("Invalid {$format} configuration data");
        }
        
        self::$config = array_merge_recursive(self::$config, $config);
        
        if (!self::validateConfig()) {
            return false;
        }
        
        return self::saveConfig();
    }
    
    public static function backup(string $filename = null): bool {
        $filename = $filename ?? 'config_backup_' . date('Y-m-d_H-i-s') . '.json';
        $backupFile = __DIR__ . '/../' . $filename;
        
        $backup = [
            'config' => self::$config,
            'timestamp' => time(),
            'version' => '2.0.0',
            'php_version' => PHP_VERSION,
            'environment' => APP_ENV ?? 'unknown',
        ];
        
        return file_put_contents($backupFile, json_encode($backup, JSON_PRETTY_PRINT), LOCK_EX) !== false;
    }
    
    public static function restore(string $backupFile): bool {
        if (!file_exists($backupFile)) {
            throw new InvalidArgumentException("Backup file not found: {$backupFile}");
        }
        
        $backup = json_decode(file_get_contents($backupFile), true);
        
        if (!$backup || !isset($backup['config'])) {
            throw new InvalidArgumentException("Invalid backup file format");
        }
        
        self::$config = $backup['config'];
        
        if (!self::validateConfig()) {
            return false;
        }
        
        return self::saveConfig();
    }
    
    public static function getDefaults(): array {
        return [
            'database' => [
                'host' => 'localhost',
                'name' => 'site_monitor',
                'user' => 'monitor_user',
                'password' => '',
                'charset' => 'utf8mb4',
                'port' => 3306,
            ],
            'monitoring' => [
                'alert_cooldown' => 3600,
                'check_timeout' => 30,
                'log_retention_days' => 90,
                'enable_detailed_monitoring' => true,
                'http_timeout' => 30,
                'ssl_timeout' => 15,
                'port_timeout' => 10,
                'dns_timeout' => 10,
                'ping_timeout' => 5,
                'max_redirects' => 5,
                'enable_content_analysis' => true,
                'enable_ssl_chain_analysis' => true,
                'enable_performance_metrics' => true,
                'retry_failed_checks' => true,
                'max_retries' => 3,
                'retry_delay' => 1000,
            ],
            'security' => [
                'enable_security_manager' => true,
                'enable_ip_blocking' => true,
                'max_login_attempts' => 5,
                'login_lockout_duration' => 900,
                'enable_session_fingerprinting' => true,
                'enable_advanced_rate_limiting' => true,
                'security_log_level' => 'WARNING',
            ],
            'performance' => [
                'enable_async_checks' => false,
                'max_concurrent_checks' => 10,
                'enable_query_optimization' => true,
                'enable_result_compression' => true,
                'cache_strategy' => 'file',
            ],
            'alerts' => [
                'enable_escalation' => false,
                'escalation_levels' => 'warning,critical',
                'enable_deduping' => true,
                'dedup_window' => 300,
            ],
            'application' => [
                'url' => 'http://localhost',
                'env' => 'production',
                'timezone' => 'UTC',
                'debug_mode' => false,
            ],
        ];
    }
    
    private static function loadSchema(): void {
        self::$schema = [
            'database.host' => ['type' => 'string', 'required' => true, 'pattern' => '/^[a-zA-Z0-9.-]+$/'],
            'database.name' => ['type' => 'string', 'required' => true, 'min' => 1, 'max' => 64],
            'database.user' => ['type' => 'string', 'required' => true, 'min' => 1, 'max' => 32],
            'database.password' => ['type' => 'string', 'required' => true, 'min' => 0, 'max' => 255],
            'database.port' => ['type' => 'integer', 'required' => false, 'min' => 1, 'max' => 65535],
            'monitoring.alert_cooldown' => ['type' => 'integer', 'required' => false, 'min' => 60, 'max' => 86400],
            'monitoring.check_timeout' => ['type' => 'integer', 'required' => false, 'min' => 5, 'max' => 300],
            'security.max_login_attempts' => ['type' => 'integer', 'required' => false, 'min' => 1, 'max' => 20],
            'performance.max_concurrent_checks' => ['type' => 'integer', 'required' => false, 'min' => 1, 'max' => 50],
        ];
    }
    
    private static function loadConfig(): void {
        if (file_exists(self::$configFile)) {
            $config = json_decode(file_get_contents(self::$configFile), true);
            if ($config !== null) {
                self::$config = $config;
                self::$lastModified = filemtime(self::$configFile);
                return;
            }
        }
        
        // Fallback to environment variables
        self::$config = self::loadFromEnvironment();
    }
    
    private static function saveConfig(): bool {
        $json = json_encode(self::$config, JSON_PRETTY_PRINT);
        $result = file_put_contents(self::$configFile, $json, LOCK_EX);
        
        if ($result !== false) {
            self::$lastModified = time();
        }
        
        return $result !== false;
    }
    
    private static function validateConfig(): array {
        $errors = [];
        $warnings = [];
        
        foreach (self::$schema as $key => $rules) {
            $value = self::get($key);
            $validation = self::validateValue($key, $value, $rules);
            
            if (!$validation['valid']) {
                if ($rules['required'] ?? false) {
                    $errors[] = "{$key}: {$validation['error']}";
                } else {
                    $warnings[] = "{$key}: {$validation['error']}";
                }
            }
        }
        
        // Cross-field validation
        if (self::get('performance.max_concurrent_checks') > 50) {
            $warnings[] = 'High concurrent check count may impact performance';
        }
        
        if (self::get('monitoring.check_timeout') > 300) {
            $warnings[] = 'Very high timeout may cause resource exhaustion';
        }
        
        return ['valid' => empty($errors), 'errors' => $errors, 'warnings' => $warnings];
    }
    
    private static function validateKey(string $key, mixed $value): array {
        $rules = self::$schema[$key] ?? null;
        
        if (!$rules) {
            return ['valid' => true];
        }
        
        return self::validateValue($key, $value, $rules);
    }
    
    private static function validateValue(string $key, mixed $value, array $rules): array {
        $type = $rules['type'] ?? 'string';
        
        // Type validation
        switch ($type) {
            case 'string':
                if (!is_string($value)) {
                    return ['valid' => false, 'error' => 'Must be a string'];
                }
                break;
            case 'integer':
                if (!is_int($value)) {
                    return ['valid' => false, 'error' => 'Must be an integer'];
                }
                break;
            case 'boolean':
                if (!is_bool($value)) {
                    return ['valid' => false, 'error' => 'Must be a boolean'];
                }
                break;
            case 'array':
                if (!is_array($value)) {
                    return ['valid' => false, 'error' => 'Must be an array'];
                }
                break;
        }
        
        // Length validation
        if (isset($rules['min']) && is_string($value) && strlen($value) < $rules['min']) {
            return ['valid' => false, 'error' => "Minimum length is {$rules['min']}"];
        }
        
        if (isset($rules['max']) && is_string($value) && strlen($value) > $rules['max']) {
            return ['valid' => false, 'error' => "Maximum length is {$rules['max']}"];
        }
        
        // Numeric validation
        if (isset($rules['min']) && is_numeric($value) && $value < $rules['min']) {
            return ['valid' => false, 'error' => "Minimum value is {$rules['min']}"];
        }
        
        if (isset($rules['max']) && is_numeric($value) && $value > $rules['max']) {
            return ['valid' => false, 'error' => "Maximum value is {$rules['max']}"];
        }
        
        // Pattern validation
        if (isset($rules['pattern']) && is_string($value) && !preg_match($rules['pattern'], $value)) {
            return ['valid' => false, 'error' => 'Invalid format'];
        }
        
        // Custom validation
        if (isset($rules['validator']) && is_callable($rules['validator'])) {
            $result = $rules['validator']($value);
            if (!$result) {
                return ['valid' => false, 'error' => 'Custom validation failed'];
            }
        }
        
        return ['valid' => true];
    }
    
    private static function setupWatcher(): void {
        if (function_exists('inotify_init')) {
            // Advanced file watching with inotify
            $watchDescriptor = inotify_init();
            $watchFile = inotify_add_watch($watchDescriptor, self::$configFile, IN_MODIFY);
            
            if ($watchFile !== false) {
                register_tick_function(function() use ($watchDescriptor) {
                    $events = inotify_read($watchDescriptor);
                    if ($events !== false) {
                        foreach ($events as $event) {
                            if ($event['mask'] & IN_MODIFY) {
                                self::reload();
                                break;
                            }
                        }
                    }
                });
            }
        }
    }
    
    private static function notifyWatchers(string $key, mixed $oldValue, mixed $newValue): void {
        foreach (self::$watchers as $watcherInfo) {
            if ($watcherInfo['key'] === null || strpos($key, $watcherInfo['key']) === 0) {
                try {
                    call_user_func($watcherInfo['watcher'], $key, $oldValue, $newValue);
                } catch (Throwable $e) {
                    error_log("Config watcher error: " . $e->getMessage());
                }
            }
        }
    }
    
    private static function ensureLoaded(): void {
        if (empty(self::$config)) {
            self::init();
        }
    }
    
    private static function loadFromEnvironment(): array {
        $config = [];
        $defaults = self::getDefaults();
        
        // Map environment variables to config structure
        $envMappings = [
            'DB_HOST' => 'database.host',
            'DB_NAME' => 'database.name',
            'DB_USER' => 'database.user',
            'DB_PASS' => 'database.password',
            'DB_PORT' => 'database.port',
            'ALERT_COOLDOWN' => 'monitoring.alert_cooldown',
            'CHECK_TIMEOUT' => 'monitoring.check_timeout',
            'MAX_CONCURRENT_CHECKS' => 'performance.max_concurrent_checks',
            'ENABLE_SECURITY_MANAGER' => 'security.enable_security_manager',
            'APP_URL' => 'application.url',
            'APP_ENV' => 'application.env',
            'TIMEZONE' => 'application.timezone',
        ];
        
        foreach ($envMappings as $envVar => $configKey) {
            $value = getenv($envVar);
            if ($value !== false) {
                set_nested_value($config, $configKey, $value);
            }
        }
        
        return array_merge_recursive($defaults, $config);
    }
    
    private static function toYaml(array $data): string {
        $yaml = '';
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $yaml .= "{$key}:\n";
                foreach ($value as $subKey => $subValue) {
                    $yaml .= "  {$subKey}: " . (is_string($subValue) ? "\"{$subValue}\"" : $subValue) . "\n";
                }
            } else {
                $yaml .= "{$key}: " . (is_string($value) ? "\"{$value}\"" : $value) . "\n";
            }
        }
        return $yaml;
    }
    
    private static function fromYaml(string $yaml): array {
        // Simple YAML parser - in production, use a proper YAML library
        $lines = explode("\n", $yaml);
        $data = [];
        $currentSection = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\"");
                
                if (is_numeric($value)) {
                    $value = (int) $value;
                } elseif ($value === 'true' || $value === 'false') {
                    $value = $value === 'true';
                } else {
                    $value = trim($value, '"');
                }
                
                if (strpos($key, '.') !== false) {
                    set_nested_value($data, $key, $value);
                } else {
                    $data[$key] = $value;
                }
            }
        }
        
        return $data;
    }
    
    private static function toEnvFormat(array $data): string {
        $env = '';
        foreach ($data as $section => $values) {
            if (is_array($values)) {
                foreach ($values as $key => $value) {
                    $envKey = strtoupper($section . '_' . $key);
                    $env .= "{$envKey}=" . (is_string($value) ? "\"{$value}\"" : $value) . "\n";
                }
            }
        }
        return $env;
    }
    
    private static function fromEnvFormat(string $env): array {
        $data = [];
        $lines = explode("\n", $env);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\"");
                
                if (is_numeric($value)) {
                    $value = (int) $value;
                } elseif ($value === 'true' || $value === 'false') {
                    $value = $value === 'true';
                } else {
                    $value = trim($value, '"');
                }
                
                $parts = explode('_', strtolower($key));
                if (count($parts) >= 2) {
                    $section = $parts[0];
                    $configKey = implode('_', array_slice($parts, 1));
                    set_nested_value($data, $section . '.' . $configKey, $value);
                }
            }
        }
        
        return $data;
    }
    
    private static function toIniFormat(array $data): string {
        $ini = '';
        foreach ($data as $section => $values) {
            $ini .= "[{$section}]\n";
            if (is_array($values)) {
                foreach ($values as $key => $value) {
                    $ini .= "{$key} = " . (is_string($value) ? "\"{$value}\"" : $value) . "\n";
                }
            }
            $ini .= "\n";
        }
        return $ini;
    }
}

if (!function_exists('array_merge_recursive')) {
    function array_merge_recursive(array $array1, array $array2): array {
        $merged = $array1;
        
        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = array_merge_recursive($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }
        
        return $merged;
    }
}

if (!function_exists('set_nested_value')) {
    function set_nested_value(array &$array, string $path, mixed $value): void {
        $keys = explode('.', $path);
        $current = &$array;
        
        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        
        $current = $value;
    }
}
