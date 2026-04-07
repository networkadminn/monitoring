# Microsoft Teams Integration

## Features

Send site monitoring alerts directly to Microsoft Teams channels with rich formatted messages including:
- Site name and URL
- Error details
- Response time
- Direct link to monitor details
- Color-coded alerts (red for down, green for recovery, orange for SSL expiry)

## Setup Instructions

### 1. Create a Teams Incoming Webhook

1. In **Microsoft Teams**, go to the channel where you want alerts
2. Click the **⋯ (More options)** menu at the top
3. Select **Connectors**
4. Search for **"Incoming Webhook"**
5. Click **Configure**
6. Name it: `Site Monitor`
7. Click **Create**
8. **Copy the webhook URL** (you'll need this)

### 2. Add to Monitoring System

1. Go to your monitoring dashboard
2. **Edit** a monitor (or create a new one)
3. Go to the **Alerts** tab
4. Paste the webhook URL in the **Microsoft Teams Webhook** field
5. Click **Save Monitor**

### 3. Test the Integration

1. After saving, you should see a test message in Teams
2. Alternatively, wait for the next scheduled check
3. Or manually trigger a check from the dashboard

## What Alerts Look Like

### Down Alert
```
❌ DOWN ALERT - Site Name is currently unavailable
Site: Website Monitoring
URL: https://example.com
Error: Expected HTTP 200, got 503
Response Time: 2543ms
[Button: View Monitor]
```

### Recovery Alert
```
✅ RECOVERED - Site Name is back online
Site: Website Monitoring  
URL: https://example.com
Response Time: 145ms
[Button: View Monitor]
```

### SSL Expiry Alert
```
⚠️ SSL EXPIRING - Site Name Certificate expires in 7 days
Site: Website Monitoring
URL: https://example.com
[Button: View Monitor]
```

## Multiple Teams Channels

You can send alerts to different Teams channels for each monitor:

1. Create a separate webhook for each channel following steps 1-2 above
2. Add each webhook URL to the corresponding monitor
3. Each monitor can have its own Teams webhook

## Webhook URL Format

Teams webhook URLs look like:
```
https://outlook.webhook.office.com/webhookb2/XXXXX@XXXXX/IncomingWebhook/XXXXX
```

⚠️ **Keep these URLs safe** - they allow posting to your Teams channel!

## Troubleshooting

### "No alerts appearing in Teams"
- [ ] Webhook URL is correct (copy-paste carefully)
- [ ] Teams connector has appropriate permissions
- [ ] Check monitoring logs: `tail -f /var/log/monitor.log`
- [ ] Ensure `ENABLE_TEAMS_ALERTS` is `true` in config.php (it should be by default)

### "Webhook URL is invalid"
- [ ] Regenerate the webhook in Teams
- [ ] Copy again and update the monitor

### "Teams says the webhook failed"
- [ ] Check the webhook hasn't expired (regenerate if needed)
- [ ] Ensure your Teams tenant allows webhook integrations

## Configuration

Teams alerting is enabled by default in `config.php`:
```php
define('ENABLE_TEAMS_ALERTS', true);
```

Disable if needed:
```php
define('ENABLE_TEAMS_ALERTS', false);
```

## Alert Message Format

Teams receives **Adaptive Cards** with:
- **Color coding**: Red (down), Green (recovery), Yellow (SSL)
- **Rich formatting**: Structured data with labels
- **Action buttons**: Direct link to monitor details
- **Responsive**: Works on desktop and mobile Teams apps

## Combining with Other Alerts

Teams works alongside other alerting methods:
- ✓ Email alerts (still sent)
- ✓ SMS alerts (if configured)
- ✓ Telegram (if configured)
- ✓ Teams (new!)

Each monitor can have any/all of these configured simultaneously.

## Data Security

- Webhook URLs are stored in the database
- URLs are treated as sensitive configuration
- Only appears in Alerts tab when editing a monitor
- Never logged in plain text

## Advanced: Multiple Webhooks

For advanced routing, you could:
1. Create different monitoring monitors for different Teams channels
2. Each with its own webhook
3. Tag them appropriately (e.g., "team-backend", "team-frontend")

## Support

If alerts aren't working:
1. Check cron logs: `tail -f /var/log/monitor.log`
2. Verify webhook URL in monitor settings
3. Run database migration: `php install.php`
4. Ensure `ENABLE_TEAMS_ALERTS` is true
