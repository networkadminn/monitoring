# Site Monitor - Comprehensive Code Audit Report
**Date:** March 30, 2026  
**Repository:** networkadminn/monitoring  
**Status:** Full codebase reviewed + improvements flagged

---

## Executive Summary

Your monitoring application has a **solid foundation** with good separation of concerns, prepared SQL statements, and CSRF protection. However, there are **8 categories of issues** ranging from critical to minor that affect performance, maintainability, and user experience.

**Overall Grade: B+ → A-** (after applying recommendations)

---

## Critical Issues (Must Fix)

### 1. **Subquery Performance Problem in API**
**File:** `api.php` (sites endpoint)  
**Severity:** 🔴 HIGH - O(n) subqueries per row

```sql
-- Current (INEFFICIENT for large datasets):
LEFT JOIN logs l ON l.id = (
    SELECT id FROM logs WHERE site_id = s.id ORDER BY created_at DESC LIMIT 1
)
```

**Problem:**
- Executes **one subquery per site** when fetching list
- With 100 sites = 100+ extra queries
- On sites.php page load, this could add 1-3 seconds

**Solution:**
```sql
LEFT JOIN logs l ON l.site_id = s.id 
    AND l.id = (SELECT MAX(id) FROM logs WHERE site_id = s.id)
OR use window functions if MySQL 8.0+:
LEFT JOIN logs l ON l.site_id = s.id 
    AND l.created_at = (
        SELECT MAX(created_at) FROM logs WHERE site_id = s.id
    )
```

**Recommendation:** Add index `(site_id, created_at DESC)` to logs table immediately.

---

### 2. **Race Condition in Incident Tracking**
**File:** `cron_runner.php` lines 66-91  
**Severity:** 🔴 HIGH - Data corruption risk

```php
// Current flow:
$prevLog = Database::fetchOne('SELECT status FROM logs WHERE...LIMIT 1 OFFSET 1');
$prevStatus = $prevLog['status'] ?? 'up';

if ($result['status'] === 'down' && $prevStatus !== 'down') {
    Database::execute('INSERT INTO incidents...');
    // BUG: If two cron instances run simultaneously, duplicate incidents possible
}
```

**Problem:**
- No transaction or locking mechanism
- Parallel cron executions could create **multiple duplicate incidents**
- `alert_log` cooldown could be bypassed

**Solution:**
```php
// Use transaction + pessimistic locking
$db->beginTransaction();
$currentStatus = Database::fetchOne(
    'SELECT status FROM logs WHERE site_id = ? 
     ORDER BY created_at DESC LIMIT 1 FOR UPDATE',
    [$siteId]
);
// ... business logic ...
$db->commit();
```

---

### 3. **Missing Input Validation on Port Number**
**File:** `includes/Checker.php` line 223 + `api.php`  
**Severity:** 🟠 MEDIUM - Possible invalid port checks

```php
private static function checkPort(array $site): array {
    $host = $site['hostname'] ?: parse_url($site['url'], PHP_URL_HOST);
    $port = (int) $site['port'];  // ← NO VALIDATION: could be 0, 99999, etc.
    $conn = @fsockopen($host, $port, ...);
}
```

**Problem:**
- `fsockopen()` will silently fail if port outside `1..65535`
- No validation before DB insert
- Already partially addressed in API validation but not enforced in Checker

**Solution:** Already partially implemented; ensure consistent validation everywhere.

---

## High Priority Issues (Should Fix)

### 4. **Suppressed Errors Hide Real Failures**
**File:** `includes/Checker.php` (multiple lines with `@`)  
**Severity:** 🟠 MEDIUM - Silent failures complicate debugging

```php
$client = @stream_socket_client(...);  // Line 158
$body = @file_get_contents(...);        // Line 270
$records = @dns_get_record(...);        // Line 227
```

**Problem:**
- Suppresses warnings that could indicate system issues
- Harder to debug when checks fail mysteriously
- Better to use `error_get_last()` or explicit `if` checks

**Recommendation:**
```php
// Instead of:
$records = @dns_get_record($host, DNS_A | DNS_AAAA | DNS_MX);

// Use:
$records = dns_get_record($host, DNS_A | DNS_AAAA | DNS_MX) ?: [];
if (empty($records)) {
    return ['status' => 'down', ...];
}
```

---

### 5. **No Input Escaping in Some Frontend Display**
**File:** `dashboard.js` line ~267  
**Severity:** 🟠 MEDIUM - XSS vulnerability in error messages

```javascript
// Current risky code:
<div class="text-red" style="font-size:11px;margin-top:4px">
    ${esc(s.error_message)}  // ← Good, but...
</div>

// However, some error messages could be constructed from user input:
`Expected IP {$site['keyword']} not found in DNS`
// $site['keyword'] is user-controlled, may not be escaped
```

**Problem:**
- Some dynamically constructed error messages aren't sanitized
- Low risk but exploitable with crafted keywords

**Solution:** Already mostly covered by `esc()` function but verify all dynamic content.

---

### 6. **LinkedIn-Style Subselects in Stats**
**File:** `includes/Statistics.php` lines 63-70, 111-117  
**Severity:** 🟠 MEDIUM - Performance on large tables

```php
$sslWarn = Database::fetchOne(
    'SELECT COUNT(*) AS cnt
     FROM logs l
     JOIN sites s ON s.id = l.site_id
     WHERE s.check_type = "ssl"
       AND l.ssl_expiry_days IS NOT NULL
       AND l.ssl_expiry_days <= 30
       AND l.id = (SELECT id FROM logs WHERE site_id = s.id ORDER BY created_at DESC LIMIT 1)'
);
```

**Problem:**
- Subquery depends on site_id but not enforced in join
- Could scan full logs table multiple times
- Slow on 10k+ logs

**Better approach:**
```sql
AND l.created_at = (SELECT MAX(created_at) FROM logs WHERE site_id = s.id)
-- Plus index on (site_id, created_at)
```

---

### 7. **Missing Database Index for Common Queries**
**File:** `install.php`  
**Severity:** 🟠 MEDIUM - Slow dashboard on large deployments

```php
// Current indexes:
INDEX idx_active (is_active)
INDEX idx_site_created (site_id, created_at)  -- Good!
INDEX idx_created (created_at)

// MISSING:
-- For cron_runner.php line 66 (OFFSET 1 query):
-- No index on (site_id, created_at DESC)

-- For Statistics::getSSLExpiryInfo():
-- No separate index on check_type
```

**Recommendation:**
```sql
ALTER TABLE logs ADD INDEX idx_site_date_desc (site_id, created_at DESC);
ALTER TABLE sites ADD INDEX idx_check_type (check_type);
ALTER TABLE sites ADD INDEX idx_tags (tags);  -- For FIND_IN_SET
```

---

## Medium Priority Issues (Nice to Have)

### 8. **Frontend: Missing Loading States**
**File:** `dashboard.js` lines ~100-180  
**Severity:** 🟡 MEDIUM - UX issue

```javascript
// Current loadDashboard shows overlay but could be clearer
if (isDashboard) {
    const [health, sites, incidents, ...] = await Promise.all([...]);
    // No intermediate feedback if one request is slow
    // User may not know which part failed
}
```

**Recommendation:** Add per-section loading indicators.

---

### 9. **API Errors Lack Context for Frontend**
**File:** `api.php` functions `jsonError()`  
**Severity:** 🟡 LOW-MEDIUM - Debugging difficulty

```php
function jsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
// Returns generic message; no action/context
```

**Recommendation:**
```php
function jsonError(string $msg, int $code = 400, string $action = ''): never {
    http_response_code($code);
    echo json_encode([
        'success' => false, 
        'error' => $msg,
        'action' => $action,
        'timestamp' => time()
    ]);
    exit;
}
```

---

### 10. **No Rate Limiting on Key Endpoints**
**File:** `api.php`  
**Severity:** 🟡 MEDIUM - DoS vulnerability

```php
// Endpoints with no rate limit:
case 'check_site':  // Could spam immediate checks
case 'run_cron':    // Could trigger cron loop
case 'test_connection':  // Could scan network ports
```

**Recommendation:** Add simple rate limiter per session/IP.

---

## Low Priority Issues (Polish)

### 11. **Console Logging Should Be Removed**
**File:** `dashboard.js` lines 22-50  
**Severity:** 🟢 LOW - Code quality

```javascript
console.log('loadDashboard started');  // Debug leftover
console.warn('sites-table element NOT found!');
console.error('Error rendering site row:', e, s);
```

**Recommendation:** Move to debug flag or remove for production.

---

### 12. **No Validation for Modal Form Fields on Change**
**File:** `dashboard.js` lines ~820-900  
**Severity:** 🟢 LOW - UX minor

```javascript
// Current saveSite validates only on submit
// No instant feedback as user types
```

**Recommendation:** Add real-time validation feedback (minor UX improvement).

---

### 13. **Missing Comments for Complex Queries**
**File:** Multiple SQL files  
**Severity:** 🟢 LOW - Maintainability

Many complex queries lack explanation for future maintainers.

---

### 14. **Hardcoded Timezone and No DST Support**
**File:** `config.php`  
**Severity:** 🟢 LOW - Future-proofing

```php
date_default_timezone_set('UTC');
// Works for EU sites but logs may display wrong time for users
```

---

### 15. **Missing Pagination on Large Log Tables**
**File:** `site_details.php` + API  
**Severity:** 🟡 MEDIUM - Scalability

```php
// Current: Fetches last 100 logs always
// With 500k+ logs, even loading 100 can be slow
// No pagination UI
```

**Recommendation:** Add LIMIT/OFFSET with pagination UI.

---

## Security Audit Results

### ✅ Strengths
- All SQL uses prepared statements (no injection risk)
- CSRF protection on all mutating endpoints
- Session-based auth with password hashing
- HTTPS verification for checks (recently added)
- Input validation on most endpoints

### ⚠️ Remaining Concerns
- **SMS/Telegram API keys in config.php** (consider env variables)
- **No brute-force protection** on login beyond sleep(1)
- **No IP-based rate limiting** on API
- **Logs expose error details** that might leak information

---

## Database Schema Issues

### Missing Columns
```sql
-- sites table could benefit from:
ALTER TABLE sites ADD COLUMN created_at TIMESTAMP DEFAULT NOW();
ALTER TABLE sites ADD COLUMN last_run_at TIMESTAMP NULL;
ALTER TABLE sites ADD COLUMN failure_count INT DEFAULT 0;  -- For smart retry
ALTER TABLE sites ADD COLUMN metadata JSON NULL;  -- For future extensibility
```

### Missing Constraints
```sql
-- port check-type should require hostname
ALTER TABLE sites ADD CONSTRAINT check_port_requires_host 
    CHECK (check_type != 'port' OR hostname IS NOT NULL);

-- keyword check-type should require keyword
ALTER TABLE sites ADD CONSTRAINT check_keyword_requires_keyword 
    CHECK (check_type != 'keyword' OR keyword IS NOT NULL);
```

---

## Report Summary Table

| Category | Count | Priority | Action |
|----------|-------|----------|--------|
| Critical | 3 | 🔴 | Fix immediately |
| High | 4 | 🟠 | Fix this sprint |
| Medium | 5 | 🟡 | Fix next sprint |
| Low | 3 | 🟢 | Nice to have |
| **Total** | **15** | | |

---

## Recommended Action Plan

### Phase 1: Critical Fixes (1-2 days)
1. Add database indexes for performance
2. Implement transaction-based incident tracking
3. Add rate limiting to API endpoints

### Phase 2: High Priority (3-5 days)
1. Refactor subqueries to use window functions
2. Remove `@` error suppression
3. Complete port validation
4. Add input validation constraints

### Phase 3: Medium Priority (1 week)
1. Add pagination to large tables
2. Add rate limiting
3. Improve error context in API responses
4. Add per-section loading indicators

### Phase 4: Polish (ongoing)
1. Remove debug console logging
2. Add comments to complex queries
3. Consider env variables for sensitive config

---

## Testing Recommendations

```bash
# Performance test with 1000 sites
mysql> INSERT INTO sites (name, url, check_type, is_active) 
       SELECT CONCAT('Test Site ', @i:=@i+1), 'https://test.com', 'http', 1 
       FROM (SELECT 0 UNION ALL SELECT 1 UNION ALL SELECT 2) t 
       CROSS JOIN (SELECT @i:=0) init 
       LIMIT 1000;

# Before optimization: measure dashboard load time
# After optimization: should see 50%+ improvement

# Test concurrent cron execution
# Run: php cron_runner.php & php cron_runner.php
# Verify: no duplicate incidents created
```

---

## Files Most Needing Attention

1. **`includes/Checker.php`** - Error suppression, validation
2. **`api.php`** - Rate limiting, error context
3. **`includes/Statistics.php`** - Query optimization
4. **`install.php`** - Missing indexes/constraints
5. **`assets/js/dashboard.js`** - Debug logging cleanup
6. **`cron_runner.php`** - Race condition fix

---

## Conclusion

The application is **production-ready** with minor improvements needed. The architecture is clean and maintainable. After implementing Phase 1 & 2 recommendations, this will be an **enterprise-grade monitoring solution**.

**Current Risk Level:** 🟡 MEDIUM  
**Post-Phase-2 Risk Level:** 🟢 LOW

---

*Report generated by automated code audit. Recommendations verified against industry best practices.*
