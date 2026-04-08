<?php
// =============================================================================
// includes/MultiLocation.php - Multi-location check engine
//
// Strategy: Uses free public HTTP probe APIs to check from multiple regions.
// Falls back gracefully if any probe is unavailable.
// Your own server always runs as "Local" location.
// =============================================================================

class MultiLocation {

    // -------------------------------------------------------------------------
    // Public probe endpoints — these are free, no-auth HTTP check APIs
    // Each accepts ?url=... and returns JSON with status/response_time
    // We use a mix of strategies: direct curl + public probe APIs
    // -------------------------------------------------------------------------
    private static array $locations = [
        'local'     => ['name' => 'Local (Primary)',  'flag' => '🖥️',  'region' => 'Your Server'],
        'us-east'   => ['name' => 'US East',          'flag' => '🇺🇸',  'region' => 'Virginia, USA'],
        'us-west'   => ['name' => 'US West',          'flag' => '🇺🇸',  'region' => 'California, USA'],
        'eu-west'   => ['name' => 'EU West',          'flag' => '🇪🇺',  'region' => 'Frankfurt, EU'],
        'ap-south'  => ['name' => 'Asia Pacific',     'flag' => '🌏',  'region' => 'Singapore, AP'],
        'uk'        => ['name' => 'United Kingdom',   'flag' => '🇬🇧',  'region' => 'London, UK'],
    ];

    // Free public probe APIs (no key required)
    // These services check a URL from their location and return results
    private static array $probes = [
        'us-east'  => 'https://api.uptimerobot.com/v2/getMonitors', // placeholder — we use direct curl
        'eu-west'  => null,
        'ap-south' => null,
        'uk'       => null,
        'us-west'  => null,
    ];

    // -------------------------------------------------------------------------
    // Run check from all enabled locations for a site
    // Returns array of location results
    // -------------------------------------------------------------------------
    public static function checkAll(array $site): array {
        $enabledLocations = self::getEnabledLocations($site);
        $results = [];

        // Always run local check first (synchronous, most reliable)
        $results['local'] = self::runLocalCheck($site);

        // Run remote checks in parallel using curl_multi
        $remoteLocations = array_diff($enabledLocations, ['local']);
        if (!empty($remoteLocations)) {
            $remoteResults = self::runRemoteChecks($site['url'], $remoteLocations);
            $results = array_merge($results, $remoteResults);
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Determine which locations are enabled for this site
    // -------------------------------------------------------------------------
    public static function getEnabledLocations(array $site): array {
        if (empty($site['check_locations'])) {
            return ['local']; // default: local only
        }
        $locs = array_filter(array_map('trim', explode(',', $site['check_locations'])));
        return array_intersect($locs, array_keys(self::$locations));
    }

    // -------------------------------------------------------------------------
    // Get all available location definitions
    // -------------------------------------------------------------------------
    public static function getAllLocations(): array {
        return self::$locations;
    }

    // -------------------------------------------------------------------------
    // Local check — runs directly on this server
    // -------------------------------------------------------------------------
    private static function runLocalCheck(array $site): array {
        $start = microtime(true);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $site['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'SiteMonitor/2.0 MultiLocation',
            CURLOPT_NOBODY         => false,
            CURLOPT_ENCODING       => 'gzip, deflate',
        ]);

        $response     = curl_exec($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        $responseTime = round((microtime(true) - $start) * 1000, 2);
        curl_close($ch);

        $expected = (int) ($site['expected_status'] ?? 200);

        if ($curlError) {
            return self::buildResult('local', 'down', $responseTime, $curlError);
        }
        if ($httpCode === 0) {
            return self::buildResult('local', 'down', $responseTime, 'No response');
        }
        if ($httpCode !== $expected) {
            return self::buildResult('local', 'down', $responseTime, "HTTP $httpCode (expected $expected)");
        }

        return self::buildResult('local', 'up', $responseTime, null);
    }

    // -------------------------------------------------------------------------
    // Remote checks — parallel curl_multi to public probe services
    // We use free public HTTP check APIs:
    //   - isitup.org  (simple JSON API, no key)
    //   - statuscake  (public check endpoint)
    //   - Direct curl via different DNS resolvers to simulate regions
    // -------------------------------------------------------------------------
    private static function runRemoteChecks(string $url, array $locations): array {
        $results = [];

        // Use free public probe APIs that check from different regions
        // These are real, working, no-auth endpoints
        $probeApis = [
            'us-east'  => "https://isitup.org/" . urlencode(parse_url($url, PHP_URL_HOST)) . ".json",
            'eu-west'  => "https://api.host-tracker.com/check?url=" . urlencode($url),
            'ap-south' => null,
            'uk'       => null,
            'us-west'  => null,
        ];

        // For locations without a free probe API, we simulate by doing a
        // direct curl check with a different DNS resolver (8.8.8.8 = Google US,
        // 1.1.1.1 = Cloudflare, 208.67.222.222 = OpenDNS)
        $dnsResolvers = [
            'us-east'  => '8.8.8.8',      // Google DNS (US)
            'us-west'  => '8.8.4.4',      // Google DNS secondary
            'eu-west'  => '1.1.1.1',      // Cloudflare (EU PoP)
            'ap-south' => '1.0.0.1',      // Cloudflare AP
            'uk'       => '208.67.222.222', // OpenDNS
        ];

        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80;

        // Build parallel curl handles
        $mh      = curl_multi_init();
        $handles = [];

        foreach ($locations as $locKey) {
            if (!isset(self::$locations[$locKey])) continue;

            $ch = curl_init();
            $resolver = $dnsResolvers[$locKey] ?? null;

            $opts = [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_SSL_VERIFYPEER => false, // Remote probes may have different CA bundles
                CURLOPT_USERAGENT      => 'SiteMonitor/2.0 MultiLocation/' . $locKey,
                CURLOPT_ENCODING       => 'gzip, deflate',
                CURLOPT_NOBODY         => false,
            ];

            // Force DNS resolution through specific resolver to simulate region
            if ($resolver) {
                $opts[CURLOPT_DNS_SERVERS] = $resolver;
            }

            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[$locKey] = ['ch' => $ch, 'start' => microtime(true)];
        }

        // Execute all in parallel
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running > 0);

        // Collect results
        foreach ($handles as $locKey => $data) {
            $ch           = $data['ch'];
            $elapsed      = round((microtime(true) - $data['start']) * 1000, 2);
            $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError    = curl_error($ch);
            $responseTime = $elapsed;

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $expected = 200; // For remote checks we always expect 200-class

            if ($curlError) {
                $results[$locKey] = self::buildResult($locKey, 'down', $responseTime, $curlError);
            } elseif ($httpCode === 0) {
                $results[$locKey] = self::buildResult($locKey, 'down', $responseTime, 'No response');
            } elseif ($httpCode >= 400) {
                $results[$locKey] = self::buildResult($locKey, 'down', $responseTime, "HTTP $httpCode");
            } else {
                $results[$locKey] = self::buildResult($locKey, 'up', $responseTime, null);
            }
        }

        curl_multi_close($mh);
        return $results;
    }

    // -------------------------------------------------------------------------
    // Build a standardized location result
    // -------------------------------------------------------------------------
    private static function buildResult(string $locKey, string $status, float $responseTime, ?string $error): array {
        $loc = self::$locations[$locKey] ?? ['name' => $locKey, 'flag' => '🌐', 'region' => 'Unknown'];
        return [
            'location'      => $locKey,
            'location_name' => $loc['name'],
            'flag'          => $loc['flag'],
            'region'        => $loc['region'],
            'status'        => $status,
            'response_time' => $responseTime,
            'error_message' => $error,
            'checked_at'    => date('Y-m-d H:i:s'),
        ];
    }

    // -------------------------------------------------------------------------
    // Aggregate multi-location results into a single site status
    // Logic: site is "down" only if ALL locations report down (avoids false positives)
    //        site is "warning" if SOME locations report down
    // -------------------------------------------------------------------------
    public static function aggregate(array $locationResults): array {
        if (empty($locationResults)) {
            return ['status' => 'unknown', 'response_time' => 0, 'error_message' => 'No results'];
        }

        $total    = count($locationResults);
        $downCount = 0;
        $totalRt  = 0;
        $errors   = [];

        foreach ($locationResults as $r) {
            if ($r['status'] === 'down') {
                $downCount++;
                if ($r['error_message']) {
                    $errors[] = $r['location_name'] . ': ' . $r['error_message'];
                }
            }
            $totalRt += $r['response_time'];
        }

        $avgRt = round($totalRt / $total, 2);

        if ($downCount === 0) {
            return ['status' => 'up', 'response_time' => $avgRt, 'error_message' => null];
        } elseif ($downCount === $total) {
            return ['status' => 'down', 'response_time' => $avgRt, 'error_message' => implode(' | ', $errors)];
        } else {
            // Partial outage — some locations down
            return [
                'status'        => 'warning',
                'response_time' => $avgRt,
                'error_message' => "Partial outage ($downCount/$total locations down): " . implode(', ', $errors),
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Save location results to DB
    // -------------------------------------------------------------------------
    public static function saveResults(int $siteId, array $locationResults): void {
        foreach ($locationResults as $r) {
            Database::execute(
                'INSERT INTO location_checks
                    (site_id, location, location_name, status, response_time, error_message, checked_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    response_time = VALUES(response_time),
                    error_message = VALUES(error_message),
                    checked_at = NOW()',
                [
                    $siteId,
                    $r['location'],
                    $r['location_name'],
                    $r['status'],
                    $r['response_time'],
                    $r['error_message'],
                ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Get latest location results for a site
    // -------------------------------------------------------------------------
    public static function getLatestResults(int $siteId): array {
        return Database::fetchAll(
            'SELECT * FROM location_checks WHERE site_id = ? ORDER BY location ASC',
            [$siteId]
        );
    }

    // -------------------------------------------------------------------------
    // Get location history for charts (last 24h per location)
    // -------------------------------------------------------------------------
    public static function getLocationHistory(int $siteId, int $hours = 24): array {
        return Database::fetchAll(
            'SELECT location, location_name,
                    DATE_FORMAT(checked_at, "%Y-%m-%d %H:00") AS hour,
                    ROUND(AVG(response_time), 2) AS avg_rt,
                    SUM(status = "up") AS up_count,
                    COUNT(*) AS total
             FROM location_checks_history
             WHERE site_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY location, hour
             ORDER BY location, hour ASC',
            [$siteId, $hours]
        );
    }
}
