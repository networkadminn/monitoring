# Critical Error Fixes - Deployment Instructions

## Overview

This deployment fixes two critical issues in your PHP monitoring application:

1. **Problem 1: DNS Resolution Failures** - Enhanced DNS resolution with fallback mechanisms
2. **Problem 2: Network Error 61 (Content Encoding)** - Fixed cURL configuration for HTTP compression

## Files Modified

### Primary Files
- `includes/Checker.php` - Main monitoring logic with enhanced error handling
- `test_fixes.php` - Comprehensive test script to validate fixes

### Changes Made
1. **cURL Configuration Fixes**
   - Changed `CURLOPT_ENCODING` from `'gzip, deflate, br'` to `''` (empty string)
   - Removed problematic 'br' (brotli) encoding from Accept-Encoding headers
   - Added fallback mechanism for encoding errors
   - Enhanced error handling with debug logging

2. **DNS Resolution Improvements**
   - Enhanced DNS resolution with multiple record types
   - Added fallback using `gethostbyname()` for basic A records
   - Improved error messages with detailed debugging information
   - Better error categorization and retry logic

3. **Debug Logging & Error Tracking**
   - Added comprehensive debug logging when `DEBUG_MODE` is enabled
   - Enhanced error categorization for better troubleshooting
   - Added detailed error messages with context

4. **Fallback Mechanisms**
   - Automatic retry for encoding errors
   - Fallback DNS resolution methods
   - Graceful degradation when features fail

## Pre-Deployment Checklist

### 1. Backup Current Files
```bash
# Backup the current Checker.php
cp /home/netadmin/study/monitoring/includes/Checker.php /home/netadmin/study/monitoring/includes/Checker.php.backup

# Backup any custom configuration
cp /home/netadmin/study/monitoring/config.php /home/netadmin/study/monitoring/config.php.backup
```

### 2. Verify Requirements
```bash
# Check PHP version (should be 7.4+)
php --version

# Check cURL extension
php -m | grep curl

# Check required PHP functions
php -r "echo 'curl_version: ' . curl_version()['version'] . PHP_EOL;"
php -r "echo 'dns_get_record: ' . (function_exists('dns_get_record') ? 'Available' : 'Not Available') . PHP_EOL;"
```

### 3. Test Environment Setup
```bash
# Test the fixes in current environment
php test_fixes.php

# Check for any syntax errors
php -l includes/Checker.php
```

## Deployment Steps

### Step 1: Deploy Updated Checker.php
```bash
# The updated Checker.php is already in place
# Verify syntax
php -l includes/Checker.php

# If you need to restore from backup:
# cp /home/netadmin/study/monitoring/includes/Checker.php.backup /home/netadmin/study/monitoring/includes/Checker.php
```

### Step 2: Configure Debug Mode (Optional)
```bash
# For production, you may want to disable debug mode
# Edit config.php or set environment variable:
# export DEBUG_MODE=false

# Or add to your .env file:
# echo "DEBUG_MODE=false" >> .env
```

### Step 3: Test the Deployment
```bash
# Run comprehensive test
php test_fixes.php

# Test specific problematic sites
php -r "
require_once 'config.php';
require_once 'includes/Checker.php';

\$sites = [
    ['name' => 'euclideesoftwaresolutions.in', 'url' => 'https://euclideesoftwaresolutions.in', 'check_type' => 'http'],
    ['name' => 'lumen-luxe.com', 'url' => 'https://lumen-luxe.com', 'check_type' => 'http'],
    ['name' => 'mankconsultant.com', 'url' => 'https://mankconsultant.com', 'check_type' => 'http']
];

foreach (\$sites as \$site) {
    echo 'Testing ' . \$site['name'] . '...\n';
    \$result = Checker::check(\$site);
    echo 'Status: ' . \$result['status'] . '\n';
    echo 'Error: ' . (\$result['error_message'] ?? 'None') . '\n';
    echo '---\n';
}
"
```

### Step 4: Monitor System Logs
```bash
# Monitor error logs for debugging information
tail -f /var/log/php_errors.log

# Or check system logs
tail -f /var/log/syslog | grep -i sitemonitor

# Check application logs if configured
tail -f /home/netadmin/study/monitoring/logs/app.log
```

## Post-Deployment Verification

### 1. Check Monitoring Dashboard
- Access your monitoring dashboard
- Verify that sites previously showing "Network error 61" now show correct status
- Check if DNS resolution failures are reduced

### 2. Verify Error Reduction
```bash
# Run test again to compare with pre-deployment
php test_fixes.php

# Expected results:
# - Encoding failures: 0 (was 50+)
# - DNS failures: Reduced (may still occur for genuinely unreachable sites)
# - Better error messages and categorization
```

### 3. Monitor Performance
```bash
# Check if response times are reasonable
# Monitor system resource usage
top -p $(pgrep -f "php.*monitoring")

# Check memory usage
ps aux | grep -E "(php|curl)" | grep -v grep
```

## Troubleshooting Guide

### If Encoding Errors Persist
1. **Check cURL Version**
```bash
curl --version
php -r "print_r(curl_version());"
```

2. **Verify cURL Compiled Features**
```bash
php -r "
\$version = curl_version();
echo 'Features: ' . \$version['features'] . PHP_EOL;
echo 'Available encodings: ' . (\$version['libz_version'] ? 'gzip/deflate' : 'none') . PHP_EOL;
"
```

3. **Update cURL if needed**
```bash
# Ubuntu/Debian
sudo apt update
sudo apt install curl php-curl

# CentOS/RHEL
sudo yum update curl php-curl
```

### If DNS Issues Persist
1. **Check DNS Configuration**
```bash
# Test DNS resolution manually
nslookup euclideesoftwaresolutions.in
dig euclideesoftwaresolutions.in
host euclideesoftwaresolutions.in

# Check system DNS servers
cat /etc/resolv.conf
```

2. **Test Different DNS Servers**
```bash
# Try using Google DNS
nslookup euclideesoftwaresolutions.in 8.8.8.8
nslookup euclideesoftwaresolutions.in 1.1.1.1
```

3. **Check Firewall/Network**
```bash
# Test basic connectivity
ping -c 4 euclideesoftwaresolutions.in
telnet euclideesoftwaresolutions.in 443
```

### If Performance Issues Occur
1. **Adjust Timeouts**
```bash
# Edit config.php to adjust timeouts if needed
# HTTP_TIMEOUT, DNS_TIMEOUT, SSL_TIMEOUT, etc.
```

2. **Disable Debug Mode in Production**
```bash
# Set DEBUG_MODE=false in config.php or environment
```

## Configuration Options

### Debug Mode Settings
```php
// In config.php or .env file
define('DEBUG_MODE', false);  // Set to false in production

// Or in .env file
DEBUG_MODE=false
```

### Timeout Adjustments
```php
// In config.php - adjust based on your network conditions
define('HTTP_TIMEOUT', 30);    // Increase if sites are slow
define('DNS_TIMEOUT', 10);     // Increase for slow DNS
define('SSL_TIMEOUT', 15);     // Adjust for SSL handshake time
```

### Retry Configuration
```php
// In config.php - adjust retry behavior
define('MAX_RETRIES', 3);        // Number of retry attempts
define('RETRY_DELAY', 1000);    // Delay between retries (milliseconds)
```

## Monitoring and Maintenance

### Daily Checks
1. Monitor error logs for new issues
2. Check monitoring dashboard for site status
3. Review system performance metrics

### Weekly Maintenance
1. Update cURL and PHP if security updates available
2. Review and rotate log files
3. Backup configuration and database

### Monthly Review
1. Analyze failure patterns and adjust timeouts
2. Review DNS resolution performance
3. Optimize retry logic based on failure rates

## Rollback Plan

If issues occur after deployment:

### Immediate Rollback
```bash
# Restore original Checker.php
cp /home/netadmin/study/monitoring/includes/Checker.php.backup /home/netadmin/study/monitoring/includes/Checker.php

# Restart monitoring service if applicable
sudo systemctl restart monitoring-service
# or
php -f /home/netadmin/study/monitoring/cron_runner.php
```

### Partial Rollback
```bash
# Keep fixes but disable debug mode
# Edit config.php and set DEBUG_MODE=false

# Or adjust timeouts if performance issues
# Edit config.php and increase timeout values
```

## Expected Results

### Before Fixes
- 50+ sites with "Network error 61: Unrecognized content encoding type"
- 5 sites with DNS resolution failures
- Poor error messages and categorization

### After Fixes
- 0 sites with encoding errors (cURL configuration fixed)
- DNS failures only for genuinely unreachable sites
- Enhanced error messages with detailed debugging information
- Better error categorization and retry logic
- Graceful fallback mechanisms

## Support

If you encounter issues:

1. **Check logs** - Enable DEBUG_MODE=true for detailed logging
2. **Run test script** - `php test_fixes.php` for comprehensive testing
3. **Verify configuration** - Check all timeout and retry settings
4. **Test manually** - Use curl commands to test specific sites
5. **Monitor resources** - Check system memory and CPU usage

## Success Criteria

Deployment is successful when:

1. [ ] No "Network error 61" or "Unrecognized content encoding type" errors
2. [ ] DNS failures only occur for genuinely unreachable sites
3. [ ] Error messages are clear and actionable
4. [ ] System performance remains acceptable
5. [ ] Debug logging provides useful information when enabled
6. [ ] Retry logic works correctly for transient failures

---

**Deployment Date:** April 10, 2026  
**Version:** 1.0 - Critical Error Fixes  
**Next Review:** After 1 week of production monitoring
