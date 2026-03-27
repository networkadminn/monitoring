# Site Monitor

Production-ready PHP monitoring system supporting HTTP, SSL, port, DNS, and keyword checks with Chart.js dashboards and multi-channel alerts.

## Requirements

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- Apache/Nginx with mod_rewrite
- Composer
- PHP extensions: `pdo_mysql`, `curl`, `openssl`

## Installation

### 1. Clone / upload files

```bash
cp -r monitor/ /var/www/html/monitor
```

### 2. Install dependencies

```bash
cd /var/www/html/monitor
composer install --no-dev --optimize-autoloader
```

### 3. Configure

Edit `config.php` with your database credentials, SMTP settings, Twilio, and Telegram tokens.

### 4. Run the installer

Visit `http://yoursite.com/monitor/install.php` in your browser and click **Run Installation**.

Delete or restrict `install.php` after setup:

```bash
rm /var/www/html/monitor/install.php
```

### 5. Set up the cron job

Run checks every minute:

```cron
* * * * * php /var/www/html/monitor/cron_runner.php >> /var/log/site-monitor.log 2>&1
```

## Alert Configuration

### Email (Gmail)

1. Enable 2FA on your Google account
2. Generate an App Password at https://myaccount.google.com/apppasswords
3. Set `SMTP_USER`, `SMTP_PASS`, `FROM_EMAIL` in `config.php`

### Twilio SMS

1. Sign up at https://twilio.com
2. Get your Account SID and Auth Token
3. Set `SMS_API_KEY` (Account SID), `SMS_API_SECRET` (Auth Token), `SMS_FROM`

### Telegram

1. Create a bot via @BotFather — get the token
2. Get your chat ID by messaging @userinfobot
3. Set `TELEGRAM_BOT_TOKEN` in `config.php`
4. Set the site's **Telegram Chat ID** field to your chat ID

## Dashboard Auth

Set `DASHBOARD_AUTH = true` in `config.php` and update `DASHBOARD_USER` / `DASHBOARD_PASS`.

## Check Types

| Type    | What it checks                                      |
|---------|-----------------------------------------------------|
| http    | HTTP status code (default 200)                      |
| ssl     | SSL certificate expiry (warns at 30d, alerts at 7d) |
| port    | TCP port reachability (fsockopen)                   |
| dns     | DNS A/AAAA/MX records; optional IP match via keyword|
| keyword | Page content contains specified keyword             |

## File Structure

```
monitor/
├── index.php           Dashboard
├── site_details.php    Per-site analytics
├── api.php             REST API (AJAX)
├── cron_runner.php     Monitoring engine (cron only)
├── config.php          Configuration
├── install.php         DB installer
├── composer.json
├── .htaccess
├── assets/
│   ├── css/dashboard.css
│   └── js/dashboard.js
└── includes/
    ├── Database.php    PDO wrapper
    ├── Checker.php     Check logic
    ├── Alert.php       Email/SMS/Telegram alerts
    └── Statistics.php  Aggregation queries
```

## Performance Notes

- Designed for 100+ sites with 1-minute checks
- Logs are retained for 90 days (configurable via `LOG_RETENTION_DAYS`)
- Hourly and daily stats are pre-aggregated to keep chart queries fast
- All date columns are indexed
- DataTables handles large site lists client-side with search/sort/pagination
