<?php
// =============================================================================
// includes/Statistics.php - Aggregation & analytics queries
// =============================================================================

class Statistics {

    // -------------------------------------------------------------------------
    // Uptime % for a site over N days
    // -------------------------------------------------------------------------
    public static function getUptime(int $siteId, int $days = 30): float {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS total,
                    SUM(status = "up") AS up_count
             FROM logs
             WHERE site_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$siteId, $days]
        );
        if (!$row || $row['total'] == 0) return 100.0;
        return round(($row['up_count'] / $row['total']) * 100, 2);
    }

    // -------------------------------------------------------------------------
    // Hourly average response time for last N hours
    // -------------------------------------------------------------------------
    public static function getResponseTimeTrend(int $siteId, int $hours = 24): array {
        return Database::fetchAll(
            'SELECT DATE_FORMAT(created_at, "%Y-%m-%d %H:00") AS hour,
                    ROUND(AVG(response_time), 2) AS avg_rt,
                    MIN(response_time) AS min_rt,
                    MAX(response_time) AS max_rt,
                    COUNT(*) AS checks
             FROM logs
             WHERE site_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY hour
             ORDER BY hour ASC',
            [$siteId, $hours]
        );
    }

    // -------------------------------------------------------------------------
    // Daily uptime stats for last N days
    // -------------------------------------------------------------------------
    public static function getDailyUptime(int $siteId, int $days = 30): array {
        return Database::fetchAll(
            'SELECT date,
                    uptime_percentage,
                    total_checks,
                    failed_checks,
                    avg_response_time
             FROM daily_uptime
             WHERE site_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             ORDER BY date ASC',
            [$siteId, $days]
        );
    }

    // -------------------------------------------------------------------------
    // SSL expiry info for all sites with SSL data
    // -------------------------------------------------------------------------
    public static function getSSLExpiryInfo(): array {
        return Database::fetchAll(
            'SELECT s.id, s.name, s.url,
                    l.ssl_expiry_days,
                    l.created_at AS last_checked
             FROM sites s
             JOIN logs l ON l.id = (
                 SELECT id FROM logs WHERE site_id = s.id ORDER BY created_at DESC LIMIT 1
             )
             WHERE l.ssl_expiry_days IS NOT NULL AND s.is_active = 1
             ORDER BY l.ssl_expiry_days ASC'
        );
    }

    // -------------------------------------------------------------------------
    // System-wide uptime trend (aggregate of all sites)
    // -------------------------------------------------------------------------
    public static function getSystemUptimeTrend(int $days = 30): array {
        return Database::fetchAll(
            'SELECT date, ROUND(AVG(uptime_percentage), 2) AS uptime_percentage
             FROM daily_uptime
             WHERE date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY date
             ORDER BY date ASC',
            [$days]
        );
    }

    // -------------------------------------------------------------------------
    // Overall system health score (0-100)
    // -------------------------------------------------------------------------
    public static function getSystemHealth(): array {
        $sites = Database::fetchAll('SELECT COUNT(*) AS total FROM sites WHERE is_active = 1');
        $total = (int) ($sites[0]['total'] ?? 0);

        $down = Database::fetchOne(
            'SELECT COUNT(DISTINCT site_id) AS cnt
             FROM logs
             WHERE status = "down"
               AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)'
        );
        $downCount = (int) ($down['cnt'] ?? 0);

        $avgRt = Database::fetchOne(
            'SELECT ROUND(AVG(response_time), 2) AS avg_rt
             FROM logs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );

        $sslWarn = Database::fetchOne(
            'SELECT COUNT(*) AS cnt
             FROM logs l
             JOIN sites s ON s.id = l.site_id
             WHERE s.check_type = "ssl"
               AND l.ssl_expiry_days IS NOT NULL
               AND l.ssl_expiry_days <= 30
               AND l.id = (SELECT id FROM logs WHERE site_id = s.id ORDER BY created_at DESC LIMIT 1)'
        );

        $upCount     = max(0, $total - $downCount);
        $healthScore = $total > 0 ? round(($upCount / $total) * 100) : 100;

        return [
            'total_sites'    => $total,
            'sites_down'     => $downCount,
            'sites_up'       => $upCount,
            'avg_response'   => $avgRt['avg_rt'] ?? 0,
            'ssl_warnings'   => (int) ($sslWarn['cnt'] ?? 0),
            'health_score'   => $healthScore,
        ];
    }

    // -------------------------------------------------------------------------
    // Top N slowest sites by average response time (last 24h)
    // -------------------------------------------------------------------------
    public static function getSlowestSites(int $limit = 5): array {
        return Database::fetchAll(
            'SELECT s.id, s.name, s.url,
                    ROUND(AVG(l.response_time), 2) AS avg_rt
             FROM logs l
             JOIN sites s ON s.id = l.site_id
             WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY s.id, s.name, s.url
             ORDER BY avg_rt DESC
             LIMIT ?',
            [$limit]
        );
    }

    // -------------------------------------------------------------------------
    // Incident history for a site
    // -------------------------------------------------------------------------
    public static function getIncidents(int $siteId, int $limit = 20): array {
        return Database::fetchAll(
            'SELECT id, started_at, ended_at, duration_seconds, error_message
             FROM incidents
             WHERE site_id = ?
             ORDER BY started_at DESC
             LIMIT ?',
            [$siteId, $limit]
        );
    }

    // -------------------------------------------------------------------------
    // All incidents (dashboard view)
    // -------------------------------------------------------------------------
    public static function getAllIncidents(int $limit = 50): array {
        return Database::fetchAll(
            'SELECT i.*, s.name AS site_name, s.url
             FROM incidents i
             JOIN sites s ON s.id = i.site_id
             ORDER BY i.started_at DESC
             LIMIT ?',
            [$limit]
        );
    }

    // -------------------------------------------------------------------------
    // Current month SLA percentage
    // -------------------------------------------------------------------------
    public static function getMonthlySLA(int $siteId): float {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS total, SUM(status = "up") AS up_count
             FROM logs
             WHERE site_id = ?
               AND YEAR(created_at) = YEAR(NOW())
               AND MONTH(created_at) = MONTH(NOW())',
            [$siteId]
        );
        if (!$row || $row['total'] == 0) return 100.0;
        return round(($row['up_count'] / $row['total']) * 100, 3);
    }

    // -------------------------------------------------------------------------
    // Multi-site comparison: avg response time per site last 24h
    // -------------------------------------------------------------------------
    public static function getSitesComparison(array $siteIds): array {
        if (empty($siteIds)) return [];
        $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
        return Database::fetchAll(
            "SELECT s.id, s.name,
                    ROUND(AVG(l.response_time), 2) AS avg_rt,
                    SUM(l.status = 'up') / COUNT(*) * 100 AS uptime_pct
             FROM logs l
             JOIN sites s ON s.id = l.site_id
             WHERE l.site_id IN ($placeholders)
               AND l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY s.id, s.name",
            $siteIds
        );
    }

    // -------------------------------------------------------------------------
    // Response time histogram buckets for last 100 checks
    // -------------------------------------------------------------------------
    public static function getResponseHistogram(int $siteId): array {
        $rows = Database::fetchAll(
            'SELECT response_time FROM logs WHERE site_id = ? ORDER BY created_at DESC LIMIT 100',
            [$siteId]
        );

        $buckets = ['0-100' => 0, '100-200' => 0, '200-500' => 0, '500-1000' => 0, '1000+' => 0];
        foreach ($rows as $row) {
            $rt = (float) $row['response_time'];
            if ($rt < 100)       $buckets['0-100']++;
            elseif ($rt < 200)   $buckets['100-200']++;
            elseif ($rt < 500)   $buckets['200-500']++;
            elseif ($rt < 1000)  $buckets['500-1000']++;
            else                 $buckets['1000+']++;
        }
        return $buckets;
    }

    // -------------------------------------------------------------------------
    // Last N logs for a site
    // -------------------------------------------------------------------------
    public static function getRecentLogs(int $siteId, int $limit = 100): array {
        return Database::fetchAll(
            'SELECT id, status, response_time, error_message, ssl_expiry_days, created_at
             FROM logs
             WHERE site_id = ?
             ORDER BY created_at DESC
             LIMIT ?',
            [$siteId, $limit]
        );
    }

    // -------------------------------------------------------------------------
    // Aggregate hourly stats into hourly_stats table (called by cron)
    // -------------------------------------------------------------------------
    public static function aggregateHourlyStats(): void {
        Database::execute(
            'INSERT INTO hourly_stats (site_id, hour, avg_response_time, min_response_time, max_response_time)
             SELECT site_id,
                    DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") AS hour,
                    ROUND(AVG(response_time), 2),
                    MIN(response_time),
                    MAX(response_time)
             FROM logs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND created_at < DATE_FORMAT(NOW(), "%Y-%m-%d %H:00:00")
             GROUP BY site_id, hour
             ON DUPLICATE KEY UPDATE
                avg_response_time = VALUES(avg_response_time),
                min_response_time = VALUES(min_response_time),
                max_response_time = VALUES(max_response_time)'
        );
    }

    // -------------------------------------------------------------------------
    // Aggregate daily uptime stats (called by cron)
    // -------------------------------------------------------------------------
    public static function aggregateDailyUptime(): void {
        Database::execute(
            'INSERT INTO daily_uptime (site_id, date, uptime_percentage, total_checks, failed_checks, avg_response_time)
             SELECT site_id,
                    DATE(created_at) AS date,
                    ROUND(SUM(status = "up") / COUNT(*) * 100, 2),
                    COUNT(*),
                    SUM(status != "up"),
                    ROUND(AVG(response_time), 2)
             FROM logs
             WHERE DATE(created_at) = CURDATE() - INTERVAL 1 DAY
             GROUP BY site_id, date
             ON DUPLICATE KEY UPDATE
                uptime_percentage = VALUES(uptime_percentage),
                total_checks      = VALUES(total_checks),
                failed_checks     = VALUES(failed_checks),
                avg_response_time = VALUES(avg_response_time)'
        );
    }
}
