-- =============================================================================
-- Enhanced Monitoring Database Migration
-- Adds support for detailed monitoring data, performance metrics, and SSL analysis
-- =============================================================================

-- Create enhanced logs table with detailed monitoring data
CREATE TABLE IF NOT EXISTS `logs_enhanced` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_id` int(11) NOT NULL,
  `status` enum('up','down','warning') NOT NULL,
  `response_time` decimal(8,2) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `error_category` varchar(50) DEFAULT NULL,
  `ssl_expiry_days` int(11) DEFAULT NULL,
  `retry_count` int(11) DEFAULT 0,
  `performance_data` json DEFAULT NULL,
  `content_check_data` json DEFAULT NULL,
  `ssl_details_data` json DEFAULT NULL,
  `connectivity_data` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_site_id` (`site_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_error_category` (`error_category`),
  FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create performance metrics aggregation table
CREATE TABLE IF NOT EXISTS `performance_metrics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_id` int(11) NOT NULL,
  `metric_type` enum('dns_time','connect_time','ttfb','total_time','download_size') NOT NULL,
  `metric_value` decimal(10,2) NOT NULL,
  `hour_bucket` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_metric` (`site_id`,`metric_type`,`hour_bucket`),
  KEY `idx_site_metric` (`site_id`,`metric_type`),
  KEY `idx_hour_bucket` (`hour_bucket`),
  FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create SSL certificate details table
CREATE TABLE IF NOT EXISTS `ssl_certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_id` int(11) NOT NULL,
  `certificate_hash` varchar(64) NOT NULL,
  `subject` text DEFAULT NULL,
  `issuer` text DEFAULT NULL,
  `issue_date` datetime DEFAULT NULL,
  `expiry_date` datetime DEFAULT NULL,
  `days_until_expiry` int(11) DEFAULT NULL,
  `signature_algorithm` varchar(100) DEFAULT NULL,
  `public_key_info` text DEFAULT NULL,
  `chain_position` int(11) DEFAULT 0,
  `issues` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cert` (`site_id`,`certificate_hash`),
  KEY `idx_site_id` (`site_id`),
  KEY `idx_expiry_date` (`expiry_date`),
  KEY `idx_days_until_expiry` (`days_until_expiry`),
  FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create error categorization table for analytics
CREATE TABLE IF NOT EXISTS `error_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_id` int(11) NOT NULL,
  `error_category` varchar(50) NOT NULL,
  `error_count` int(11) DEFAULT 1,
  `first_seen` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_error` (`site_id`,`error_category`),
  KEY `idx_site_category` (`site_id`,`error_category`),
  KEY `idx_last_seen` (`last_seen`),
  FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add new columns to existing sites table for enhanced monitoring
ALTER TABLE `sites` 
ADD COLUMN IF NOT EXISTS `enable_detailed_monitoring` tinyint(1) DEFAULT 1,
ADD COLUMN IF NOT EXISTS `performance_threshold_ms` decimal(8,2) DEFAULT 5000.00,
ADD COLUMN IF NOT EXISTS `content_analysis_enabled` tinyint(1) DEFAULT 1,
ADD COLUMN IF NOT EXISTS `ssl_chain_analysis_enabled` tinyint(1) DEFAULT 1,
ADD COLUMN IF NOT EXISTS `retry_failed_checks` tinyint(1) DEFAULT 1,
ADD COLUMN IF NOT EXISTS `max_retries` int(11) DEFAULT 3,
ADD COLUMN IF NOT EXISTS `retry_delay_ms` int(11) DEFAULT 1000;

-- Add new columns to incidents table for enhanced tracking
ALTER TABLE `incidents`
ADD COLUMN IF NOT EXISTS `error_category` varchar(50) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `detailed_error_data` json DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `performance_impact` json DEFAULT NULL;

-- Create monitoring configuration history table
CREATE TABLE IF NOT EXISTS `monitoring_config_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_id` int(11) NOT NULL,
  `config_type` varchar(50) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `changed_by` varchar(100) DEFAULT NULL,
  `change_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_site_config` (`site_id`,`config_type`),
  KEY `idx_created_at` (`created_at`),
  FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create view for enhanced monitoring dashboard
CREATE OR REPLACE VIEW `v_enhanced_site_status` AS
SELECT 
    s.id,
    s.name,
    s.url,
    s.check_type,
    s.is_active,
    s.uptime_percentage,
    s.last_checked,
    s.status,
    s.response_time,
    s.error_message,
    s.error_category,
    s.ssl_expiry_days,
    s.enable_detailed_monitoring,
    s.performance_threshold_ms,
    s.content_analysis_enabled,
    s.ssl_chain_analysis_enabled,
    s.retry_failed_checks,
    s.max_retries,
    s.retry_delay_ms,
    -- Latest performance metrics
    (SELECT JSON_EXTRACT(performance_data, '$.ttfb') 
     FROM logs_enhanced le 
     WHERE le.site_id = s.id 
     ORDER BY le.created_at DESC 
     LIMIT 1) as latest_ttfb,
    -- Latest SSL details
    (SELECT JSON_EXTRACT(ssl_details_data, '$.issues') 
     FROM logs_enhanced le 
     WHERE le.site_id = s.id 
     ORDER BY le.created_at DESC 
     LIMIT 1) as latest_ssl_issues,
    -- Error count in last 24 hours
    (SELECT COUNT(*) 
     FROM logs_enhanced le 
     WHERE le.site_id = s.id 
     AND le.status = 'down' 
     AND le.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as errors_last_24h,
    -- Average response time in last hour
    (SELECT AVG(response_time) 
     FROM logs_enhanced le 
     WHERE le.site_id = s.id 
     AND le.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) as avg_response_hour
FROM sites s;

-- Insert default monitoring settings for existing sites
UPDATE sites SET 
    enable_detailed_monitoring = 1,
    performance_threshold_ms = 5000.00,
    content_analysis_enabled = 1,
    ssl_chain_analysis_enabled = 1,
    retry_failed_checks = 1,
    max_retries = 3,
    retry_delay_ms = 1000
WHERE enable_detailed_monitoring IS NULL;

-- Create stored procedure for aggregating performance metrics
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS `AggregatePerformanceMetrics`()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE site_id_val INT;
    DECLARE metric_type_val VARCHAR(20);
    DECLARE metric_val DECIMAL(10,2);
    DECLARE hour_bucket_val DATETIME;
    
    -- Cursor for performance data from last hour
    DECLARE perf_cursor CURSOR FOR
        SELECT 
            site_id,
            JSON_UNQUOTE(JSON_EXTRACT(performance_data, CONCAT('$.', key_name))) as value,
            key_name,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour_bucket
        FROM logs_enhanced,
        JSON_TABLE(performance_data, '$' COLUMNS (
            key_name VARCHAR(20) PATH '$.key',
            value_val DECIMAL(10,2) PATH '$'
        )) as jt
        WHERE performance_data IS NOT NULL
        AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        AND created_at < DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00');
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN perf_cursor;
    
    read_loop: LOOP
        FETCH perf_cursor INTO site_id_val, metric_val, metric_type_val, hour_bucket_val;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        -- Insert or update performance metrics
        INSERT INTO performance_metrics (site_id, metric_type, metric_value, hour_bucket)
        VALUES (site_id_val, metric_type_val, metric_val, hour_bucket_val)
        ON DUPLICATE KEY UPDATE 
            metric_value = (metric_value + metric_val) / 2,
            updated_at = NOW();
            
    END LOOP;
    
    CLOSE perf_cursor;
END //
DELIMITER ;

-- Create event to automatically aggregate performance metrics every hour
CREATE EVENT IF NOT EXISTS `aggregate_performance_metrics`
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
DO CALL AggregatePerformanceMetrics();

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_logs_enhanced_site_created ON logs_enhanced(site_id, created_at);
CREATE INDEX IF NOT EXISTS idx_logs_enhanced_status_category ON logs_enhanced(status, error_category);
CREATE INDEX IF NOT EXISTS idx_ssl_certificates_expiry ON ssl_certificates(days_until_expiry);
CREATE INDEX IF NOT EXISTS idx_error_categories_last_seen ON error_categories(last_seen);

-- Update existing logs table structure for compatibility (if needed)
ALTER TABLE `logs` 
ADD COLUMN IF NOT EXISTS `error_category` varchar(50) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `retry_count` int(11) DEFAULT 0;
