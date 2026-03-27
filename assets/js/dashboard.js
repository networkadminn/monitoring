// =============================================================================
// dashboard.js - Dashboard interactivity, charts, DataTables
// =============================================================================

const API = 'api.php';

// Chart.js global defaults
Chart.defaults.color = '#7a87a8';
Chart.defaults.borderColor = 'rgba(255,255,255,0.05)';
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
Chart.defaults.font.size = 12;

// ── State ─────────────────────────────────────────────────────────────────
const charts = {};
let sitesData = [];
let sitesTable = null; // DataTable instance
let refreshTimer = null;

// ── Init ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadDashboard();
  refreshTimer = setInterval(loadDashboard, 60000);

  // Topbar buttons
  document.getElementById('btn-add-site')?.addEventListener('click', () => openSiteModal());
  document.getElementById('btn-refresh')?.addEventListener('click', loadDashboard);
  document.getElementById('btn-bulk-delete')?.addEventListener('click', bulkDeleteSites);

  // Modal save/cancel
  document.getElementById('modal-save')?.addEventListener('click', saveSite);
  document.getElementById('modal-cancel')?.addEventListener('click', closeModal);
  document.getElementById('modal-close')?.addEventListener('click', closeModal);
  document.getElementById('modal-overlay')?.addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeModal();
  });

  // Confirm modal
  document.getElementById('confirm-cancel')?.addEventListener('click', closeConfirm);
  document.getElementById('confirm-overlay')?.addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeConfirm();
  });

  // Tabs in modal
  document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
      const pane = tab.dataset.tab;
      document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
      document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
      tab.classList.add('active');
      document.getElementById('tab-' + pane)?.classList.add('active');
    });
  });
});

// ── Main loader ───────────────────────────────────────────────────────────
async function loadDashboard() {
  try {
    const [health, sites, incidents, ssl] = await Promise.all([
      apiFetch('health'),
      apiFetch('sites'),
      apiFetch('incidents'),
      apiFetch('ssl_expiry'),
    ]);

    sitesData = sites;
    renderHealthCards(health);
    renderSitesTable(sites);
    renderIncidentsTable(incidents);
    renderSSLChart(ssl);
    renderUptimeChart(sites);
    renderResponseTrendChart(sites);
    renderHistogramChart(sites);
    renderGauge(health.health_score);
    updateLastUpdated();
  } catch (err) {
    showToast('Failed to load dashboard: ' + err.message, 'error');
  }
}

// ── Health cards ──────────────────────────────────────────────────────────
function renderHealthCards(h) {
  setText('card-total',  h.total_sites);
  setText('card-up',     h.sites_up);
  setText('card-down',   h.sites_down);
  setText('card-avgrt',  h.avg_response + ' ms');
  setText('card-health', h.health_score + '%');
  setText('gauge-score', h.health_score + '%');

  // Status bar
  const dot = document.getElementById('sb-dot');
  const status = document.getElementById('sb-status');
  const total = document.getElementById('sb-total');
  if (dot) dot.className = 'status-dot ' + (h.sites_down > 0 ? 'red' : 'green');
  if (status) status.textContent = h.sites_down > 0 ? h.sites_down + ' site(s) down' : 'All systems operational';
  if (total) total.textContent = h.total_sites + ' monitors active';

  const downCard = document.getElementById('card-down-wrap');
  if (downCard) downCard.className = 'card ' + (h.sites_down > 0 ? 'red' : 'green');
}

// ── Sites table ───────────────────────────────────────────────────────────
function renderSitesTable(sites) {
  // Destroy DataTable FIRST before touching the DOM
  if (sitesTable) {
    sitesTable.destroy();
    sitesTable = null;
  }

  const tbody = document.getElementById('sites-tbody');
  if (!tbody) return;

  if (!sites.length) {
    tbody.innerHTML = `<tr><td colspan="8" class="text-muted" style="text-align:center;padding:40px">
      No monitors configured yet. Click "Add Monitor" to get started.
    </td></tr>`;
    return;
  }

  tbody.innerHTML = sites.map(s => {
    const uptime   = parseFloat(s.uptime_percentage) || 0;
    const barColor = uptime >= 99 ? 'green' : uptime >= 95 ? 'yellow' : 'red';
    const rt       = s.response_time ? Math.round(s.response_time) + ' ms' : '—';
    const checked  = s.last_checked ? timeAgo(s.last_checked) : 'Never';
    const status   = s.status || 'unknown';
    const domain   = (() => { try { return new URL(s.url).hostname; } catch(e) { return s.url; } })();

    // 30 uptime blocks (simplified — colour by overall uptime)
    const blocks = Array.from({length: 30}, (_, i) => {
      const cls = uptime >= 99 ? 'up' : uptime >= 90 ? 'partial' : uptime >= 50 ? 'partial' : 'down';
      return `<div class="uptime-block ${i < Math.round(uptime * 30 / 100) ? cls : 'empty'}" title="Day ${i+1}"></div>`;
    }).join('');

    return `<tr>
      <td><input type="checkbox" class="site-checkbox" data-id="${s.id}" data-name="${esc(s.name)}" style="cursor:pointer"></td>
      <td>
        <div class="site-name-cell">
          <div class="site-favicon">
            <img src="https://www.google.com/s2/favicons?domain=${encodeURIComponent(domain)}&sz=32"
                 onerror="this.style.display='none'" alt="">
          </div>
          <div>
            <a href="site_details.php?id=${s.id}" class="site-name-link">${esc(s.name)}</a>
            <div class="site-url-small truncate">${esc(s.url)}</div>
          </div>
        </div>
      </td>
      <td><span class="badge ${status}"><span class="badge-dot"></span>${status}</span></td>
      <td style="font-weight:500">${rt}</td>
      <td>
        <div class="uptime-cell">
          <div class="progress"><div class="progress-bar ${barColor}" style="width:${uptime}%"></div></div>
          <span class="uptime-pct" style="color:var(--${barColor})">${uptime}%</span>
        </div>
      </td>
      <td><div class="uptime-blocks">${blocks}</div></td>
      <td class="text-muted" style="font-size:12px">${checked}</td>
      <td>
        <div style="display:flex;gap:6px">
          <button class="btn btn-ghost btn-sm" onclick="openSiteModal(${s.id})" title="Edit">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit
          </button>
          <button class="btn btn-danger btn-sm" onclick="confirmDeleteSite(${s.id}, '${esc(s.name)}')" title="Delete">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
          </button>
        </div>
      </td>
    </tr>`;
  }).join('');

  // Init DataTable fresh
  if (window.jQuery && $.fn.DataTable) {
    sitesTable = $('#sites-table').DataTable({
      pageLength: 25,
      order: [[2, 'asc']], // sort by status
      columnDefs: [{ orderable: false, targets: [0, 5, 7] }],
      language: { search: 'Filter:', lengthMenu: 'Show _MENU_ monitors' },
    });
  }

  bindCheckboxEvents();

  const countEl = document.getElementById('sites-count');
  if (countEl) countEl.textContent = sites.length + ' monitor' + (sites.length !== 1 ? 's' : '');
}

// ── Checkbox / bulk-delete wiring ─────────────────────────────────────────
function bindCheckboxEvents() {
  const selectAll = document.getElementById('select-all-sites');
  const bulkBtn   = document.getElementById('btn-bulk-delete');
  const bulkCount = document.getElementById('bulk-count');

  function updateBulkBtn() {
    const n = document.querySelectorAll('.site-checkbox:checked').length;
    if (bulkBtn)   bulkBtn.style.display = n > 0 ? '' : 'none';
    if (bulkCount) bulkCount.textContent = n;
  }

  if (selectAll) {
    selectAll.checked = false;
    // Clone to remove old listeners
    const fresh = selectAll.cloneNode(true);
    selectAll.parentNode.replaceChild(fresh, selectAll);
    fresh.addEventListener('change', () => {
      document.querySelectorAll('.site-checkbox').forEach(cb => cb.checked = fresh.checked);
      updateBulkBtn();
    });
  }

  document.querySelectorAll('.site-checkbox').forEach(cb => {
    cb.addEventListener('change', () => {
      const all  = document.querySelectorAll('.site-checkbox').length;
      const done = document.querySelectorAll('.site-checkbox:checked').length;
      const sa   = document.getElementById('select-all-sites');
      if (sa) sa.checked = all === done && all > 0;
      updateBulkBtn();
    });
  });
}

// ── Incidents table ───────────────────────────────────────────────────────
function renderIncidentsTable(incidents) {
  const tbody = document.getElementById('incidents-tbody');
  if (!tbody) return;

  tbody.innerHTML = incidents.map(i => {
    const dur = i.duration_seconds ? formatDuration(i.duration_seconds) : '<span class="text-red">Ongoing</span>';
    return `<tr>
      <td>${esc(i.site_name || '')}</td>
      <td>${formatDate(i.started_at)}</td>
      <td>${i.ended_at ? formatDate(i.ended_at) : '—'}</td>
      <td>${dur}</td>
      <td class="text-muted">${esc(i.error_message || '')}</td>
    </tr>`;
  }).join('');
}

// ── Response time trend (multi-site line chart) ───────────────────────────
async function renderResponseTrendChart(sites) {
  const ids = sites.slice(0, 8).map(s => s.id).join(',');
  if (!ids) return;

  const data = await apiFetch(`response_trend&ids=${ids}`);
  const ctx  = document.getElementById('chart-response-trend');
  if (!ctx) return;

  // Build unified label set
  const allHours = new Set();
  Object.values(data).forEach(rows => rows.forEach(r => allHours.add(r.hour)));
  const labels = [...allHours].sort();

  const colors = ['#3b82f6','#22c55e','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#f97316','#ec4899'];
  const datasets = sites.slice(0, 8).map((site, i) => {
    const rows = data[site.id] || [];
    const map  = Object.fromEntries(rows.map(r => [r.hour, r.avg_rt]));
    return {
      label: site.name,
      data: labels.map(h => map[h] ?? null),
      borderColor: colors[i],
      backgroundColor: colors[i] + '22',
      tension: 0.4,
      fill: false,
      spanGaps: true,
      pointRadius: 2,
    };
  });

  destroyChart('response-trend');
  charts['response-trend'] = new Chart(ctx, {
    type: 'line',
    data: { labels: labels.map(h => h.slice(11, 16)), datasets },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'bottom' } },
      scales: {
        y: { title: { display: true, text: 'Response Time (ms)' }, beginAtZero: true },
        x: { ticks: { maxTicksLimit: 12 } },
      },
    },
  });
}

// ── SSL expiry bar chart ──────────────────────────────────────────────────
function renderSSLChart(sslData) {
  const ctx = document.getElementById('chart-ssl');
  if (!ctx || !sslData.length) return;

  const labels = sslData.map(s => s.name);
  const values = sslData.map(s => s.ssl_expiry_days ?? 0);
  const colors = values.map(d => d <= 7 ? '#ef4444' : d <= 30 ? '#f59e0b' : '#22c55e');

  destroyChart('ssl');
  charts['ssl'] = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Days Until Expiry',
        data: values,
        backgroundColor: colors,
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        y: { title: { display: true, text: 'Days Remaining' }, beginAtZero: true },
      },
    },
  });
}

// ── 30-day uptime area chart (first site or aggregate) ────────────────────
async function renderUptimeChart(sites) {
  if (!sites.length) return;
  const id  = sites[0].id;
  const data = await apiFetch(`uptime_chart&id=${id}`);
  const ctx  = document.getElementById('chart-uptime');
  if (!ctx) return;

  destroyChart('uptime');
  charts['uptime'] = new Chart(ctx, {
    type: 'line',
    data: {
      labels: data.map(d => d.date),
      datasets: [{
        label: 'Uptime %',
        data: data.map(d => d.uptime_percentage),
        borderColor: '#22c55e',
        backgroundColor: 'rgba(34,197,94,0.15)',
        fill: true,
        tension: 0.3,
        pointRadius: 3,
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        y: { min: 0, max: 100, title: { display: true, text: 'Uptime %' } },
      },
    },
  });
}

// ── Response time histogram ───────────────────────────────────────────────
async function renderHistogramChart(sites) {
  if (!sites.length) return;
  const id   = sites[0].id;
  const data = await apiFetch(`histogram&id=${id}`);
  const ctx  = document.getElementById('chart-histogram');
  if (!ctx) return;

  destroyChart('histogram');
  charts['histogram'] = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: Object.keys(data).map(k => k + ' ms'),
      datasets: [{
        label: 'Checks',
        data: Object.values(data),
        backgroundColor: '#3b82f6',
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        y: { title: { display: true, text: 'Count' }, beginAtZero: true },
        x: { title: { display: true, text: 'Response Time' } },
      },
    },
  });
}

// ── Health gauge (doughnut) ───────────────────────────────────────────────
function renderGauge(score) {
  const ctx = document.getElementById('chart-gauge');
  if (!ctx) return;

  const color = score >= 95 ? '#22c55e' : score >= 80 ? '#f59e0b' : '#ef4444';
  setText('gauge-score', score + '%');

  destroyChart('gauge');
  charts['gauge'] = new Chart(ctx, {
    type: 'doughnut',
    data: {
      datasets: [{
        data: [score, 100 - score],
        backgroundColor: [color, 'rgba(255,255,255,0.05)'],
        borderWidth: 0,
        circumference: 270,
        rotation: 225,
      }],
    },
    options: {
      responsive: true,
      cutout: '78%',
      plugins: { legend: { display: false }, tooltip: { enabled: false } },
    },
  });
}

// ── Site modal ────────────────────────────────────────────────────────────
async function openSiteModal(id = null) {
  const modal = document.getElementById('modal-overlay');
  const title = document.getElementById('modal-title');

  // Reset form + errors
  document.getElementById('site-form').reset();
  document.getElementById('site-id').value = '';
  document.querySelectorAll('.form-error').forEach(el => el.textContent = '');
  document.querySelectorAll('.form-control').forEach(el => el.classList.remove('error'));

  // Reset to first tab
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelector('.tab[data-tab="basic"]')?.classList.add('active');
  document.getElementById('tab-basic')?.classList.add('active');

  if (id) {
    title.textContent = 'Edit Monitor';
    document.getElementById('modal-save').textContent = 'Save Changes';
    try {
      const detail = await apiFetch(`site_detail&id=${id}`);
      const s = detail.site;
      document.getElementById('site-id').value         = s.id;
      document.getElementById('site-name').value       = s.name;
      document.getElementById('site-url').value        = s.url;
      document.getElementById('site-check-type').value = s.check_type;
      document.getElementById('site-port').value       = s.port || '';
      document.getElementById('site-hostname').value   = s.hostname || '';
      document.getElementById('site-keyword').value    = s.keyword || '';
      document.getElementById('site-expected').value   = s.expected_status || 200;
      document.getElementById('site-email').value      = s.alert_email || '';
      document.getElementById('site-active').checked   = s.is_active == 1;
    } catch (err) {
      showToast('Failed to load site: ' + err.message, 'error');
      return;
    }
  } else {
    title.textContent = 'Add Monitor';
    document.getElementById('modal-save').innerHTML =
      '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Save Monitor';
  }

  modal.classList.add('open');
}

function closeModal() {
  document.getElementById('modal-overlay')?.classList.remove('open');
}

async function saveSite() {
  // Validate
  const name = document.getElementById('site-name').value.trim();
  const url  = document.getElementById('site-url').value.trim();
  let valid  = true;

  document.querySelectorAll('.form-error').forEach(el => el.textContent = '');
  document.querySelectorAll('.form-control').forEach(el => el.classList.remove('error'));

  if (!name) {
    document.getElementById('err-name').textContent = 'Name is required';
    document.getElementById('site-name').classList.add('error');
    valid = false;
  }
  if (!url) {
    document.getElementById('err-url').textContent = 'URL is required';
    document.getElementById('site-url').classList.add('error');
    valid = false;
  } else if (!/^https?:\/\/.+/.test(url)) {
    document.getElementById('err-url').textContent = 'Must start with http:// or https://';
    document.getElementById('site-url').classList.add('error');
    // Switch to basic tab to show error
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelector('.tab[data-tab="basic"]')?.classList.add('active');
    document.getElementById('tab-basic')?.classList.add('active');
    valid = false;
  }

  if (!valid) return;

  const saveBtn = document.getElementById('modal-save');
  saveBtn.disabled = true;
  saveBtn.innerHTML = '<span class="spinner"></span> Saving…';

  const data = {
    id:              document.getElementById('site-id').value || null,
    name,
    url,
    check_type:      document.getElementById('site-check-type').value,
    port:            document.getElementById('site-port').value,
    hostname:        document.getElementById('site-hostname').value,
    keyword:         document.getElementById('site-keyword').value,
    expected_status: document.getElementById('site-expected').value || 200,
    alert_email:     document.getElementById('site-email').value,
    is_active:       document.getElementById('site-active').checked ? 1 : 0,
  };

  try {
    await apiPost('save_site', data);
    closeModal();
    showToast(data.id ? 'Monitor updated' : 'Monitor added', 'success');
    loadDashboard();
  } catch (err) {
    showToast('Save failed: ' + err.message, 'error');
  } finally {
    saveBtn.disabled = false;
    saveBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Save Monitor';
  }
}

// ── Confirm modal ─────────────────────────────────────────────────────────
function showConfirm(title, msg, sites, onConfirm) {
  document.getElementById('confirm-title').textContent = title;
  document.getElementById('confirm-msg').textContent   = msg;
  const list = document.getElementById('confirm-sites');
  if (sites && sites.length) {
    list.innerHTML = sites.map(n => `<li>${esc(n)}</li>`).join('');
    list.style.display = '';
  } else {
    list.style.display = 'none';
  }
  document.getElementById('confirm-overlay').classList.add('open');
  const btn = document.getElementById('confirm-ok');
  const fresh = btn.cloneNode(true);
  btn.parentNode.replaceChild(fresh, btn);
  fresh.addEventListener('click', async () => { closeConfirm(); await onConfirm(); });
}

function closeConfirm() {
  document.getElementById('confirm-overlay')?.classList.remove('open');
}

function confirmDeleteSite(id, name) {
  showConfirm('Delete Monitor', 'Are you sure? All logs and history will be permanently removed.', [name], async () => {
    try {
      await apiPost(`delete_site&id=${id}`, {});
      showToast('Monitor deleted', 'success');
      loadDashboard();
    } catch (err) {
      showToast('Delete failed: ' + err.message, 'error');
    }
  });
}

function bulkDeleteSites() {
  const checked = [...document.querySelectorAll('.site-checkbox:checked')];
  if (!checked.length) return;
  const ids   = checked.map(cb => parseInt(cb.dataset.id));
  const names = checked.map(cb => cb.dataset.name);
  showConfirm(`Delete ${ids.length} Monitor${ids.length > 1 ? 's' : ''}`,
    'This will permanently delete the selected monitors and all their history.', names, async () => {
    try {
      const result = await apiPost('bulk_delete_sites', { ids });
      showToast(`${result.deleted} monitor(s) deleted`, 'success');
      loadDashboard();
    } catch (err) {
      showToast('Bulk delete failed: ' + err.message, 'error');
    }
  });
}

// ── API helpers ───────────────────────────────────────────────────────────
async function apiFetch(action) {
  const res  = await fetch(`${API}?action=${action}`);
  const json = await res.json();
  if (!json.success) throw new Error(json.error || 'API error');
  return json.data;
}

async function apiPost(action, body) {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const res  = await fetch(`${API}?action=${action}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
    body: JSON.stringify(body),
  });
  const json = await res.json();
  if (!json.success) throw new Error(json.error || 'API error');
  return json.data;
}

// ── Utilities ─────────────────────────────────────────────────────────────
function destroyChart(key) {
  if (charts[key]) { charts[key].destroy(); delete charts[key]; }
}

function setText(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}

function esc(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function timeAgo(dateStr) {
  const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
  if (diff < 60)   return diff + 's ago';
  if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
  return Math.floor(diff / 86400) + 'd ago';
}

function formatDate(str) {
  return str ? new Date(str).toLocaleString() : '—';
}

function formatDuration(secs) {
  if (secs < 60)   return secs + 's';
  if (secs < 3600) return Math.floor(secs / 60) + 'm ' + (secs % 60) + 's';
  return Math.floor(secs / 3600) + 'h ' + Math.floor((secs % 3600) / 60) + 'm';
}

function updateLastUpdated() {
  const el = document.getElementById('last-updated');
  if (el) el.textContent = 'Updated ' + new Date().toLocaleTimeString();
}

function showToast(msg, type = 'success') {
  const container = document.getElementById('toast-container');
  if (!container) return;
  const icons = { success: '✓', error: '✕', warning: '⚠', info: 'ℹ' };
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.innerHTML = `<div class="toast-icon">${icons[type] || '•'}</div><div class="toast-body"><div class="toast-title">${msg}</div></div>`;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 4000);
}

// ── CSV export (site_details page) ───────────────────────────────────────
async function exportCSV(siteId) {
  const logs = await apiFetch(`export_logs&id=${siteId}`);
  if (!logs.length) { showToast('No logs to export', 'error'); return; }

  const headers = Object.keys(logs[0]);
  const rows    = logs.map(r => headers.map(h => JSON.stringify(r[h] ?? '')).join(','));
  const csv     = [headers.join(','), ...rows].join('\n');
  const blob    = new Blob([csv], { type: 'text/csv' });
  const url     = URL.createObjectURL(blob);
  const a       = document.createElement('a');
  a.href = url; a.download = `site_${siteId}_logs.csv`; a.click();
  URL.revokeObjectURL(url);
}
