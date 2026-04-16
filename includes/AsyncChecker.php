<?php
// =============================================================================
// includes/AsyncChecker.php - Asynchronous site checking with concurrency
// =============================================================================

class AsyncChecker {
    private static array $processes = [];
    private static int $maxConcurrent = 10;
    private static array $results = [];
    private static array $sharedMemory;
    
    public static function init(): void {
        self::$maxConcurrent = MAX_CONCURRENT_CHECKS;
        self::$sharedMemory = [];
        
        // Ensure we have required extensions
        if (!function_exists('pcntl_fork')) {
            throw new RuntimeException('PCNTL extension required for async checking');
        }
        
        if (!function_exists('posix_kill')) {
            throw new RuntimeException('POSIX extension required for async checking');
        }
    }
    
    public static function checkSites(array $sites): array {
        if (!ENABLE_ASYNC_CHECKS) {
            // Fallback to synchronous checking
            foreach ($sites as $site) {
                $siteId = (int) $site['id'];
                self::$results[$siteId] = Checker::check($site);
            }
            return self::$results;
        }
        
        self::init();
        self::$results = [];
        $chunks = array_chunk($sites, self::$maxConcurrent);
        
        foreach ($chunks as $chunk) {
            $pids = [];
            
            // Fork processes for concurrent checks
            foreach ($chunk as $site) {
                $pid = pcntl_fork();
                
                if ($pid == -1) {
                    // Fork failed
                    throw new RuntimeException("Failed to fork process");
                } elseif ($pid == 0) {
                    // Child process
                    self::runCheckInChild($site);
                    exit(0);
                } else {
                    // Parent process
                    $pids[] = $pid;
                    self::$processes[$pid] = [
                        'site_id' => $site['id'],
                        'site_name' => $site['name'],
                        'started_at' => microtime(true),
                    ];
                }
            }
            
            // Wait for all child processes to complete
            foreach ($pids as $pid) {
                $status = 0;
                pcntl_waitpid($pid, $status);
                
                // Collect results from shared memory
                $resultFile = sys_get_temp_dir() . "/monitor_result_{$pid}.json";
                if (file_exists($resultFile)) {
                    $result = json_decode(file_get_contents($resultFile), true);
                    if ($result) {
                        $siteId = $result['site_id'];
                        self::$results[$siteId] = $result['data'];
                    }
                    unlink($resultFile);
                }
                
                // Log process completion
                $duration = microtime(true) - self::$processes[$pid]['started_at'];
                Logger::debug("Async check completed", [
                    'pid' => $pid,
                    'site_id' => self::$processes[$pid]['site_id'],
                    'site_name' => self::$processes[$pid]['site_name'],
                    'duration_ms' => round($duration * 1000, 2),
                ]);
                
                unset(self::$processes[$pid]);
            }
            
            // Brief pause between batches to prevent overwhelming
            usleep(100000); // 100ms
        }
        
        return self::$results;
    }
    
    public static function checkSiteAsync(array $site): array {
        if (!ENABLE_ASYNC_CHECKS) {
            return Checker::check($site);
        }
        
        self::init();
        
        $pid = pcntl_fork();
        
        if ($pid == -1) {
            throw new RuntimeException("Failed to fork process");
        } elseif ($pid == 0) {
            // Child process
            self::runCheckInChild($site);
            exit(0);
        } else {
            // Parent process
            $timeout = CHECK_TIMEOUT + 10; // Extra time for overhead
            $startTime = time();
            
            while (time() - $startTime < $timeout) {
                $status = 0;
                $result = pcntl_waitpid($pid, $status, WNOHANG);
                
                if ($result == -1) {
                    // Error
                    throw new RuntimeException("Error waiting for child process");
                } elseif ($result > 0) {
                    // Child completed
                    $resultFile = sys_get_temp_dir() . "/monitor_result_{$pid}.json";
                    if (file_exists($resultFile)) {
                        $data = json_decode(file_get_contents($resultFile), true);
                        unlink($resultFile);
                        return $data['data'] ?? ['status' => 'error', 'error_message' => 'No result data'];
                    }
                    break;
                }
                
                // Check if child is still running
                if (!posix_kill($pid, 0)) {
                    // Child died
                    return ['status' => 'error', 'error_message' => 'Child process died unexpectedly'];
                }
                
                // Sleep briefly to prevent busy waiting
                usleep(100000); // 100ms
            }
            
            // Timeout reached, kill child
            posix_kill($pid, SIGTERM);
            sleep(1);
            posix_kill($pid, SIGKILL); // Force kill if needed
            
            return ['status' => 'error', 'error_message' => 'Check timed out'];
        }
    }
    
    public static function getActiveProcesses(): array {
        $active = [];
        foreach (self::$processes as $pid => $info) {
            if (posix_kill($pid, 0)) {
                $active[$pid] = $info;
            }
        }
        return $active;
    }
    
    public static function killAllProcesses(): void {
        foreach (self::$processes as $pid => $info) {
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGTERM);
                sleep(1);
                if (posix_kill($pid, 0)) {
                    posix_kill($pid, SIGKILL);
                }
                Logger::warning("Killed process {$pid} for site {$info['site_name']}");
            }
        }
        self::$processes = [];
    }
    
    private static function runCheckInChild(array $site): void {
        try {
            // Re-initialize resources in child process
            Database::$instance = null; // Reset database connection
            ConnectionPool::closeAllConnections();
            
            // Run the actual check
            $result = Checker::check($site);
            
            // Save result to temporary file for parent to read
            $resultFile = sys_get_temp_dir() . "/monitor_result_" . getmypid() . ".json";
            $data = [
                'site_id' => $site['id'],
                'pid' => getmypid(),
                'data' => $result,
                'timestamp' => microtime(true),
            ];
            
            file_put_contents($resultFile, json_encode($data), LOCK_EX);
            
        } catch (Throwable $e) {
            // Save error result
            $resultFile = sys_get_temp_dir() . "/monitor_result_" . getmypid() . ".json";
            $data = [
                'site_id' => $site['id'],
                'pid' => getmypid(),
                'data' => [
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                    'error_category' => 'child_process_error',
                ],
                'timestamp' => microtime(true),
            ];
            
            file_put_contents($resultFile, json_encode($data), LOCK_EX);
        }
    }
    
    public static function cleanup(): void {
        // Clean up any orphaned result files
        $tempDir = sys_get_temp_dir();
        $files = glob($tempDir . '/monitor_result_*.json');
        
        foreach ($files as $file) {
            $age = time() - filemtime($file);
            if ($age > 300) { // 5 minutes
                unlink($file);
            }
        }
        
        // Kill any remaining processes
        self::killAllProcesses();
    }
    
    public static function getStats(): array {
        return [
            'max_concurrent' => self::$maxConcurrent,
            'active_processes' => count(self::getActiveProcesses()),
            'total_results' => count(self::$results),
            'async_enabled' => ENABLE_ASYNC_CHECKS,
            'pcntl_available' => function_exists('pcntl_fork'),
            'posix_available' => function_exists('posix_kill'),
        ];
    }
}
