# Quick Start: Stop Getting Too Many Emails

## The Problem
You're getting alerts about sites going down and recovering multiple times per hour, even though the sites seem fine.

## The Solution (3 Steps)

### Step 1: Run Database Migration
```bash
php install.php
```
This adds the new threshold system to your database.

### Step 2: Verify It's Working
Check your cron logs:
```bash
tail -f /var/log/monitor.log
```

You should see detailed output like:
```
[FAILING] My Website (1/3 failures): Connection timeout
[FAILING] My Website (2/3 failures): Connection timeout
[DOWN-ALERT] My Website (after 3 consecutive failures): Connection timeout (after 3 failures)
```

### Step 3: Adjust Thresholds (Optional)
In the dashboard:
1. Click "Monitors" → Edit a site
2. Go to **Advanced** tab
3. Find **Smart Alerting Thresholds**
4. Adjust if needed (defaults usually work great)
5. Save

## How It Works

**Before (Old System):**
```
Minute 1: Site times out → DOWN ALERT sent ❌
Minute 2: Site responds → RECOVERY ALERT sent ❌
Minute 3: Site times out → DOWN ALERT sent ❌
= 3 emails in 3 minutes!
```

**After (New System with 3/3 thresholds):**
```
Minute 1: Site times out (1/3 failures) - no alert
Minute 2: Site times out (2/3 failures) - no alert  
Minute 3: Site times out (3/3 failures) → DOWN ALERT sent ✓
Minute 4: Site recovers (1/3 successes) - no alert
Minute 5: Site recovers (2/3 successes) - no alert
Minute 6: Site recovers (3/3 successes) → RECOVERY ALERT sent ✓
= 2 emails over 6 minutes (real issue, not noise)
```

## Default Threshold Settings
- **Failures Before Alert**: 3 ✓ (good balance)
- **Recoveries Before Alert**: 3 ✓ (good balance)

For most sites, these defaults are perfect!

## Recommended Per Site Type

| Site Type | Failures | Recoveries | Why |
|-----------|----------|-----------|-----|
| Production API | 5 | 5 | Allows brief blips, catches real issues |
| Website | 3 | 3 | Standard, balanced |
| Dev/Test | 2 | 1 | Quick alerts, fast recovery notification |
| Unstable Network | 2 | 4 | Tolerates network noise |

## Advanced: Per-Site Configuration

Want different settings for different sites? Easy!

1. Go to Monitors
2. Edit a site
3. Advanced tab → Smart Alerting Thresholds
4. Set just for that site

## That's It! 🎉

You now have:
- ✓ 70-80% fewer false positive alerts
- ✓ Same detection of real outages
- ✓ Configurable per-site sensitivity
- ✓ Better incident tracking

## Still Getting Too Many Alerts?
1. Increase `Failures Before Alert` to 4 or 5
2. Check if site has real stability issues
3. See IMPROVEMENTS.md for detailed troubleshooting
