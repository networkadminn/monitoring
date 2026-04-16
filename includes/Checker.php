<?php
// =============================================================================
// includes/Checker.php - All monitoring check logic
// =============================================================================

require_once __DIR__ . '/CircuitBreaker.php';
require_once __DIR__ . '/ConnectionPool.php';
require_once __DIR__ . '/Logger.php';

class Checker {

    public static function check(array $site): array {
        $siteId = (int) $site['id'];
        
        // Check circuit breaker state
        if (!CircuitBreaker::check($siteId)) {
            $state = CircuitBreaker::getState($siteId);
            return [
                'status' => 'down',
                'response_time' => 0,
                'error_message' => "Circuit breaker open ({$state['failure_count']} failures)",
                'error_category' => 'circuit_breaker',
                'circuit_breaker_state' => $state['state'],
                'retry_count' => 0,
            ];
        }
        
        $result = [
            'status'          => 'up',
            'response_time'   => 0,
            'error_message'   => null,
            'ssl_expiry_days' => null,
            'performance'     => [],
            'content_check'   => [],
            'ssl_details'     => [],
            'error_category'  => null,
            'connectivity'    => [],
            'retry_count'     => 0,
        ];

        $start = microtime(true);
        $lastError = null;
        
        // Implement retry logic with exponential backoff
        $maxRetries = RETRY_FAILED_CHECKS ? MAX_RETRIES : 0;
        
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $attemptStart = microtime(true);
            
            try {
                switch ($site['check_type']) {
                    case 'http':    $checkResult = self::checkHttp($site);    break;
                    case 'ssl':     $checkResult = self::checkSsl($site);     break;
                    case 'port':    $checkResult = self::checkPort($site);    break;
                    case 'dns':     $checkResult = self::checkDns($site);     break;
                    case 'keyword': $checkResult = self::checkKeyword($site); break;
                    case 'ping':    $checkResult = self::checkPing($site);    break;
                    case 'api':     $checkResult = self::checkApi($site);     break;
                    default:        $checkResult = self::checkHttp($site);
                }
                
                $result = array_merge($result, $checkResult);
                
                // If check succeeded, record success and break
                if ($result['status'] === 'up') {
                    CircuitBreaker::recordSuccess($siteId);
                    break;
                }
                
                $lastError = $result['error_message'];
                
                // If this is not the last attempt, wait with exponential backoff
                if ($attempt < $maxRetries) {
                    $result['retry_count'] = $attempt + 1;
                    $delay = self::calculateBackoffDelay($attempt);
                    usleep($delay * 1000); // Convert milliseconds to microseconds
                }
                
            } catch (Throwable $e) {
                $lastError = 'Exception: ' . $e->getMessage();
                $result['error_category'] = 'system_error';
                
                if ($attempt < $maxRetries) {
                    $result['retry_count'] = $attempt + 1;
                    $delay = self::calculateBackoffDelay($attempt);
                    usleep($delay * 1000);
                }
            }
        }
        
        // Record failure if all retries failed
        if ($result['status'] === 'down') {
            CircuitBreaker::recordFailure($siteId);
        }
        
        // If all retries failed, set final error message
        if ($result['status'] === 'down' && $lastError) {
            $result['error_message'] = $lastError;
            if ($result['retry_count'] > 0) {
                $result['error_message'] .= " (failed after {$result['retry_count']} retries)";
            }
        }

        if (empty($result['response_time'])) {
            $result['response_time'] = round((microtime(true) - $start) * 1000, 2);
        }

        // Log the check result
        Logger::checkResult($siteId, $site, $result);

        return $result;
    }

    // -------------------------------------------------------------------------
    // HTTP / HTTPS check - Enhanced with detailed monitoring
    // -------------------------------------------------------------------------
    private static function checkHttp(array $site): array {
        $start = microtime(true);
        $dnsStart = microtime(true);
        $connectStart = null;
        $ttfbStart = null;
        
        $result = [
            'performance' => [],
            'content_check' => [],
            'ssl_details' => [],
            'error_category' => null,
            'connectivity' => [],
        ];

        // Build custom headers with proper Accept-Encoding
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Accept-Encoding: gzip, deflate',  // Removed 'br' to fix encoding issues
            'Connection: keep-alive',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ];

        // Custom headers from site config
        if (!empty($site['custom_headers'])) {
            $customLines = array_filter(array_map('trim', explode("\n", $site['custom_headers'])));
            foreach ($customLines as $line) {
                if (strpos($line, ':') !== false) {
                    $headers[] = $line;
                }
            }
        }

        // Auth header
        if (!empty($site['auth_type'])) {
            switch ($site['auth_type']) {
                case 'bearer':
                    if (!empty($site['auth_value'])) {
                        $headers[] = 'Authorization: Bearer ' . $site['auth_value'];
                    }
                    break;
                case 'basic':
                    if (!empty($site['auth_value'])) {
                        $headers[] = 'Authorization: Basic ' . base64_encode($site['auth_value']);
                    }
                    break;
                case 'apikey':
                    if (!empty($site['auth_header']) && !empty($site['auth_value'])) {
                        $headers[] = $site['auth_header'] . ': ' . $site['auth_value'];
                    }
                    break;
            }
        }

        $method = strtoupper($site['http_method'] ?? 'GET');
        $parsedUrl = parse_url($site['url']);
        $host = $parsedUrl['host'] ?? '';
        $port = $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? 443 : 80);

        // Try to get connection from pool
        $ch = ConnectionPool::getConnection($host, $port);
        if (!$ch) {
            $ch = curl_init();
        }
        curl_setopt_array($ch, [
            CURLOPT_URL            => $site['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => min(HTTP_TIMEOUT, 15),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => MAX_REDIRECTS,
            CURLOPT_SSL_VERIFYPEER => (stripos($site['url'], 'https://') === 0),
            CURLOPT_SSL_VERIFYHOST => (stripos($site['url'], 'https://') === 0) ? 2 : 0,
            CURLOPT_USERAGENT      => 'SiteMonitor/2.0 (https://github.com/sitemonitor)',
            CURLOPT_CERTINFO       => ENABLE_SSL_CHAIN_ANALYSIS,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '',  // Empty string to accept all encodings cURL supports
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HEADER         => true,
            CURLOPT_NOPROGRESS     => !ENABLE_PERFORMANCE_METRICS,
            CURLOPT_FAILONERROR    => false,  // Don't fail on HTTP errors
            CURLOPT_VERBOSE        => false,  // Set to true for debugging
        ]);
        
        // Only set progress function if performance metrics are enabled
        if (ENABLE_PERFORMANCE_METRICS) {
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($ch, $downloadSize, $downloaded, $uploadSize, $uploaded) use (&$connectStart, &$ttfbStart) {
                if ($downloaded > 0 && $ttfbStart === null) {
                    $ttfbStart = microtime(true);
                }
            });
        }
        
        // Add fallback mechanism for failed requests
        $fallbackResponse = null;
        $originalEncoding = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        // POST/PUT body
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($site['request_body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $site['request_body']);
        }

        $response     = curl_exec($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        $curlErrCode  = curl_errno($ch);
        $responseTime = round((microtime(true) - $start) * 1000, 2);
        
        // Fallback mechanism for encoding errors
        if ($curlErrCode === CURLE_BAD_CONTENT_ENCODING && $fallbackResponse === null) {
            // Try again without encoding
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Connection: keep-alive',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ]);
            $fallbackResponse = curl_exec($ch);
            $curlError = curl_error($ch);
            $curlErrCode = curl_errno($ch);
            
            if (!$curlError && $fallbackResponse) {
                $response = $fallbackResponse;
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            }
        }

        // Enhanced performance metrics (conditional)
        if (ENABLE_PERFORMANCE_METRICS) {
            $dnsTime = round((microtime(true) - $dnsStart) * 1000, 2);
            $connectTime = curl_getinfo($ch, CURLINFO_CONNECT_TIME) * 1000;
            $ttfb = curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000;
            $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
            $redirectCount = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
            $downloadSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
            $uploadSize = curl_getinfo($ch, CURLINFO_SIZE_UPLOAD);
            $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            
            $result['performance'] = [
                'dns_time' => round($dnsTime, 2),
                'connect_time' => round($connectTime, 2),
                'ttfb' => round($ttfb, 2),
                'total_time' => round($totalTime, 2),
                'redirect_count' => $redirectCount,
                'download_size' => round($downloadSize, 2),
                'upload_size' => round($uploadSize, 2),
                'content_length' => round($contentLength, 2),
            ];
        }

        // Parse response headers and body
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);
        
        // Content analysis (conditional)
        if (ENABLE_CONTENT_ANALYSIS) {
            $result['content_check'] = self::analyzeContent($responseBody, $site);
        }
        
        // Enhanced SSL details (conditional)
        $sslDays = null;
        if (strpos($site['url'], 'https://') === 0) {
            $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
            if (ENABLE_SSL_CHAIN_ANALYSIS && !empty($certInfo)) {
                $result['ssl_details'] = self::analyzeSslChain($certInfo);
            }
            if (!empty($certInfo) && isset($certInfo[0]['Expire date'])) {
                $expiryTs = strtotime($certInfo[0]['Expire date']);
                $sslDays  = (int) ceil(($expiryTs - time()) / 86400);
            }
        }
        
        // Connectivity analysis (always enabled for basic monitoring)
        $result['connectivity'] = self::analyzeConnectivity($site['url'], $responseHeaders, $httpCode);

        // Release connection back to pool
        ConnectionPool::releaseConnection($ch, $host, $port);

        if ($curlError) {
            $result['error_category'] = self::categorizeError($curlErrCode);
            
            // Enhanced error handling with debug info
            $debugInfo = [
                'curl_code' => $curlErrCode,
                'curl_error' => $curlError,
                'http_code' => $httpCode,
                'url' => $site['url'],
                'timeout' => HTTP_TIMEOUT,
            ];
            
            $errorMsg = match($curlErrCode) {
                CURLE_OPERATION_TIMEDOUT  => 'Connection timed out after ' . HTTP_TIMEOUT . 's',
                CURLE_COULDNT_RESOLVE_HOST => 'DNS resolution failed for ' . parse_url($site['url'], PHP_URL_HOST),
                CURLE_COULDNT_CONNECT     => 'Connection refused - server may be down',
                CURLE_SSL_CONNECT_ERROR   => 'SSL handshake failed - certificate issues',
                CURLE_SSL_CERTPROBLEM     => 'SSL certificate problem detected',
                CURLE_SSL_CACERT          => 'SSL CA certificate verification failed',
                CURLE_UNSUPPORTED_PROTOCOL => 'Unsupported protocol - check URL format',
                default                   => "Network error ($curlErrCode): " . htmlspecialchars($curlError, ENT_QUOTES, 'UTF-8'),
            };
            
            // Log debug information
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log("SiteMonitor Debug: " . json_encode($debugInfo));
            }
            
            return array_merge(['status' => 'down', 'response_time' => $responseTime, 'error_message' => $errorMsg, 'ssl_expiry_days' => $sslDays], $result);
        }

        if ($httpCode === 0) {
            $result['error_category'] = 'network_error';
            return array_merge(['status' => 'down', 'response_time' => $responseTime, 'error_message' => 'No HTTP response received - possible network issue', 'ssl_expiry_days' => $sslDays], $result);
        }

        $expected = (int) ($site['expected_status'] ?? 200);
        if ($httpCode !== $expected) {
            $result['error_category'] = self::categorizeHttpError($httpCode);
            $errorMsg = "Expected HTTP $expected, got $httpCode";
            if ($httpCode >= 500) {
                $errorMsg .= ' - Server error';
            } elseif ($httpCode >= 400) {
                $errorMsg .= ' - Client error';
            }
            return array_merge(['status' => 'down', 'response_time' => $responseTime, 'error_message' => $errorMsg, 'ssl_expiry_days' => $sslDays], $result);
        }

        // Enhanced response body validation
        if (!empty($site['response_contains']) && strpos($responseBody, $site['response_contains']) === false) {
            $result['error_category'] = 'content_validation';
            return array_merge(['status' => 'down', 'response_time' => $responseTime, 'error_message' => "Response does not contain: \"{$site['response_contains']}\"", 'ssl_expiry_days' => $sslDays], $result);
        }

        // Enhanced JSON field validation
        if (!empty($site['json_path']) && !empty($site['json_expected'])) {
            $jsonResult = self::validateJsonResponse($responseBody, $site['json_path'], $site['json_expected']);
            if ($jsonResult !== null) {
                $result['error_category'] = 'content_validation';
                return array_merge(['status' => 'down', 'response_time' => $responseTime, 'error_message' => $jsonResult, 'ssl_expiry_days' => $sslDays], $result);
            }
        }

        return array_merge(['status' => 'up', 'response_time' => $responseTime, 'ssl_expiry_days' => $sslDays], $result);
    }

    // -------------------------------------------------------------------------
    // API check (POST/PUT with JSON body + response validation)
    // -------------------------------------------------------------------------
    private static function checkApi(array $site): array {
        // Reuse HTTP check with API-specific defaults
        $site['http_method'] = $site['http_method'] ?? 'POST';
        if (empty($site['custom_headers'])) {
            $site['custom_headers'] = 'Content-Type: application/json';
        }
        return self::checkHttp($site);
    }

    // -------------------------------------------------------------------------
    // SSL certificate expiry check
    // -------------------------------------------------------------------------
    private static function checkSsl(array $site): array {
        $start   = microtime(true);
        $host    = parse_url($site['url'], PHP_URL_HOST) ?? $site['hostname'] ?? $site['url'] ?? '';
        $port    = (int) ($site['port'] ?? 443);
        
        if (empty($host)) {
            return ['status' => 'down', 'response_time' => round((microtime(true) - $start) * 1000, 2), 'error_message' => 'No hostname specified'];
        }
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
        ]]);

        $client = @stream_socket_client("ssl://$host:$port", $errno, $errstr, SSL_TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        $responseTime = round((microtime(true) - $start) * 1000, 2);

        if (!$client) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "SSL connect failed: $errstr ($errno)"];
        }

        $params = stream_context_get_params($client);
        $cert   = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? '');
        fclose($client);

        if (!$cert) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => 'Could not parse SSL certificate'];
        }

        $daysLeft = (int) ceil(($cert['validTo_time_t'] - time()) / 86400);
        $status   = $daysLeft <= 7 ? 'down' : ($daysLeft <= 30 ? 'warning' : 'up');
        $errorMsg = $daysLeft <= 30 ? "SSL expires in $daysLeft days" : null;

        // Also check subject/issuer info
        $subject = $cert['subject']['CN'] ?? 'Unknown';
        $issuer  = $cert['issuer']['O'] ?? 'Unknown';

        return [
            'status'          => $status,
            'response_time'   => $responseTime,
            'ssl_expiry_days' => $daysLeft,
            'error_message'   => $errorMsg,
            'ssl_subject'     => $subject,
            'ssl_issuer'      => $issuer,
        ];
    }

    // -------------------------------------------------------------------------
    // TCP port check
    // -------------------------------------------------------------------------
    private static function checkPort(array $site): array {
        $start = microtime(true);
        $host  = $site['hostname'] ?? parse_url($site['url'], PHP_URL_HOST) ?? $site['url'] ?? '';
        $port  = (int) ($site['port'] ?? 80);
        
        if (empty($host)) {
            return ['status' => 'down', 'response_time' => round((microtime(true) - $start) * 1000, 2), 'error_message' => 'No hostname specified'];
        }

        $conn = @fsockopen($host, $port, $errno, $errstr, PORT_TIMEOUT);
        $responseTime = round((microtime(true) - $start) * 1000, 2);

        if (!$conn) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "Port $port unreachable: $errstr ($errno)"];
        }

        if ($conn) fclose($conn);
        return ['status' => 'up', 'response_time' => $responseTime];
    }

    // -------------------------------------------------------------------------
    // DNS record check - Enhanced with better error handling
    // -------------------------------------------------------------------------
    private static function checkDns(array $site): array {
        $start   = microtime(true);
        $host    = $site['hostname'] ?? parse_url($site['url'], PHP_URL_HOST) ?? $site['url'] ?? '';
        
        if (empty($host)) {
            return ['status' => 'down', 'response_time' => round((microtime(true) - $start) * 1000, 2), 'error_message' => 'No hostname specified'];
        }
        
        // Enhanced DNS resolution with multiple record types and fallback
        $records = [];
        $errors = [];
        
        // Try different DNS record types separately for better error handling
        $recordTypes = [DNS_A, DNS_AAAA, DNS_MX, DNS_CNAME];
        foreach ($recordTypes as $type) {
            try {
                $typeRecords = @dns_get_record($host, $type);
                if ($typeRecords !== false && is_array($typeRecords)) {
                    $records = array_merge($records, $typeRecords);
                }
            } catch (Exception $e) {
                $errors[] = "DNS type $type: " . $e->getMessage();
            }
        }
        
        $responseTime = round((microtime(true) - $start) * 1000, 2);

        if (empty($records)) {
            // Try fallback with gethostbyname for basic A record
            $fallbackIp = @gethostbyname($host);
            if ($fallbackIp !== $host) {
                // Fallback succeeded
                $records = [['type' => 'A', 'ip' => $fallbackIp, 'host' => $host]];
            } else {
                // All DNS resolution failed
                $errorMsg = "DNS resolution failed for $host";
                if (!empty($errors)) {
                    $errorMsg .= " - " . implode('; ', array_slice($errors, 0, 2));
                }
                return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => $errorMsg];
            }
        }

        if (!empty($site['keyword'])) {
            $ips = array_column(array_filter($records, fn($r) => isset($r['ip'])), 'ip');
            if (!in_array($site['keyword'], $ips)) {
                return ['status' => 'warning', 'response_time' => $responseTime, 'error_message' => "Expected IP {$site['keyword']} not found in DNS"];
            }
        }

        return ['status' => 'up', 'response_time' => $responseTime];
    }

    // -------------------------------------------------------------------------
    // Keyword presence check
    // -------------------------------------------------------------------------
    private static function checkKeyword(array $site): array {
        $start   = microtime(true);
        $keyword = $site['keyword'] ?? '';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $site['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => min(HTTP_TIMEOUT, 15),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => MAX_REDIRECTS,
            CURLOPT_SSL_VERIFYPEER => (stripos($site['url'], 'https://') === 0),
            CURLOPT_SSL_VERIFYHOST => (stripos($site['url'], 'https://') === 0) ? 2 : 0,
            CURLOPT_USERAGENT      => 'SiteMonitor/2.0',
            CURLOPT_ENCODING       => 'gzip, deflate',
            CURLOPT_HEADER         => false,
        ]);

        $response     = curl_exec($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        $responseTime = round((microtime(true) - $start) * 1000, 2);

        if ($curlError) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "cURL error: $curlError"];
        }

        if ($httpCode >= 400) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "HTTP $httpCode error"];
        }

        if ($keyword && strpos($response, $keyword) === false) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "Keyword \"$keyword\" not found on page"];
        }

        return ['status' => 'up', 'response_time' => $responseTime];
    }

    // -------------------------------------------------------------------------
    // Ping / ICMP check (NEW)
    // -------------------------------------------------------------------------
    private static function checkPing(array $site): array {
        $start = microtime(true);
        $host  = parse_url($site['url'], PHP_URL_HOST) ?? $site['hostname'] ?? $site['url'] ?? '';
        
        if (empty($host)) {
            return ['status' => 'down', 'response_time' => round((microtime(true) - $start) * 1000, 2), 'error_message' => 'No hostname specified'];
        }
        
        $host  = escapeshellarg($host);

        // Try system ping first (most accurate)
        if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            $output = [];
            $retval = 0;

            if (PHP_OS_FAMILY === 'Windows') {
                exec("ping -n 3 -w " . (PING_TIMEOUT * 1000) . " $host 2>&1", $output, $retval);
            } else {
                exec("ping -c 3 -W " . PING_TIMEOUT . " $host 2>&1", $output, $retval);
            }

            $responseTime = round((microtime(true) - $start) * 1000, 2);

            if ($retval !== 0) {
                return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "Host unreachable (ping failed)"];
            }

            // Extract avg RTT from ping output
            $outputStr = implode("\n", $output);
            if (preg_match('/(?:avg|Average)[^\d]*([\d.]+)/i', $outputStr, $m)) {
                $responseTime = (float) $m[1];
            }

            return ['status' => 'up', 'response_time' => $responseTime];
        }

        // Fallback: TCP connect to port 80 as ping substitute
        $conn = @fsockopen(trim($host, "'"), 80, $errno, $errstr, PING_TIMEOUT);
        $responseTime = round((microtime(true) - $start) * 1000, 2);

        if (!$conn) {
            // Try port 443
            $conn = @fsockopen(trim($host, "'"), 443, $errno, $errstr, PING_TIMEOUT);
            $responseTime = round((microtime(true) - $start) * 1000, 2);
            if (!$conn) {
                return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "Host unreachable (no response on 80/443)"];
            }
        }

        if ($conn) fclose($conn);
        return ['status' => 'up', 'response_time' => $responseTime];
    }

    // -------------------------------------------------------------------------
    // Content analysis helper
    // -------------------------------------------------------------------------
    private static function analyzeContent(string $body, array $site): array {
        // Limit content size to prevent memory issues
        $maxSize = 1024 * 1024; // 1MB limit
        if (strlen($body) > $maxSize) {
            $body = substr($body, 0, $maxSize);
        }
        
        $analysis = [
            'size_bytes' => strlen($body),
            'word_count' => str_word_count(strip_tags($body)),
            'has_html' => stripos($body, '<html') !== false || stripos($body, '<!DOCTYPE') !== false,
            'has_json' => json_decode($body) !== null,
            'has_xml' => false,
            'encoding' => mb_detect_encoding($body, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true),
            'compressed' => function_exists('gzdecode') && @gzdecode($body) !== false,
            'truncated' => strlen($body) === $maxSize,
        ];
        
        // Safer XML detection - only try to parse if it looks like XML
        $trimmedBody = trim($body);
        if (stripos($trimmedBody, '<?xml') === 0) {
            try {
                $xml = @simplexml_load_string($body);
                $analysis['has_xml'] = $xml !== false;
            } catch (Exception $e) {
                $analysis['has_xml'] = false;
            }
        }
        
        // Check for common error patterns
        $errorPatterns = [
            'database error' => '/(database|mysql|sql) error/i',
            'php error' => '/(fatal error|warning|notice):/i',
            'server error' => '/(internal server error|500 error)/i',
            'maintenance' => '/(maintenance|under construction|temporarily unavailable)/i',
            'rate limit' => '/(rate limit|too many requests|429)/i',
        ];
        
        $analysis['error_patterns'] = [];
        foreach ($errorPatterns as $type => $pattern) {
            if (preg_match($pattern, $body)) {
                $analysis['error_patterns'][] = $type;
            }
        }
        
        // Check for performance indicators
        $analysis['performance_indicators'] = [
            'has_cdn' => preg_match('/(cloudflare|fastly|akamai|cloudfront)/i', $body),
            'has_cache_headers' => preg_match('/(cache-control|etag|last-modified)/i', $body),
            'has_compression' => preg_match('/(content-encoding|gzip|deflate)/i', $body),
        ];
        
        return $analysis;
    }
    
    // -------------------------------------------------------------------------
    // SSL chain analysis helper
    // -------------------------------------------------------------------------
    private static function analyzeSslChain(array $certInfo): array {
        $analysis = [
            'chain_length' => count($certInfo),
            'certificates' => [],
            'issues' => [],
        ];
        
        foreach ($certInfo as $index => $cert) {
            $certData = [
                'subject' => $cert['Subject'] ?? 'Unknown',
                'issuer' => $cert['Issuer'] ?? 'Unknown',
                'version' => $cert['Version'] ?? 'Unknown',
                'signature_algorithm' => $cert['Signature Type'] ?? 'Unknown',
                'public_key' => $cert['Public Key'] ?? 'Unknown',
            ];
            
            if (isset($cert['Expire date'])) {
                $expiryTs = strtotime($cert['Expire date']);
                $certData['expiry_date'] = $cert['Expire date'];
                $certData['days_until_expiry'] = (int) ceil(($expiryTs - time()) / 86400);
            }
            
            if (isset($cert['Start date'])) {
                $certData['issue_date'] = $cert['Start date'];
            }
            
            // Check for common SSL issues
            if ($index === 0) { // Leaf certificate
                if (isset($certData['days_until_expiry']) && $certData['days_until_expiry'] < 7) {
                    $analysis['issues'][] = 'Certificate expires in less than 7 days';
                }
                if (isset($certData['days_until_expiry']) && $certData['days_until_expiry'] < 0) {
                    $analysis['issues'][] = 'Certificate has expired';
                }
                if (stripos($certData['subject'], 'localhost') !== false || stripos($certData['subject'], '127.0.0.1') !== false) {
                    $analysis['issues'][] = 'Using localhost certificate';
                }
            }
            
            $analysis['certificates'][] = $certData;
        }
        
        return $analysis;
    }
    
    // -------------------------------------------------------------------------
    // Connectivity analysis helper
    // -------------------------------------------------------------------------
    private static function analyzeConnectivity(string $url, string $headers, int $httpCode): array {
        $analysis = [
            'protocol' => parse_url($url, PHP_URL_SCHEME) ?: 'unknown',
            'http_version' => 'unknown',
            'response_headers' => [],
            'server_info' => [],
            'security_headers' => [],
        ];
        
        // Extract HTTP version from headers
        if (preg_match('/^HTTP\/([0-9\.]+)/m', $headers, $matches)) {
            $analysis['http_version'] = $matches[1];
        }
        
        // Parse response headers
        $headerLines = explode("\r\n", $headers);
        foreach ($headerLines as $line) {
            if (strpos($line, ':') !== false && !preg_match('/^HTTP\//', $line)) {
                list($key, $value) = explode(':', $line, 2);
                $key = trim(strtolower($key));
                $value = trim($value);
                $analysis['response_headers'][$key] = $value;
                
                // Server information
                if ($key === 'server') {
                    $analysis['server_info']['software'] = $value;
                }
                
                // Security headers
                $securityHeaders = [
                    'strict-transport-security', 'content-security-policy', 
                    'x-frame-options', 'x-content-type-options',
                    'x-xss-protection', 'referrer-policy'
                ];
                if (in_array($key, $securityHeaders)) {
                    $analysis['security_headers'][$key] = $value;
                }
            }
        }
        
        // Analyze response time indicators
        if (isset($analysis['response_headers']['x-cache']) || isset($analysis['response_headers']['x-cdn'])) {
            $analysis['cache_indicators'] = 'CDN detected';
        }
        
        return $analysis;
    }
    
    // -------------------------------------------------------------------------
    // Error categorization helper
    // -------------------------------------------------------------------------
    private static function categorizeError(int $curlErrCode): string {
        return match($curlErrCode) {
            CURLE_OPERATION_TIMEDOUT => 'timeout',
            CURLE_COULDNT_RESOLVE_HOST => 'dns_error',
            CURLE_COULDNT_CONNECT => 'connection_refused',
            CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CACERT => 'ssl_error',
            CURLE_COULDNT_RESOLVE_PROXY => 'proxy_error',
            CURLE_RECV_ERROR => 'network_error',
            CURLE_SEND_ERROR => 'network_error',
            default => 'unknown_error',
        };
    }
    
    // -------------------------------------------------------------------------
    // HTTP error categorization helper
    // -------------------------------------------------------------------------
    private static function categorizeHttpError(int $httpCode): string {
        return match(true) {
            $httpCode >= 500 => 'server_error',
            $httpCode >= 400 => 'client_error',
            $httpCode >= 300 => 'redirect_error',
            default => 'http_error',
        };
    }

    // -------------------------------------------------------------------------
    // JSON response validation helper
    // -------------------------------------------------------------------------
    private static function validateJsonResponse(string $body, string $path, string $expected): ?string {
        $data = json_decode($body, true);
        if ($data === null) {
            return 'Response is not valid JSON';
        }

        // Simple dot-notation path traversal (e.g. "status.code" or "data.0.id")
        $keys  = explode('.', $path);
        $value = $data;
        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return "JSON path \"$path\" not found in response";
            }
            $value = $value[$key];
        }

        $actual = is_scalar($value) ? (string) $value : json_encode($value);
        if ($actual !== $expected) {
            return "JSON \"$path\" = \"$actual\", expected \"$expected\"";
        }

        return null; // validation passed
    }
    
    // -------------------------------------------------------------------------
    // Exponential backoff calculation
    // -------------------------------------------------------------------------
    private static function calculateBackoffDelay(int $attempt): int {
        // Base delay with exponential backoff: base * (2^attempt) + jitter
        $baseDelay = RETRY_DELAY;
        $exponentialDelay = $baseDelay * pow(2, $attempt);
        
        // Add jitter to prevent thundering herd
        $jitter = mt_rand(0, (int)($exponentialDelay * 0.1));
        
        // Cap at maximum delay (5 seconds)
        $maxDelay = 5000;
        
        return min($exponentialDelay + $jitter, $maxDelay);
    }
}
