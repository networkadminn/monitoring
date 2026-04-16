<?php
// =============================================================================
// includes/ErrorHandler.php - Advanced error handling and resilience
// =============================================================================

class ErrorHandler {
    private static array $errorHandlers = [];
    private static array $fatalErrors = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    private static array $recoverableErrors = [E_WARNING, E_USER_WARNING, E_NOTICE, E_USER_NOTICE];
    private static string $logFile;
    private static bool $registered = false;
    
    public static function init(): void {
        if (self::$registered) {
            return;
        }
        
        self::$logFile = __DIR__ . '/../logs/errors_' . date('Y-m-d') . '.log';
        self::ensureLogDirectory();
        
        // Set up error handlers
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
        
        self::$registered = true;
    }
    
    public static function addHandler(callable $handler, int $priority = 0): void {
        self::$errorHandlers[] = ['handler' => $handler, 'priority' => $priority];
        // Sort by priority (higher first)
        usort(self::$errorHandlers, fn($a, $b) => $b['priority'] - $a['priority']);
    }
    
    public static function handleError(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
        // Don't handle suppressed errors
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        $error = [
            'type' => self::getErrorType($errno),
            'code' => $errno,
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'timestamp' => microtime(true),
            'context' => self::getErrorContext(),
            'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10),
        ];
        
        // Determine severity
        $severity = in_array($errno, self::$fatalErrors) ? 'fatal' : 
                   (in_array($errno, self::$recoverableErrors) ? 'warning' : 'notice');
        
        $error['severity'] = $severity;
        
        // Log the error
        self::logError($error);
        
        // Call custom handlers
        foreach (self::$errorHandlers as $handlerInfo) {
            try {
                call_user_func($handlerInfo['handler'], $error);
            } catch (Throwable $e) {
                // Don't let handler errors cause infinite loops
                error_log("Error in error handler: " . $e->getMessage());
            }
        }
        
        // For fatal errors, try to recover gracefully
        if (in_array($errno, self::$fatalErrors)) {
            self::handleFatalError($error);
        }
        
        return true;
    }
    
    public static function handleException(Throwable $exception): void {
        $error = [
            'type' => 'exception',
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'timestamp' => microtime(true),
            'context' => self::getErrorContext(),
            'stack_trace' => $exception->getTrace(),
            'previous' => $exception->getPrevious() ? get_class($exception->getPrevious()) : null,
        ];
        
        $error['severity'] = 'fatal';
        
        self::logError($error);
        
        // Call custom handlers
        foreach (self::$errorHandlers as $handlerInfo) {
            try {
                call_user_func($handlerInfo['handler'], $error);
            } catch (Throwable $e) {
                error_log("Error in exception handler: " . $e->getMessage());
            }
        }
        
        // Try graceful recovery
        self::handleFatalError($error);
    }
    
    public static function handleShutdown(): void {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], self::$fatalErrors)) {
            $errorData = [
                'type' => self::getErrorType($error['type']),
                'code' => $error['type'],
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'timestamp' => microtime(true),
                'context' => self::getErrorContext(),
                'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10),
                'severity' => 'fatal',
                'shutdown_function' => true,
            ];
            
            self::logError($errorData);
            self::handleFatalError($errorData);
        }
    }
    
    public static function wrapCallback(callable $callback, string $description = ''): callable {
        return function(...$args) use ($callback, $description) {
            try {
                return $callback(...$args);
            } catch (Throwable $e) {
                ErrorHandler::handleException($e);
                
                // Return default value or rethrow based on configuration
                if (defined('RETHROW_WRAPPED_EXCEPTIONS') && RETHROW_WRAPPED_EXCEPTIONS) {
                    throw $e;
                }
                
                return null;
            }
        };
    }
    
    public static function retry(callable $callback, int $maxAttempts = 3, int $delayMs = 1000, callable $shouldRetry = null): mixed {
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $maxAttempts) {
            $attempt++;
            
            try {
                return $callback($attempt);
            } catch (Throwable $e) {
                $lastException = $e;
                
                if ($shouldRetry && !$shouldRetry($e, $attempt)) {
                    break;
                }
                
                if ($attempt < $maxAttempts) {
                    // Exponential backoff with jitter
                    $delay = $delayMs * pow(2, $attempt - 1) + mt_rand(0, $delayMs / 4);
                    usleep($delay * 1000);
                    
                    self::logWarning("Retry attempt {$attempt} for " . self::getCallbackName($callback), [
                        'error' => $e->getMessage(),
                        'delay_ms' => $delay,
                        'next_attempt' => $attempt + 1,
                    ]);
                }
            }
        }
        
        throw $lastException;
    }
    
    public static function circuitBreak(callable $callback, string $serviceName, int $failureThreshold = 5, int $timeoutSeconds = 60): mixed {
        $cacheKey = "circuit_breaker_{$serviceName}";
        
        // Check circuit state
        $state = AdvancedCache::get($cacheKey, [
            'state' => 'closed',
            'failures' => 0,
            'last_failure' => 0,
            'next_attempt' => 0,
        ]);
        
        $now = time();
        
        // Reset if timeout has passed
        if ($state['state'] === 'open' && $now >= $state['next_attempt']) {
            $state = [
                'state' => 'half_open',
                'failures' => $state['failures'],
                'last_failure' => $state['last_failure'],
                'next_attempt' => $now + $timeoutSeconds / 2,
            ];
        }
        
        // Check if circuit is open
        if ($state['state'] === 'open') {
            throw new CircuitBreakerOpenException("Circuit breaker open for {$serviceName}");
        }
        
        try {
            $result = $callback();
            
            // Success - reset failures
            if ($state['failures'] > 0) {
                $state = [
                    'state' => 'closed',
                    'failures' => 0,
                    'last_failure' => 0,
                    'next_attempt' => 0,
                ];
                AdvancedCache::set($cacheKey, $state, $timeoutSeconds);
            }
            
            return $result;
            
        } catch (Throwable $e) {
            $state['failures']++;
            $state['last_failure'] = $now;
            
            if ($state['failures'] >= $failureThreshold) {
                $state['state'] = 'open';
                $state['next_attempt'] = $now + $timeoutSeconds;
                
                self::logError("Circuit breaker opened for {$serviceName}", [
                    'failures' => $state['failures'],
                    'threshold' => $failureThreshold,
                    'timeout_seconds' => $timeoutSeconds,
                    'next_attempt' => date('Y-m-d H:i:s', $state['next_attempt']),
                ]);
            }
            
            AdvancedCache::set($cacheKey, $state, $timeoutSeconds * 2);
            throw $e;
        }
    }
    
    public static function bulkhead(callable $callback, int $timeoutMs, callable $onTimeout = null): mixed {
        $startTime = microtime(true);
        
        // Set up timeout handler
        $timeoutHandler = function() use ($startTime, $timeoutMs, $onTimeout) {
            $elapsed = (microtime(true) - $startTime) * 1000;
            if ($elapsed > $timeoutMs) {
                if ($onTimeout) {
                    $onTimeout($elapsed);
                }
                throw new TimeoutException("Operation timed out after {$timeoutMs}ms");
            }
        };
        
        // For CLI, use alarm signal
        if (php_sapi_name() === 'cli' && function_exists('pcntl_signal')) {
            pcntl_signal(SIGALRM, $timeoutHandler);
            pcntl_alarm($timeoutMs / 1000);
        }
        
        try {
            $result = $callback();
            
            // Clear alarm if it was set
            if (php_sapi_name() === 'cli' && function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
            
            return $result;
            
        } catch (TimeoutException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Clear alarm on any exception
            if (php_sapi_name() === 'cli' && function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
            throw $e;
        }
    }
    
    public static function getErrorStats(): array {
        $logFile = self::$logFile;
        
        if (!file_exists($logFile)) {
            return [
                'total_errors' => 0,
                'fatal_errors' => 0,
                'warnings' => 0,
                'notices' => 0,
                'exceptions' => 0,
                'most_common' => [],
                'recent_errors' => [],
            ];
        }
        
        $logs = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $stats = [
            'total_errors' => count($logs),
            'fatal_errors' => 0,
            'warnings' => 0,
            'notices' => 0,
            'exceptions' => 0,
            'most_common' => [],
            'recent_errors' => [],
        ];
        
        $errorCounts = [];
        $recentErrors = [];
        $cutoff = time() - 3600; // Last hour
        
        foreach ($logs as $logEntry) {
            $error = json_decode($logEntry, true);
            if (!$error) continue;
            
            // Count by severity
            switch ($error['severity'] ?? 'unknown') {
                case 'fatal':
                    $stats['fatal_errors']++;
                    break;
                case 'warning':
                    $stats['warnings']++;
                    break;
                case 'notice':
                    $stats['notices']++;
                    break;
            }
            
            if ($error['type'] === 'exception') {
                $stats['exceptions']++;
            }
            
            // Count by message
            $message = substr($error['message'] ?? '', 0, 100);
            $errorCounts[$message] = ($errorCounts[$message] ?? 0) + 1;
            
            // Recent errors
            if (($error['timestamp'] ?? 0) > $cutoff) {
                $recentErrors[] = [
                    'message' => $error['message'],
                    'severity' => $error['severity'],
                    'timestamp' => $error['timestamp'],
                ];
            }
        }
        
        // Get most common errors
        arsort($errorCounts);
        $stats['most_common'] = array_slice($errorCounts, 0, 10, true);
        $stats['recent_errors'] = array_slice($recentErrors, 0, 10);
        
        return $stats;
    }
    
    private static function logError(array $error): void {
        $logEntry = json_encode($error) . PHP_EOL;
        
        // Write to error log file
        file_put_contents(self::$logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // Also log to system error log
        $message = "[{$error['severity']}] {$error['message']} in {$error['file']}:{$error['line']}";
        error_log($message);
        
        // Send to external monitoring if configured
        if (defined('ENABLE_EXTERNAL_ERROR_LOGGING') && ENABLE_EXTERNAL_ERROR_LOGGING) {
            self::sendToExternalService($error);
        }
    }
    
    private static function handleFatalError(array $error): void {
        // Try to send a graceful response if we're in a web context
        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            http_response_code(500);
            
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                echo json_encode([
                    'error' => 'Internal Server Error',
                    'details' => $error,
                    'timestamp' => date('Y-m-d H:i:s'),
                ], JSON_PRETTY_PRINT);
            } else {
                echo 'Internal Server Error';
            }
        }
        
        // Log metrics
        MetricsCollector::recordError($error['type'], $error['message'], [
            'file' => $error['file'],
            'line' => $error['line'],
            'severity' => $error['severity'],
        ]);
    }
    
    private static function getErrorType(int $errno): string {
        return match($errno) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'UNKNOWN',
        };
    }
    
    private static function getErrorContext(): array {
        $context = [];
        
        // Include request context if available
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $context['request'] = [
                'method' => $_SERVER['REQUEST_METHOD'],
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ];
        }
        
        // Include memory usage
        $context['memory'] = [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
        ];
        
        // Include process info
        $context['process'] = [
            'pid' => getmypid(),
            'php_version' => PHP_VERSION,
            'sapi' => php_sapi_name(),
        ];
        
        return $context;
    }
    
    private static function getCallbackName(callable $callback): string {
        if (is_string($callback)) {
            return $callback;
        }
        
        if (is_array($callback)) {
            if (is_object($callback[0])) {
                return get_class($callback[0]) . '::' . $callback[1];
            } else {
                return $callback[0] . '::' . $callback[1];
            }
        }
        
        if (is_object($callback)) {
            return get_class($callback);
        }
        
        return 'anonymous';
    }
    
    private static function ensureLogDirectory(): void {
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    private static function sendToExternalService(array $error): void {
        // Implementation would depend on external service
        // Could send to Sentry, Bugsnag, etc.
        if (defined('SENTRY_DSN') && SENTRY_DSN) {
            // Sentry integration example
            // This would require the Sentry SDK
        }
    }
}

class CircuitBreakerOpenException extends Exception {
    public function __construct(string $message, int $code = 0, Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

class TimeoutException extends Exception {
    public function __construct(string $message, int $code = 0, Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}
