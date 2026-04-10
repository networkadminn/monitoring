# Enhanced Website Monitoring System

## Overview

This enhanced monitoring system provides detailed website uptime checks with comprehensive performance analysis, SSL certificate monitoring, error categorization, and advanced connectivity analysis.

## New Features

### 1. Enhanced HTTP/HTTPS Monitoring
- **Detailed Performance Metrics**: DNS time, connect time, TTFB (Time to First Byte), total response time
- **Content Analysis**: Automatic detection of error patterns, performance indicators, and content encoding
- **SSL Chain Analysis**: Full certificate chain validation and expiry tracking
- **Connectivity Analysis**: HTTP version detection, server information, security headers analysis

### 2. Advanced Error Categorization
- **Intelligent Error Classification**: Automatically categorizes errors into timeout, DNS, SSL, connection, server, client, and content validation errors
- **Retry Logic**: Configurable retry mechanism with exponential backoff for failed checks
- **Error Statistics**: Historical error tracking and analysis

### 3. Performance Monitoring
- **Real-time Metrics**: Live performance data collection during checks
- **Historical Trends**: 24-hour performance trend analysis
- **Aggregated Statistics**: Hourly, daily, and weekly performance aggregation
- **Threshold Alerts**: Configurable performance thresholds and alerting

### 4. SSL Certificate Monitoring
- **Chain Analysis**: Complete SSL certificate chain validation
- **Expiry Tracking**: Advanced expiry warnings with configurable thresholds
- **Certificate Details**: Subject, issuer, algorithm, and validation information
- **Security Issues**: Automatic detection of common SSL problems

### 5. Enhanced Configuration
- **Granular Timeouts**: Different timeouts for HTTP, SSL, port, DNS, and ping checks
- **Feature Toggles**: Enable/disable specific monitoring features
- **Retry Configuration**: Configurable retry attempts and delays
- **Performance Settings**: Adjustable monitoring intensity

## Configuration

### Environment Variables

Add these to your `.env` file:

```bash
# Enhanced Monitoring Settings
ENABLE_DETAILED_MONITORING=true
HTTP_TIMEOUT=30
SSL_TIMEOUT=15
PORT_TIMEOUT=10
DNS_TIMEOUT=10
PING_TIMEOUT=5
MAX_REDIRECTS=5

# Feature Toggles
ENABLE_CONTENT_ANALYSIS=true
ENABLE_SSL_CHAIN_ANALYSIS=true
ENABLE_PERFORMANCE_METRICS=true

# Retry Configuration
RETRY_FAILED_CHECKS=true
MAX_RETRIES=3
RETRY_DELAY=1000
```

### Database Migration

Run the database migration to enable enhanced features:

```bash
mysql -u username -p database_name < database_migration_enhanced_monitoring.sql
```

## API Endpoints

### Enhanced Monitoring Endpoints

- `GET api.php?action=detailed_site_status&site_id={id}` - Comprehensive site analysis
- `GET api.php?action=performance_trends&site_id={id}&hours=24` - Performance trends data
- `GET api.php?action=ssl_analysis&site_id={id}` - SSL certificate analysis
- `GET api.php?action=error_categories&site_id={id}&days=7` - Error statistics
- `GET api.php?action=monitoring_config` - Current monitoring configuration

### Response Format

Enhanced endpoints return detailed JSON with the following structure:

```json
{
  "site": { /* site information */ },
  "latest_log": { 
    "performance_data": { /* detailed metrics */ },
    "content_check": { /* content analysis */ },
    "ssl_details": { /* SSL information */ },
    "connectivity": { /* connectivity data */ },
    "error_category": "error_type",
    "retry_count": 0
  },
  "performance_metrics": [ /* historical performance data */ ],
  "ssl_certificates": [ /* SSL certificate chain */ ],
  "error_statistics": [ /* error analysis */ ]
}
```

## Frontend Integration

### JavaScript Components

Include the enhanced monitoring JavaScript:

```html
<script src="assets/js/enhanced-monitoring.js"></script>
```

### Enhanced Details Button

Add enhanced details buttons to your site listings:

```html
<button class="btn btn-enhanced-details" data-site-id="{{ site.id }}">
  Enhanced Details
</button>
```

### Modal Display

The enhanced monitoring system automatically creates modal dialogs for detailed analysis when users click the "Enhanced Details" button.

## Performance Metrics

### Available Metrics

- **dns_time**: DNS resolution time in milliseconds
- **connect_time**: TCP connection establishment time
- **ttfb**: Time to First Byte (server processing time)
- **total_time**: Total request/response time
- **download_size**: Size of downloaded content in bytes
- **upload_size**: Size of uploaded content in bytes
- **redirect_count**: Number of HTTP redirects followed

### Performance Thresholds

Default performance thresholds (configurable):

- DNS Time: > 100ms = warning
- Connect Time: > 500ms = warning  
- TTFB: > 1000ms = warning
- Total Time: > 3000ms = warning
- Redirects: > 3 = warning

## Error Categories

### Error Types

- **timeout**: Connection or request timeout
- **dns_error**: DNS resolution failure
- **ssl_error**: SSL certificate or handshake issues
- **connection_refused**: Server not accepting connections
- **server_error**: HTTP 5xx server errors
- **client_error**: HTTP 4xx client errors
- **content_validation**: Content or response validation failures
- **network_error**: General network connectivity issues
- **system_error**: System or application errors

### Error Severity Levels

- **High**: timeout, dns_error, ssl_error, connection_refused
- **Medium**: server_error, client_error, network_error
- **Low**: content_validation, system_error

## SSL Certificate Analysis

### Certificate Information Tracked

- Subject and issuer information
- Validity period (issue and expiry dates)
- Signature algorithm
- Public key details
- Chain position and length
- Common security issues

### SSL Issues Detected

- Certificate expiry warnings
- Expired certificates
- Localhost/self-signed certificates
- Weak signature algorithms
- Incomplete certificate chains

## Content Analysis

### Automatic Detection

- **Error Patterns**: Database errors, PHP errors, server errors, maintenance pages
- **Performance Indicators**: CDN usage, caching headers, compression
- **Content Type**: HTML, JSON, XML detection
- **Encoding**: Character encoding and compression detection

### Error Pattern Recognition

The system automatically detects common error patterns in response content:

- Database connection errors
- PHP fatal errors and warnings
- HTTP 500 error messages
- Maintenance mode pages
- Rate limiting messages

## Retry Logic

### Retry Configuration

- **Maximum Retries**: Configurable (default: 3)
- **Retry Delay**: Delay between retries in milliseconds (default: 1000ms)
- **Retry Conditions**: Only failed checks are retried
- **Retry Tracking**: Number of retries is logged and displayed

### Retry Behavior

1. Initial check attempt
2. If failed, wait configured delay
3. Retry check up to maximum attempts
4. Log final result with retry count
5. Continue with normal alerting logic

## Database Schema

### New Tables

- **logs_enhanced**: Extended log entries with detailed monitoring data
- **performance_metrics**: Aggregated performance statistics
- **ssl_certificates**: SSL certificate information and history
- **error_categories**: Error categorization and statistics
- **monitoring_config_history**: Configuration change tracking

### Enhanced Views

- **v_enhanced_site_status**: Comprehensive site status with latest metrics

## Performance Impact

### Resource Usage

- **Memory**: Moderate increase due to detailed data collection
- **CPU**: Additional processing for content analysis and SSL validation
- **Storage**: Increased log storage for enhanced data
- **Network**: Minimal impact, same request volume

### Optimization Features

- **Conditional Features**: Enable/disable specific monitoring features
- **Configurable Timeouts**: Adjust timeouts based on requirements
- **Data Aggregation**: Automatic aggregation of historical data
- **Cleanup Procedures**: Automated cleanup of old enhanced data

## Troubleshooting

### Common Issues

1. **Missing Enhanced Data**: Run database migration
2. **High Memory Usage**: Disable performance-intensive features
3. **Slow Checks**: Increase timeouts or reduce retry attempts
4. **Missing SSL Data**: Check SSL_CHAIN_ANALYSIS setting

### Debug Mode

Enable debug mode by setting:

```bash
APP_ENV=development
```

This provides additional logging and error information.

## Migration from Basic Monitoring

### Backward Compatibility

The enhanced system is fully backward compatible with existing basic monitoring. Existing sites continue to work without modification.

### Gradual Upgrade

1. Install database migration
2. Add environment variables
3. Test with a few sites
4. Enable features gradually
5. Update frontend components

### Data Migration

Existing log data remains in the `logs` table. New enhanced data is stored in `logs_enhanced`. Both tables are maintained for compatibility.

## Best Practices

### Configuration

1. **Timeouts**: Set appropriate timeouts for your infrastructure
2. **Retries**: Balance reliability with performance impact
3. **Features**: Enable only needed features to reduce resource usage
4. **Thresholds**: Set performance thresholds based on SLA requirements

### Monitoring

1. **Performance**: Monitor monitoring system performance
2. **Storage**: Watch database storage growth
3. **Alerts**: Configure appropriate alert thresholds
4. **Maintenance**: Regular cleanup of old data

### Security

1. **SSL**: Always enable SSL monitoring for HTTPS sites
2. **Headers**: Monitor security headers compliance
3. **Certificates**: Track certificate expiry proactively
4. **Errors**: Analyze error patterns for security issues

## Support

For issues with the enhanced monitoring system:

1. Check the troubleshooting section
2. Review configuration settings
3. Verify database migration
4. Check error logs for detailed information
5. Test with debug mode enabled

## Future Enhancements

Planned improvements for future releases:

- Machine learning for anomaly detection
- Advanced performance baselines
- Multi-location monitoring integration
- Real-time alerting improvements
- Enhanced reporting capabilities
