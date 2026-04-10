<?php
// =============================================================================
// test_enhanced_monitoring.php - Test script for enhanced monitoring features
// =============================================================================

define('MONITOR_ROOT', __DIR__);

// Load configuration and dependencies
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/Checker.php';

echo "=== Enhanced Monitoring System Test ===\n\n";

// Test configuration
$testSites = [
    [
        'name' => 'Google HTTPS Test',
        'url' => 'https://www.google.com',
        'check_type' => 'http',
        'expected_status' => 200,
    ],
    [
        'name' => 'HTTPBin Test',
        'url' => 'https://httpbin.org/status/200',
        'check_type' => 'http',
        'expected_status' => 200,
    ],
    [
        'name' => 'SSL Test Site',
        'url' => 'https://badssl.com/',
        'check_type' => 'ssl',
    ],
    [
        'name' => 'Port Test (HTTP)',
        'url' => 'httpbin.org',
        'check_type' => 'port',
        'port' => 80,
    ],
    [
        'name' => 'DNS Test',
        'url' => 'google.com',
        'check_type' => 'dns',
    ],
];

// Test results
$results = [];
$passed = 0;
$failed = 0;

echo "Testing Enhanced Monitoring Features:\n";
echo str_repeat("=", 50) . "\n\n";

foreach ($testSites as $index => $site) {
    echo "Test " . ($index + 1) . ": " . $site['name'] . "\n";
    echo str_repeat("-", 40) . "\n";
    
    try {
        $startTime = microtime(true);
        $result = Checker::check($site);
        $endTime = microtime(true);
        
        $results[] = [
            'site' => $site['name'],
            'result' => $result,
            'duration' => ($endTime - $startTime) * 1000,
        ];
        
        // Display results
        echo "Status: " . strtoupper($result['status']) . "\n";
        echo "Response Time: " . $result['response_time'] . " ms\n";
        echo "Error Category: " . ($result['error_category'] ?? 'none') . "\n";
        echo "Retry Count: " . ($result['retry_count'] ?? 0) . "\n";
        
        if ($result['ssl_expiry_days'] !== null) {
            echo "SSL Days: " . $result['ssl_expiry_days'] . "\n";
        }
        
        // Enhanced features check
        echo "\nEnhanced Features:\n";
        
        if (!empty($result['performance'])) {
            echo "  Performance Metrics: ";
            $perf = $result['performance'];
            echo "DNS: " . ($perf['dns_time'] ?? 'N/A') . "ms, ";
            echo "Connect: " . ($perf['connect_time'] ?? 'N/A') . "ms, ";
            echo "TTFB: " . ($perf['ttfb'] ?? 'N/A') . "ms, ";
            echo "Total: " . ($perf['total_time'] ?? 'N/A') . "ms\n";
        }
        
        if (!empty($result['content_check'])) {
            echo "  Content Analysis: ";
            $content = $result['content_check'];
            echo "Size: " . ($content['size_bytes'] ?? 0) . " bytes, ";
            echo "HTML: " . ($content['has_html'] ? 'Yes' : 'No') . ", ";
            echo "JSON: " . ($content['has_json'] ? 'Yes' : 'No') . "\n";
            
            if (!empty($content['error_patterns'])) {
                echo "  Error Patterns: " . implode(', ', $content['error_patterns']) . "\n";
            }
        }
        
        if (!empty($result['ssl_details'])) {
            echo "  SSL Analysis: ";
            $ssl = $result['ssl_details'];
            echo "Chain Length: " . ($ssl['chain_length'] ?? 0) . ", ";
            echo "Issues: " . count($ssl['issues'] ?? []) . "\n";
        }
        
        if (!empty($result['connectivity'])) {
            echo "  Connectivity: ";
            $conn = $result['connectivity'];
            echo "Protocol: " . ($conn['protocol'] ?? 'unknown') . ", ";
            echo "HTTP Version: " . ($conn['http_version'] ?? 'unknown') . "\n";
            
            if (!empty($conn['security_headers'])) {
                echo "  Security Headers: " . count($conn['security_headers']) . " detected\n";
            }
        }
        
        if ($result['status'] === 'up') {
            echo "Result: PASSED\n";
            $passed++;
        } else {
            echo "Result: FAILED - " . $result['error_message'] . "\n";
            $failed++;
        }
        
    } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . "\n";
        echo "Result: FAILED\n";
        $failed++;
        $results[] = [
            'site' => $site['name'],
            'result' => ['status' => 'error', 'error_message' => $e->getMessage()],
            'duration' => 0,
        ];
    }
    
    echo "\n";
}

// Summary
echo str_repeat("=", 50) . "\n";
echo "Test Summary:\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total: " . ($passed + $failed) . "\n\n";

// Configuration Test
echo "Configuration Test:\n";
echo str_repeat("-", 30) . "\n";
echo "Detailed Monitoring: " . (ENABLE_DETAILED_MONITORING ? 'Enabled' : 'Disabled') . "\n";
echo "Content Analysis: " . (ENABLE_CONTENT_ANALYSIS ? 'Enabled' : 'Disabled') . "\n";
echo "SSL Chain Analysis: " . (ENABLE_SSL_CHAIN_ANALYSIS ? 'Enabled' : 'Disabled') . "\n";
echo "Performance Metrics: " . (ENABLE_PERFORMANCE_METRICS ? 'Enabled' : 'Disabled') . "\n";
echo "Retry Failed Checks: " . (RETRY_FAILED_CHECKS ? 'Enabled' : 'Disabled') . "\n";
echo "Max Retries: " . MAX_RETRIES . "\n";
echo "Retry Delay: " . RETRY_DELAY . "ms\n";
echo "HTTP Timeout: " . HTTP_TIMEOUT . "s\n";
echo "SSL Timeout: " . SSL_TIMEOUT . "s\n";
echo "Port Timeout: " . PORT_TIMEOUT . "s\n";
echo "DNS Timeout: " . DNS_TIMEOUT . "s\n";
echo "Ping Timeout: " . PING_TIMEOUT . "s\n";
echo "Max Redirects: " . MAX_REDIRECTS . "\n\n";

// Database Test (if available)
if (DB_AVAILABLE) {
    echo "Database Test:\n";
    echo str_repeat("-", 30) . "\n";
    
    try {
        // Test basic database connection
        $db = Database::getInstance();
        echo "Database Connection: PASSED\n";
        
        // Test enhanced tables (they might not exist yet)
        $tables = ['logs_enhanced', 'performance_metrics', 'ssl_certificates', 'error_categories'];
        foreach ($tables as $table) {
            try {
                $count = Database::fetchOne("SELECT COUNT(*) as count FROM `$table`");
                echo "Table $table: EXISTS (" . $count['count'] . " rows)\n";
            } catch (Exception $e) {
                echo "Table $table: NOT EXISTS (run migration)\n";
            }
        }
        
    } catch (Exception $e) {
        echo "Database Connection: FAILED - " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

// Performance Test
echo "Performance Test:\n";
echo str_repeat("-", 30) . "\n";

$perfTestSite = [
    'name' => 'Performance Test',
    'url' => 'https://www.google.com',
    'check_type' => 'http',
];

$iterations = 5;
$totalTime = 0;
$successCount = 0;

echo "Running $iterations iterations...\n";

for ($i = 0; $i < $iterations; $i++) {
    $start = microtime(true);
    $result = Checker::check($perfTestSite);
    $end = microtime(true);
    
    $duration = ($end - $start) * 1000;
    $totalTime += $duration;
    
    if ($result['status'] === 'up') {
        $successCount++;
    }
    
    echo "  Iteration " . ($i + 1) . ": " . round($duration, 2) . "ms\n";
}

$avgTime = $totalTime / $iterations;
echo "\nAverage Check Time: " . round($avgTime, 2) . "ms\n";
echo "Success Rate: " . round(($successCount / $iterations) * 100, 1) . "%\n\n";

// Detailed Results Analysis
echo "Detailed Results Analysis:\n";
echo str_repeat("-", 40) . "\n";

$performanceData = [];
$errorCategories = [];
$sslData = [];

foreach ($results as $result) {
    if ($result['result']['status'] === 'up' && !empty($result['result']['performance'])) {
        $performanceData[] = $result['result']['performance'];
    }
    
    if (!empty($result['result']['error_category'])) {
        $category = $result['result']['error_category'];
        $errorCategories[$category] = ($errorCategories[$category] ?? 0) + 1;
    }
    
    if (!empty($result['result']['ssl_details'])) {
        $sslData[] = $result['result']['ssl_details'];
    }
}

if (!empty($performanceData)) {
    echo "Performance Metrics Summary:\n";
    $avgDns = array_sum(array_column($performanceData, 'dns_time')) / count($performanceData);
    $avgConnect = array_sum(array_column($performanceData, 'connect_time')) / count($performanceData);
    $avgTtfb = array_sum(array_column($performanceData, 'ttfb')) / count($performanceData);
    $avgTotal = array_sum(array_column($performanceData, 'total_time')) / count($performanceData);
    
    echo "  Average DNS Time: " . round($avgDns, 2) . "ms\n";
    echo "  Average Connect Time: " . round($avgConnect, 2) . "ms\n";
    echo "  Average TTFB: " . round($avgTtfb, 2) . "ms\n";
    echo "  Average Total Time: " . round($avgTotal, 2) . "ms\n\n";
}

if (!empty($errorCategories)) {
    echo "Error Categories Summary:\n";
    foreach ($errorCategories as $category => $count) {
        echo "  $category: $count\n";
    }
    echo "\n";
}

if (!empty($sslData)) {
    echo "SSL Analysis Summary:\n";
    $totalChains = array_sum(array_column($sslData, 'chain_length'));
    $totalIssues = array_sum(array_map(fn($ssl) => count($ssl['issues'] ?? []), $sslData));
    
    echo "  Total Certificate Chains: " . count($sslData) . "\n";
    echo "  Average Chain Length: " . round($totalChains / count($sslData), 1) . "\n";
    echo "  Total SSL Issues: $totalIssues\n\n";
}

// Recommendations
echo "Recommendations:\n";
echo str_repeat("-", 20) . "\n";

if ($failed > 0) {
    echo "- Some tests failed. Check error messages above.\n";
}

if ($avgTime > 5000) {
    echo "- Average check time is high. Consider increasing timeouts.\n";
}

if (!ENABLE_DETAILED_MONITORING) {
    echo "- Enable detailed monitoring for full features.\n";
}

if (!DB_AVAILABLE) {
    echo "- Configure database for persistent monitoring.\n";
}

echo "- Run database migration if enhanced tables don't exist.\n";
echo "- Update frontend to use enhanced monitoring JavaScript.\n";

echo "\n=== Test Complete ===\n";
