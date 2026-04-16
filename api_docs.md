# Site Monitor API Documentation

## Overview

The Site Monitor API provides RESTful endpoints for monitoring website status, managing sites, and retrieving analytics data. All endpoints return JSON responses and support CORS for web applications.

## Base URL

```
https://your-domain.com/monitor/api.php
```

## Authentication

All API endpoints require authentication except for health checks. Authentication is handled via session-based login.

### Login Endpoint

```http
POST /monitor/login.php
Content-Type: application/x-www-form-urlencoded

username=admin&password=yourpassword&login_token=CSRF_TOKEN
```

### Session Management

- Sessions automatically expire after 2 hours
- Session IDs are regenerated every 30 minutes
- IP binding prevents session hijacking

## Rate Limiting

API endpoints implement IP-based rate limiting:

- **Default limits**: 30 requests per minute
- **Strict endpoints**: 10-20 requests per minute
- **Rate limit headers**: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`

## Endpoints

### Health & Status

#### GET /api.php?action=health

Get system health overview and statistics.

**Response:**
```json
{
  "total_sites": 150,
  "sites_up": 142,
  "sites_down": 8,
  "avg_response": 245.67,
  "health_score": 94.7,
  "uptime_24h": 98.5,
  "last_updated": "2024-04-16T15:30:00Z"
}
```

#### GET /health.php

Comprehensive system health check including database, cache, filesystem, memory, and disk status.

**Response:**
```json
{
  "status": "healthy",
  "timestamp": "2024-04-16T15:30:00Z",
  "uptime": "15d 4h 23m",
  "version": "2.0.0",
  "checks": {
    "database": {
      "status": "healthy",
      "message": "Database connection successful",
      "response_time_ms": 12.5
    },
    "cache": {
      "status": "healthy",
      "message": "Cache read/write successful",
      "stats": {...}
    }
  }
}
```

### Sites Management

#### GET /api.php?action=sites

Retrieve all active sites with their current status.

**Query Parameters:**
- `type` (optional): Filter by type (`websites`, `ssl`, `ports`)
- `tag` (optional): Filter by tag
- `status` (optional): Filter by status (`up`, `down`, `warning`)

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Example Site",
      "url": "https://example.com",
      "check_type": "http",
      "status": "up",
      "response_time": 234,
      "last_checked": "2024-04-16T15:29:00Z",
      "uptime_percentage": 99.8,
      "tags": ["production", "critical"]
    }
  ],
  "total": 150,
  "filtered": 150
}
```

#### POST /api.php?action=add_site

Add a new site to monitor.

**Request Body:**
```json
{
  "name": "New Site",
  "url": "https://newsite.com",
  "check_type": "http",
  "expected_status": 200,
  "alert_email": "admin@example.com",
  "check_interval": 5,
  "failure_threshold": 3,
  "is_active": true,
  "tags": "production,critical"
}
```

**Response:**
```json
{
  "created": 151,
  "message": "Monitor added successfully"
}
```

#### POST /api.php?action=update_site

Update an existing site.

**Request Body:**
```json
{
  "id": 151,
  "name": "Updated Site Name",
  "failure_threshold": 5
}
```

#### POST /api.php?action=delete_site

Delete a site.

**Query Parameters:**
- `id` (required): Site ID to delete

**Response:**
```json
{
  "deleted": 151,
  "message": "Site deleted successfully"
}
```

### Monitoring Data

#### GET /api.php?action=site_detail&id={site_id}

Get detailed information about a specific site including trends and incidents.

**Response:**
```json
{
  "site": {...},
  "trend": [
    {"hour": "2024-04-16 15:00", "avg_rt": 245.6, "checks": 12},
    {"hour": "2024-04-16 14:00", "avg_rt": 238.2, "checks": 12}
  ],
  "incidents": [...],
  "uptime_chart": [...],
  "histogram": {...}
}
```

#### GET /api.php?action=response_trend&ids={site_ids}

Get response time trends for multiple sites.

**Query Parameters:**
- `ids` (required): Comma-separated site IDs
- `start_date` (optional): Start date (YYYY-MM-DD)
- `end_date` (optional): End date (YYYY-MM-DD)

#### GET /api.php?action=incidents&id={site_id}&limit={limit}

Get recent incidents for a site.

#### GET /api.php?action=ssl_expiry

Get SSL certificate expiry information for all sites.

**Response:**
```json
{
  "data": [
    {
      "name": "Example Site",
      "ssl_expiry_days": 45,
      "ssl_subject": "example.com",
      "ssl_issuer": "Let's Encrypt"
    }
  ]
}
```

### System Statistics

#### GET /api.php?action=system_uptime

Get system-wide uptime trends.

#### GET /api.php?action=slowest

Get slowest performing sites.

**Response:**
```json
{
  "data": [
    {"name": "Slow Site", "avg_rt": 1234.5},
    {"name": "Another Site", "avg_rt": 987.3}
  ]
}
```

#### GET /api.php?action=histogram&id={site_id}

Get response time distribution histogram.

### Operations

#### POST /api.php?action=test_connection

Test connection settings before saving a site.

**Request Body:**
```json
{
  "url": "https://example.com",
  "check_type": "http",
  "auth_type": "bearer",
  "auth_value": "token123"
}
```

#### POST /api.php?action=check_site

Trigger an immediate check for a site.

**Request Body:**
```json
{
  "id": 151
}
```

## Error Responses

All endpoints return consistent error responses:

```json
{
  "error": "Error message",
  "code": 400,
  "timestamp": "2024-04-16T15:30:00Z"
}
```

### HTTP Status Codes

- `200 OK`: Successful request
- `400 Bad Request`: Invalid parameters or validation error
- `401 Unauthorized`: Not logged in or invalid session
- `403 Forbidden`: CSRF token invalid or rate limit exceeded
- `404 Not Found`: Endpoint or resource not found
- `429 Too Many Requests`: Rate limit exceeded
- `500 Internal Server Error`: Server error

## Data Types

### Site Object

```json
{
  "id": 151,
  "name": "Site Name",
  "url": "https://example.com",
  "check_type": "http|ssl|port|dns|keyword|ping|api",
  "status": "up|down|warning",
  "response_time": 245.6,
  "error_message": null,
  "uptime_percentage": 99.8,
  "last_checked": "2024-04-16T15:29:00Z",
  "tags": ["tag1", "tag2"],
  "alert_email": "admin@example.com",
  "alert_phone": "+1234567890",
  "check_interval": 5,
  "failure_threshold": 3,
  "recovery_threshold": 2,
  "is_active": true,
  "created_at": "2024-04-01T10:00:00Z",
  "updated_at": "2024-04-16T15:29:00Z"
}
```

### Check Result Object

```json
{
  "status": "up|down|warning",
  "response_time": 245.6,
  "error_message": null,
  "error_category": "timeout|dns_error|ssl_error|network_error",
  "ssl_expiry_days": 45,
  "performance": {
    "dns_time": 12.5,
    "connect_time": 45.2,
    "ttfb": 89.7,
    "total_time": 245.6,
    "redirect_count": 0
  },
  "content_check": {
    "size_bytes": 15360,
    "word_count": 245,
    "has_html": true,
    "error_patterns": []
  },
  "retry_count": 0,
  "circuit_breaker_state": "closed|open|half_open"
}
```

## Advanced Features

### Multi-Location Monitoring

Sites can be checked from multiple geographic locations:

```json
{
  "location_results": [
    {
      "location": "us-east-1",
      "location_name": "US East",
      "status": "up",
      "response_time": 145.2
    },
    {
      "location": "eu-west-1",
      "location_name": "Europe West",
      "status": "down",
      "response_time": null,
      "error_message": "Connection timeout"
    }
  ],
  "aggregated": {
    "status": "warning",
    "response_time": 145.2,
    "error_message": "Down from 1 of 2 locations"
  }
}
```

### Circuit Breaker Status

```json
{
  "circuit_breaker_state": "open",
  "failure_count": 7,
  "next_attempt": "2024-04-16T15:35:00Z"
}
```

### Performance Metrics

```json
{
  "performance": {
    "dns_time": 12.5,
    "connect_time": 45.2,
    "ttfb": 89.7,
    "total_time": 245.6,
    "redirect_count": 0,
    "download_size": 15360,
    "upload_size": 0
  }
}
```

## SDKs and Libraries

### JavaScript Client

```javascript
class SiteMonitorAPI {
  constructor(baseURL, csrfToken) {
    this.baseURL = baseURL;
    this.csrfToken = csrfToken;
  }
  
  async request(action, params = {}) {
    const url = new URL(this.baseURL);
    url.searchParams.set('action', action);
    
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': this.csrfToken
      },
      body: JSON.stringify(params)
    });
    
    return response.json();
  }
  
  async getSites(filters = {}) {
    return this.request('sites', filters);
  }
  
  async addSite(siteData) {
    return this.request('add_site', siteData);
  }
  
  async checkSite(siteId) {
    return this.request('check_site', { id: siteId });
  }
}
```

### Python Client

```python
import requests
import json

class SiteMonitorAPI:
    def __init__(self, base_url, session_cookie):
        self.base_url = base_url
        self.session = requests.Session()
        self.session.cookies.set('session_id', session_cookie)
    
    def request(self, action, params=None, method='GET'):
        url = f"{self.base_url}?action={action}"
        
        if method == 'GET':
            response = self.session.get(url, params=params)
        else:
            response = self.session.post(url, json=params)
        
        response.raise_for_status()
        return response.json()
    
    def get_sites(self, filters=None):
        return self.request('sites', filters)
    
    def add_site(self, site_data):
        return self.request('add_site', site_data, 'POST')
    
    def check_site(self, site_id):
        return self.request('check_site', {'id': site_id}, 'POST')
```

## Webhooks

### Alert Webhooks

Configure webhooks to receive real-time alerts:

```json
{
  "webhook_url": "https://your-service.com/webhook",
  "webhook_events": ["site_down", "site_up", "ssl_expiry"],
  "webhook_secret": "your-secret-key"
}
```

**Webhook Payload:**
```json
{
  "event": "site_down",
  "site": {...},
  "check_result": {...},
  "timestamp": "2024-04-16T15:30:00Z",
  "signature": "sha256-hash"
}
```

## Rate Limiting Details

### Endpoint-Specific Limits

| Endpoint | Limit | Window |
|----------|--------|--------|
| health | 60/min | 1 min |
| sites | 30/min | 1 min |
| add_site | 10/min | 1 min |
| update_site | 15/min | 1 min |
| check_site | 20/min | 1 min |
| test_connection | 30/min | 1 min |

### Rate Limit Headers

- `X-RateLimit-Limit`: Maximum requests per window
- `X-RateLimit-Remaining`: Remaining requests in current window
- `X-RateLimit-Reset`: Unix timestamp when window resets

## Security Considerations

1. **CSRF Protection**: All mutating requests require valid CSRF token
2. **Session Security**: IP binding and session regeneration
3. **Input Validation**: All inputs validated and sanitized
4. **Rate Limiting**: IP-based rate limiting prevents abuse
5. **HTTPS Required**: All production deployments should use HTTPS

## Monitoring the API

### Health Endpoint

Monitor the API health:

```bash
curl -f https://your-domain.com/monitor/health.php
```

### Metrics Endpoint

Access Prometheus metrics:

```bash
curl https://your-domain.com/monitor/metrics
```

### Log Monitoring

Monitor API access logs for security:

```bash
tail -f logs/api_2024-04-16.log
```

## Troubleshooting

### Common Issues

1. **401 Unauthorized**: Check session and login
2. **403 Forbidden**: Verify CSRF token
3. **429 Too Many Requests**: Implement backoff in client
4. **500 Internal Error**: Check server logs

### Debug Mode

Enable debug mode by setting:

```bash
APP_ENV=development
DEBUG_MODE=true
```

This provides detailed error responses and additional logging.

## Version History

- **v2.0.0**: Current version with async checking, advanced caching, and enhanced security
- **v1.5.0**: Added multi-location support and circuit breaker
- **v1.0.0**: Initial release

## Support

For API support and questions:

- Documentation: https://docs.sitemonitor.com
- Issues: https://github.com/sitemonitor/issues
- Community: https://community.sitemonitor.com
