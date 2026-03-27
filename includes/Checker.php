<?php
// =============================================================================
// includes/Checker.php - All monitoring check logic
// =============================================================================

class Checker {

    // -------------------------------------------------------------------------
    // Main dispatcher: run the correct check based on site type
    // -------------------------------------------------------------------------
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
                case 'http':
                    $result = array_merge($result, self::checkHttp($site));
                    break;
                case 'ssl':
                    $result = array_merge($result, self::checkSsl($site));
                    break;
                case 'port':
                    $result = array_merge($result, self::checkPort($site));
                    break;
                case 'dns':
                    $result = array_merge($result, self::checkDns($site));
                    break;
                case 'keyword':
                    $result = array_merge($result, self::checkKeyword($site));
                    break;
                default:
                    $result = array_merge($result, self::checkHttp($site));
            }
        } catch (Throwable $e) {
            $result['status']        = 'down';
            $result['error_message'] = 'Exception: ' . $e->getMessage();
        }

        // Ensure response_time is always set
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

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $site['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => CHECK_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => CHECK_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => false, // SSL checked separately
            CURLOPT_USERAGENT      => 'SiteMonitor/1.0',
            CURLOPT_NOBODY         => false,
            CURLOPT_CERTINFO       => true, // Capture SSL info
        ]);

        $response     = curl_exec($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        
        // Extract SSL info if HTTPS
        $sslDays = null;
        if (strpos($site['url'], 'https://') === 0) {
            $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
            if (!empty($certInfo) && isset($certInfo[0]['Expire date'])) {
                $expiryTs = strtotime($certInfo[0]['Expire date']);
                $sslDays  = (int) ceil(($expiryTs - time()) / 86400);
            }
        }
        
        curl_close($ch);

        $responseTime = round((microtime(true) - $start) * 1000, 2);
        $expected     = (int) ($site['expected_status'] ?? 200);

        if ($curlError) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "cURL error: $curlError", 'ssl_expiry_days' => $sslDays];
        }

        if ($httpCode !== $expected) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "Expected HTTP $expected, got $httpCode", 'ssl_expiry_days' => $sslDays];
        }

        return ['status' => 'up', 'response_time' => $responseTime, 'ssl_expiry_days' => $sslDays];
    }

    // -------------------------------------------------------------------------
    // SSL certificate expiry check
    // -------------------------------------------------------------------------
    private static function checkSsl(array $site): array {
        $start    = microtime(true);
        $host     = parse_url($site['url'], PHP_URL_HOST) ?: $site['hostname'] ?: $site['url'];
        $port     = $site['port'] ?: 443;
        $context  = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);

        $client = @stream_socket_client(
            "ssl://$host:$port",
            $errno, $errstr,
            CHECK_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

        $responseTime = round((microtime(true) - $start) * 1000, 2);

        if (!$client) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "SSL connect failed: $errstr ($errno)"];
        }

        $params  = stream_context_get_params($client);
        $cert    = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? '');
        fclose($client);

        if (!$cert) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => 'Could not parse SSL certificate'];
        }

        $expiry      = $cert['validTo_time_t'];
        $daysLeft    = (int) ceil(($expiry - time()) / 86400);
        $status      = $daysLeft <= 7 ? 'down' : ($daysLeft <= 30 ? 'warning' : 'up');
        $errorMsg    = $daysLeft <= 30 ? "SSL expires in $daysLeft days" : null;

        return ['status' => $status, 'response_time' => $responseTime, 'ssl_expiry_days' => $daysLeft, 'error_message' => $errorMsg];
    }

    // -------------------------------------------------------------------------
    // TCP port check
    // -------------------------------------------------------------------------
    private static function checkPort(array $site): array {
        $start    = microtime(true);
        $host     = $site['hostname'] ?: parse_url($site['url'], PHP_URL_HOST);
        $port     = (int) $site['port'];

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
        $start    = microtime(true);
        $host     = $site['hostname'] ?: parse_url($site['url'], PHP_URL_HOST);
        $records  = @dns_get_record($host, DNS_A | DNS_AAAA | DNS_MX);
        $responseTime = round((microtime(true) - $start) * 1000, 2);

        if (empty($records)) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "No DNS records found for $host"];
        }

        // If a keyword is set, treat it as expected IP to match
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

        $context = stream_context_create(['http' => [
            'timeout'     => CHECK_TIMEOUT,
            'user_agent'  => 'SiteMonitor/1.0',
            'ignore_errors' => true,
        ]]);

        $body = @file_get_contents($site['url'], false, $context);
        $responseTime = round((microtime(true) - $start) * 1000, 2);

        if ($body === false) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => 'Could not fetch URL'];
        }

        if ($keyword && strpos($body, $keyword) === false) {
            return ['status' => 'down', 'response_time' => $responseTime, 'error_message' => "Keyword \"$keyword\" not found on page"];
        }

        return ['status' => 'up', 'response_time' => $responseTime];
    }
}
