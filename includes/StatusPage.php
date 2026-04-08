<?php
// =============================================================================
// includes/StatusPage.php - Public status page logic
// =============================================================================

class StatusPage {

    public static function getConfig(): array {
        $row = Database::fetchOne("SELECT * FROM status_page_config LIMIT 1");
        return $row ?: [
            'id'            => 0,
            'title'         => defined('APP_NAME') ? APP_NAME : 'Service Status',
            'description'   => 'Real-time status of our services',
            'logo_url'      => '',
            'custom_domain' => '',
            'is_public'     => 1,
            'show_values'   => 1,
            'accent_color'  => '#3b82f6',
            'footer_text'   => '',
        ];
    }

    public static function saveConfig(array $data): void {
        $existing = Database::fetchOne("SELECT id FROM status_page_config LIMIT 1");
        if ($existing) {
            Database::execute(
                "UPDATE status_page_config SET title=?, description=?, logo_url=?, is_public=?, show_values=?, accent_color=?, footer_text=? WHERE id=?",
                [$data['title'], $data['description'], $data['logo_url'], $data['is_public'], $data['show_values'], $data['accent_color'], $data['footer_text'], $existing['id']]
            );
        } else {
            Database::insert(
                "INSERT INTO status_page_config (title, description, logo_url, is_public, show_values, accent_color, footer_text) VALUES (?,?,?,?,?,?,?)",
                [$data['title'], $data['description'], $data['logo_url'], $data['is_public'], $data['show_values'], $data['accent_color'], $data['footer_text']]
            );
        }
    }

    public static function getPublicSites(): array {
        return Database::fetchAll(
            "SELECT s.id, s.name, s.url, s.check_type, s.uptime_percentage, s.tags,
                    l.status, l.response_time, l.ssl_expiry_days, l.created_at AS last_checked,
                    l.error_message
             FROM sites s
             LEFT JOIN logs l ON l.id = (
                 SELECT id FROM logs WHERE site_id = s.id ORDER BY created_at DESC LIMIT 1
             )
             WHERE s.is_active = 1
             ORDER BY s.name ASC"
        );
    }

    public static function getUptimeLast90Days(int $siteId): array {
        return Database::fetchAll(
            "SELECT date, uptime_percentage, failed_checks
             FROM daily_uptime
             WHERE site_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
             ORDER BY date ASC",
            [$siteId]
        );
    }

    public static function getRecentIncidents(int $limit = 10): array {
        return Database::fetchAll(
            "SELECT i.*, s.name AS site_name, s.url
             FROM incidents i
             JOIN sites s ON s.id = i.site_id
             WHERE i.started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY i.started_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    public static function addSubscriber(string $email): bool {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        try {
            Database::insert(
                "INSERT INTO status_page_subscribers (email, token, created_at) VALUES (?, ?, NOW())",
                [$email, bin2hex(random_bytes(16))]
            );
            return true;
        } catch (Exception $e) {
            return false; // duplicate
        }
    }

    public static function removeSubscriber(string $token): void {
        Database::execute("DELETE FROM status_page_subscribers WHERE token = ?", [$token]);
    }
}
