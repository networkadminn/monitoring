<!-- Add/Edit Site Modal -->
<div class="modal-overlay" id="modal-overlay">
  <div class="modal">
    <div class="modal-header">
      <h3 id="modal-title">Add Monitor</h3>
      <button class="modal-close" id="modal-close">&times;</button>
    </div>
    <div class="modal-body">
      <form id="site-form" novalidate>
        <input type="hidden" id="site-id">

        <div class="tabs">
          <div class="tab active" data-tab="basic">Basic</div>
          <div class="tab" data-tab="advanced">Advanced</div>
          <div class="tab" data-tab="alerts">Alerts</div>
          <div class="tab" data-tab="integrations">Integrations</div>
        </div>

        <!-- Basic tab -->
        <div class="tab-pane active" id="tab-basic">
          <div class="form-group">
            <label>Monitor Name *</label>
            <input type="text" id="site-name" class="form-control" placeholder="My Website" autocomplete="off">
            <div class="form-error" id="err-name"></div>
          </div>
          <div class="form-group">
            <label>URL *</label>
            <input type="url" id="site-url" class="form-control" placeholder="https://example.com" autocomplete="off">
            <div class="form-error" id="err-url"></div>
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label>Check Type</label>
              <select id="site-check-type" class="form-control">
                <option value="http">HTTP / HTTPS</option>
                <option value="ssl">SSL Certificate</option>
                <option value="port">Port Check</option>
                <option value="dns">DNS Lookup</option>
                <option value="keyword">Keyword Match</option>
              </select>
            </div>
            <div class="form-group">
              <label>Check Interval</label>
              <select id="site-interval" class="form-control">
                <option value="1">Every 1 minute</option>
                <option value="5">Every 5 minutes</option>
                <option value="10">Every 10 minutes</option>
                <option value="15">Every 15 minutes</option>
                <option value="30">Every 30 minutes</option>
                <option value="60">Every 60 minutes</option>
              </select>
            </div>
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:10px">
            <input type="checkbox" id="site-active" checked style="width:15px;height:15px;cursor:pointer">
            <label for="site-active" style="margin:0;cursor:pointer">Active (enable monitoring)</label>
          </div>
          <div class="form-group">
            <label>Tags (comma separated)</label>
            <input type="text" id="site-tags" class="form-control" placeholder="Production, API, Critical">
          </div>
        </div>

        <!-- Advanced tab -->
        <div class="tab-pane" id="tab-advanced">
          <div class="form-grid">
            <div class="form-group">
              <label>Port</label>
              <input type="number" id="site-port" class="form-control" placeholder="443">
            </div>
            <div class="form-group">
              <label>Expected HTTP Status</label>
              <input type="number" id="site-expected" class="form-control" value="200">
            </div>
          </div>
          <div class="form-group">
            <label>Hostname (for port/DNS checks)</label>
            <input type="text" id="site-hostname" class="form-control" placeholder="mail.example.com">
          </div>
          <div class="form-group">
            <label>Keyword (for keyword checks)</label>
            <input type="text" id="site-keyword" class="form-control" placeholder="Welcome to our site">
          </div>
          <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)">
            <label style="font-weight:600;display:block;margin-bottom:12px">Smart Alerting Thresholds</label>
            <p style="font-size:12px;color:var(--muted);margin-bottom:12px">Require multiple consecutive failures/recoveries before alerting to reduce false positives.</p>
            <div class="form-grid">
              <div class="form-group">
                <label>Failures Before Alert</label>
                <input type="number" id="site-failure-threshold" class="form-control" value="3" min="1" max="10">
                <div style="font-size:11px;color:var(--muted);margin-top:4px">Default: 3 checks</div>
              </div>
              <div class="form-group">
                <label>Recoveries Before Alert</label>
                <input type="number" id="site-recovery-threshold" class="form-control" value="3" min="1" max="10">
                <div style="font-size:11px;color:var(--muted);margin-top:4px">Default: 3 checks</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Alerts tab -->
        <div class="tab-pane" id="tab-alerts">
          <div class="form-group">
            <label>Alert Email</label>
            <input type="email" id="site-email" class="form-control" placeholder="ops@example.com">
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Separate multiple emails with commas</div>
          </div>
          <div class="form-group" style="margin-top:16px">
            <label>Microsoft Teams Webhook</label>
            <input type="url" id="site-teams" class="form-control" placeholder="https://outlook.webhook.office.com/webhookb2/...">
          </div>
          <p style="font-size:12px;color:var(--muted);margin-top:16px">
            Alerts fire after the failure threshold is reached. Cooldown: <?= defined('ALERT_COOLDOWN') ? ALERT_COOLDOWN / 60 : 60 ?> minutes between repeat alerts.
          </p>
        </div>

        <!-- Integrations tab -->
        <div class="tab-pane" id="tab-integrations">
          <div class="form-group">
            <label>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px"><path d="M22.46 6c-.77.35-1.6.58-2.46.69.88-.53 1.56-1.37 1.88-2.38-.83.5-1.75.85-2.72 1.05C18.37 4.5 17.26 4 16 4c-2.35 0-4.27 1.92-4.27 4.29 0 .34.04.67.11.98C8.28 9.09 5.11 7.38 3 4.79c-.37.63-.58 1.37-.58 2.15 0 1.49.75 2.81 1.91 3.56-.71 0-1.37-.2-1.95-.5v.03c0 2.08 1.48 3.82 3.44 4.21a4.22 4.22 0 0 1-1.93.07 4.28 4.28 0 0 0 4 2.98 8.521 8.521 0 0 1-5.33 1.84c-.34 0-.68-.02-1.02-.06C3.44 20.29 5.7 21 8.12 21 16 21 20.33 14.46 20.33 8.79c0-.19 0-.37-.01-.56.84-.6 1.56-1.36 2.14-2.23z"/></svg>
              Slack Webhook URL
            </label>
            <input type="url" id="site-slack" class="form-control" placeholder="https://hooks.slack.com/services/...">
            <div style="font-size:11px;color:var(--muted);margin-top:4px">
              <a href="https://api.slack.com/messaging/webhooks" target="_blank" style="color:var(--blue)">How to create a Slack webhook →</a>
            </div>
          </div>
          <div class="form-group">
            <label>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:4px;color:#5865F2"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057c.002.022.015.043.03.056a19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg>
              Discord Webhook URL
            </label>
            <input type="url" id="site-discord" class="form-control" placeholder="https://discord.com/api/webhooks/...">
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Server Settings → Integrations → Webhooks</div>
          </div>
          <div class="form-group">
            <label>Custom Webhook URL (POST JSON)</label>
            <input type="url" id="site-webhook" class="form-control" placeholder="https://your-server.com/webhook">
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Receives a JSON payload on every status change</div>
          </div>
          <div class="form-group">
            <label>PagerDuty Integration Key</label>
            <input type="text" id="site-pagerduty" class="form-control" placeholder="abc123def456...">
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Events API v2 integration key from your PagerDuty service</div>
          </div>
        </div>

        <!-- Test result -->
        <div id="test-result" style="margin-top:16px;padding:12px;border-radius:6px;display:none;font-size:13px;line-height:1.4"></div>

      </form>
    </div>
    <div class="modal-footer">
      <div style="flex-grow:1;display:flex;gap:8px">
        <button type="button" class="btn btn-ghost" id="modal-test">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          Test Connection
        </button>
      </div>
      <button type="button" class="btn btn-ghost" id="modal-cancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="modal-save">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        Save Monitor
      </button>
    </div>
  </div>
</div>

<!-- Confirm Delete Modal -->
<div class="modal-overlay" id="confirm-overlay">
  <div class="modal confirm-modal">
    <div class="modal-body" style="padding:28px 24px 20px">
      <div class="confirm-icon danger">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
      </div>
      <div class="confirm-title" id="confirm-title">Delete Monitor</div>
      <div class="confirm-msg" id="confirm-msg">This action cannot be undone.</div>
      <ul class="confirm-sites" id="confirm-sites" style="display:none"></ul>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="confirm-cancel">Cancel</button>
      <button class="btn btn-danger" id="confirm-ok">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
        Delete
      </button>
    </div>
  </div>
</div>
