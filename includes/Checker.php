<?php
// =============================================================================
// includes/Checker.php - All monitoring check logic
// =============================================================================

class Checker {

    public static function check(array $site): array {
        $result = [
            'status'          => 'up',
            'response_time'   => 0,
            'error_message'   => null,
            'ssl_expiry_days' => null,
        ];

        $start = microtime(true);

        try {
            switch ($site['check_type']) {
                case 'http':    $result = array_merge($result, self::checkHttp($site));    break;
                case 'ssl':     $result = array_merge($result, self::checkSsl($site));     break;
                case 'port':    $result = array_merge($result, self::checkPort($site));    break;
                case 'dns':     $result = array_merge($result, self::checkDns($site));     break;
                case 'keyword': $result = array_merge($result, self::checkKeyword($site)); break;
                case 'ping':    $result = array_merge($result, self::checkPing($site));    break;
                case 'api':     $result = array_merge($result, self::checkApi($site));     break;
                default:        $result = array_merge($result, self::checkHttp($site));
            }
        } catch (Throwable $e) {
            $result['status']        = 'down';
            $result['error_message'] = 'Exception: ' . $e->getMessage();
        }

        if (empty($result['response_time'])) {
            $result['response_time'] = round((microtime(true) - $start) * 1000, 2);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // HTTP / HTTPS check
    // -------------------------------------------------------------------------
    private static function checkHttp(array $site): array {
        $start = microtime(true);

        // Build custom headers
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Accept-Encoding: gzip, deflate',
            'Connection: keep-alive',
            'Cache-Control: max-age=0',
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

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $site['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => CHECK_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => min(CHECK_TIMEOUT, 15),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => (stripos($site['url'], 'https://') === 0),
            CURLOPT_SSL_VERIFYHOST => (stripos($site['url'], 'https://') === 0) ? 2 : 0,
            CURLOPT_USERAGENT      => 'SiteMonitor/2.0 (https://github.com/sitemonitor)',
            CURLOPT_CERTINFO       => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => 'gzip, deflate',
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        // POST/PUT body
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($site['request_body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $site['request_body']);
        }

        $response     = curl_exec($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        $curlErrCode  = curl_errno($ch);
        $responseTime = round((microtime(true) - $start) * 1000, 2);

        // Extract SSL info
        $sslDays = null;
        if (strpos($site['url'], 'https://') === 0) {
            $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
            if (!empty($certInfo) && isset($certInfo[0]['Expire date'])) {
                $expiryTs = strtotime($certInfo[0]['Expire date']);
                $sslDays  = (int) ceil(($expiryTs - time()) / 86400);
            }
        }

        curl_close($ch);

        if ($curlError) {
            $errorMsg = match($curlErrCode) {
                CURLE_OPERATION_TIMEDOUT  => 'Connection timed out',
                CURLE_COULDNT_RESOLVE_HOST => 'DNS resolution failed',
                CURLE_COULDNT_CONNECT     => 'Connection refused',
                CURLE_SSL_CONNECT_ERROR   => 'SSL handshake failed',
                default                   => "cURL error ($curlErrCode): $curlError",
            };
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => $errorMsg, 'ssl_expiry_days' => $sslDays];
        }

        if ($httpCode === 0) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => 'No HTTP response received', 'ssl_expiry_days' => $sslDays];
        }

        $expected = (int) ($site['expected_status'] ?? 200);
        if ($httpCode !== $expected) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "Expected HTTP $expected, got $httpCode", 'ssl_expiry_days' => $sslDays];
        }

        // Response body validation
        if (!empty($site['response_contains']) && strpos($response, $site['response_contains']) === false) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "Response does not contain: \"{$site['response_contains']}\"", 'ssl_expiry_days' => $sslDays];
        }

        // JSON field validation
        if (!empty($site['json_path']) && !empty($site['json_expected'])) {
            $jsonResult = self::validateJsonResponse($response, $site['json_path'], $site['json_expected']);
            if ($jsonResult !== null) {
                return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => $jsonResult, 'ssl_expiry_days' => $sslDays];
            }
        }

        return ['status' => 'up', 'response_time' => $responseTime, 'ssl_expiry_days' => $sslDays];
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
        $host    = parse_url($site['url'], PHP_URL_HOST) ?: $site['hostname'] ?: $site['url'];
        $port    = $site['port'] ?: 443;
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
        ]]);

        $client = @stream_socket_client("ssl://$host:$port", $errno, $errstr, CHECK_TIMEOUT, STREAM_CLIENT_CONNECT, $context);
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
        $host  = $site['hostname'] ?: parse_url($site['url'], PHP_URL_HOST);
        $port  = (int) $site['port'];

        $conn = @fsockopen($host, $port, $errno, $errstr, CHECK_TIMEOUT);
        $responseTime = round((microtime(true) - $start) * 1000, 2);

        if (!$conn) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "Port $port unreachable: $errstr ($errno)"];
        }

        fclose($conn);
        return ['status' => 'up', 'response_time' => $responseTime];
    }

    // -------------------------------------------------------------------------
    // DNS record check
    // -------------------------------------------------------------------------
    private static function checkDns(array $site): array {
        $start   = microtime(true);
        $host    = $site['hostname'] ?: parse_url($site['url'], PHP_URL_HOST);
        $records = dns_get_record($host, DNS_A | DNS_AAAA | DNS_MX | DNS_CNAME) ?: [];
        $responseTime = round((microtime(true) - $start) * 1000, 2);

        if (empty($records)) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "No DNS records found for $host"];
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
            CURLOPT_TIMEOUT        => CHECK_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => CHECK_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
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
        $host  = parse_url($site['url'], PHP_URL_HOST) ?: $site['hostname'] ?: $site['url'];
        $host  = escapeshellarg($host);

        // Try system ping first (most accurate)
        if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            $output = [];
            $retval = 0;

            if (PHP_OS_FAMILY === 'Windows') {
                exec("ping -n 3 -w 3000 $host 2>&1", $output, $retval);
            } else {
                exec("ping -c 3 -W 3 $host 2>&1", $output, $retval);
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
        $conn = @fsockopen(trim($host, "'"), 80, $errno, $errstr, 5);
        $responseTime = round((microtime(true) - $start) * 1000, 2);

        if (!$conn) {
            // Try port 443
            $conn = @fsockopen(trim($host, "'"), 443, $errno, $errstr, 5);
            $responseTime = round((microtime(true) - $start) * 1000, 2);
            if (!$conn) {
                return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "Host unreachable (no response on 80/443)"];
            }
        }

        if ($conn) fclose($conn);
        return ['status' => 'up', 'response_time' => $responseTime];
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
}
