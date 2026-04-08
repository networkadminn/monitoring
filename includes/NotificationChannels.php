<?php
// =============================================================================
// includes/NotificationChannels.php - Slack, Discord, Webhook, PagerDuty alerts
// =============================================================================

class NotificationChannels {

    // -------------------------------------------------------------------------
    // Slack
    // -------------------------------------------------------------------------
    public static function sendSlack(string $webhookUrl, array $site, array $result, string $event): void {
        if (empty($webhookUrl)) return;

        $emoji   = $event === 'recovery' ? '✅' : ($event === 'ssl_expiry' ? '⚠️' : '🔴');
        $color   = $event === 'recovery' ? '#10b981' : ($event === 'ssl_expiry' ? '#f59e0b' : '#ef4444');
        $status  = $event === 'recovery' ? 'RECOVERED' : ($event === 'ssl_expiry' ? 'SSL EXPIRING' : 'DOWN');
        $text    = $result['error_message'] ?? ($event === 'ssl_expiry' ? "Expires in {$result['ssl_expiry_days']} days" : 'Site is back online');

        $payload = [
            'attachments' => [[
                'color'  => $color,
                'blocks' => [
                    ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "$emoji {$site['name']} — $status"]],
                    ['type' => 'section', 'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*URL:*\n<{$site['url']}|{$site['url']}>"],
                        ['type' => 'mrkdwn', 'text' => "*Response Time:*\n" . ($result['response_time'] ?? 'N/A') . 'ms'],
                        ['type' => 'mrkdwn', 'text' => "*Check Type:*\n" . strtoupper($site['check_type'] ?? 'http')],
                        ['type' => 'mrkdwn', 'text' => "*Time:*\n" . date('Y-m-d H:i:s T')],
                    ]],
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*Details:* $text"]],
                    ['type' => 'actions', 'elements' => [[
                        'type' => 'button', 'text' => ['type' => 'plain_text', 'text' => 'View Monitor'],
                        'url'  => APP_URL . '/site_details.php?id=' . (int)$site['id'],
                        'style' => $event === 'recovery' ? 'primary' : 'danger',
                    ]]],
                ],
            ]],
        ];

        self::postJson($webhookUrl, $payload, 'Slack');
    }

    // -------------------------------------------------------------------------
    // Discord
    // -------------------------------------------------------------------------
    public static function sendDiscord(string $webhookUrl, array $site, array $result, string $event): void {
        if (empty($webhookUrl)) return;

        $color   = $event === 'recovery' ? 1087891 : ($event === 'ssl_expiry' ? 16497928 : 15548997);
        $status  = $event === 'recovery' ? '✅ RECOVERED' : ($event === 'ssl_expiry' ? '⚠️ SSL EXPIRING' : '🔴 DOWN');
        $text    = $result['error_message'] ?? ($event === 'ssl_expiry' ? "Expires in {$result['ssl_expiry_days']} days" : 'Site is back online');

        $payload = [
            'embeds' => [[
                'title'       => "{$status} — {$site['name']}",
                'url'         => APP_URL . '/site_details.php?id=' . (int)$site['id'],
                'color'       => $color,
                'description' => $text,
                'fields'      => [
                    ['name' => 'URL',           'value' => $site['url'],                              'inline' => true],
                    ['name' => 'Response Time', 'value' => ($result['response_time'] ?? 'N/A') . 'ms','inline' => true],
                    ['name' => 'Check Type',    'value' => strtoupper($site['check_type'] ?? 'http'), 'inline' => true],
                ],
                'timestamp'   => date('c'),
                'footer'      => ['text' => 'Site Monitor'],
            ]],
        ];

        self::postJson($webhookUrl, $payload, 'Discord');
    }

    // -------------------------------------------------------------------------
    // Generic Webhook (POST JSON)
    // -------------------------------------------------------------------------
    public static function sendWebhook(string $webhookUrl, array $site, array $result, string $event): void {
        if (empty($webhookUrl)) return;

        $payload = [
            'event'         => $event,
            'site_id'       => $site['id'],
            'site_name'     => $site['name'],
            'url'           => $site['url'],
            'check_type'    => $site['check_type'],
            'status'        => $result['status'],
            'response_time' => $result['response_time'],
            'error_message' => $result['error_message'],
            'ssl_expiry_days' => $result['ssl_expiry_days'],
            'timestamp'     => date('c'),
            'dashboard_url' => APP_URL . '/site_details.php?id=' . (int)$site['id'],
        ];

        self::postJson($webhookUrl, $payload, 'Webhook');
    }

    // -------------------------------------------------------------------------
    // PagerDuty Events API v2
    // -------------------------------------------------------------------------
    public static function sendPagerDuty(string $integrationKey, array $site, array $result, string $event): void {
        if (empty($integrationKey)) return;

        $action  = $event === 'recovery' ? 'resolve' : 'trigger';
        $dedupKey = 'sitemonitor-' . $site['id'];

        $payload = [
            'routing_key'  => $integrationKey,
            'event_action' => $action,
            'dedup_key'    => $dedupKey,
            'payload'      => [
                'summary'   => "{$site['name']} is " . ($event === 'recovery' ? 'back online' : 'DOWN'),
                'source'    => $site['url'],
                'severity'  => $event === 'recovery' ? 'info' : 'critical',
                'timestamp' => date('c'),
                'custom_details' => [
                    'response_time'   => $result['response_time'],
                    'error_message'   => $result['error_message'],
                    'check_type'      => $site['check_type'],
                    'ssl_expiry_days' => $result['ssl_expiry_days'],
                ],
            ],
            'links' => [[
                'href' => APP_URL . '/site_details.php?id=' . (int)$site['id'],
                'text' => 'View Monitor',
            ]],
        ];

        self::postJson('https://events.pagerduty.com/v2/enqueue', $payload, 'PagerDuty');
    }

    // -------------------------------------------------------------------------
    // Opsgenie
    // -------------------------------------------------------------------------
    public static function sendOpsgenie(string $apiKey, array $site, array $result, string $event): void {
        if (empty($apiKey)) return;

        if ($event === 'recovery') {
            // Close the alert
            $alias = 'sitemonitor-' . $site['id'];
            $ch = curl_init("https://api.opsgenie.com/v2/alerts/$alias/close?identifierType=alias");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ["Authorization: GenieKey $apiKey", 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode(['note' => 'Site recovered']),
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_exec($ch);
            curl_close($ch);
            return;
        }

        $payload = [
            'message'     => "{$site['name']} is DOWN",
            'alias'       => 'sitemonitor-' . $site['id'],
            'description' => $result['error_message'] ?? 'Site unreachable',
            'priority'    => 'P1',
            'source'      => $site['url'],
            'details'     => [
                'response_time' => (string)($result['response_time'] ?? 0),
                'check_type'    => $site['check_type'],
            ],
        ];

        $ch = curl_init('https://api.opsgenie.com/v2/alerts');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ["Authorization: GenieKey $apiKey", 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        if (curl_errno($ch)) error_log('[Opsgenie] ' . curl_error($ch));
        curl_close($ch);
    }

    // -------------------------------------------------------------------------
    // Internal HTTP POST helper
    // -------------------------------------------------------------------------
    private static function postJson(string $url, array $payload, string $channel): void {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("[$channel] Send failed: $err");
        } elseif ($code >= 400) {
            error_log("[$channel] HTTP $code: $resp");
        }
    }
}
