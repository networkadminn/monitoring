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
            <p style="font-size:12px;color:var(--muted);margin-bottom:12px">
              Require multiple consecutive failures/recoveries before alerting to reduce false positives.
            </p>
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
          </div>
          <div class="form-group" style="margin-top:16px">
            <label>Microsoft Teams Webhook</label>
            <input type="url" id="site-teams" class="form-control" placeholder="https://outlook.webhook.office.com/webhookb2/...">
            <div style="font-size:11px;color:var(--muted);margin-top:4px">
              Get your webhook URL from Teams → Configure Connector → Incoming Webhook
            </div>
          </div>
          <p style="font-size:12px;color:var(--muted);margin-top:16px">
            Alerts are sent when a site goes down and when it recovers. A cooldown of <?= ALERT_COOLDOWN / 60 ?> minutes applies between repeat alerts for the same event.
          </p>
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