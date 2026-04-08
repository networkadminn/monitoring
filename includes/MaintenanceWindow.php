<?php
// =============================================================================
// includes/MaintenanceWindow.php - Maintenance window checks
// =============================================================================

class MaintenanceWindow {

    /**
     * Check if a site is currently in a maintenance window
     */
    public static function isActive(int $siteId): bool {
        $now = Database::fetchOne(
            "SELECT id FROM maintenance_windows
             WHERE site_id = ? AND is_active = 1
               AND start_time <= NOW() AND end_time >= NOW()",
            [$siteId]
        );
        return (bool) $now;
    }

    /**
     * Get all maintenance windows for a site
     */
    public static function getForSite(int $siteId): array {
        return Database::fetchAll(
            "SELECT * FROM maintenance_windows WHERE site_id = ? ORDER BY start_time DESC",
            [$siteId]
        );
    }

    /**
     * Get all upcoming/active windows
     */
    public static function getUpcoming(int $limit = 20): array {
        return Database::fetchAll(
            "SELECT mw.*, s.name AS site_name, s.url
             FROM maintenance_windows mw
             JOIN sites s ON s.id = mw.site_id
             WHERE mw.end_time >= NOW() AND mw.is_active = 1
             ORDER BY mw.start_time ASC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * Create a maintenance window
     */
    public static function create(int $siteId, string $title, string $startTime, string $endTime, string $description = ''): int {
        return (int) Database::insert(
            "INSERT INTO maintenance_windows (site_id, title, description, start_time, end_time, is_active)
             VALUES (?, ?, ?, ?, ?, 1)",
            [$siteId, $title, $description, $startTime, $endTime]
        );
    }

    /**
     * Delete a maintenance window
     */
    public static function delete(int $id): void {
        Database::execute("DELETE FROM maintenance_windows WHERE id = ?", [$id]);
    }
}
