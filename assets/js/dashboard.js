// =============================================================================
// dashboard.js - Dashboard interactivity, charts, DataTables
// =============================================================================

const API = 'api.php';
const CHART_DEFAULTS = {
  color: '#3b82f6',
  gridColor: 'rgba(255,255,255,0.05)',
  textColor: '#8892a4',
};

// Chart.js global defaults
Chart.defaults.color = CHART_DEFAULTS.textColor;
Chart.defaults.borderColor = CHART_DEFAULTS.gridColor;
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
Chart.defaults.font.size = 12;

// ── State ─────────────────────────────────────────────────────────────────
const charts = {};
let sitesData = [];
let refreshTimer = null;

// ── Init ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadDashboard();
  refreshTimer = setInterval(loadDashboard, 60000); // auto-refresh every 60s

  document.getElementById('btn-add-site')?.addEventListener('click', () => openSiteModal());
  document.getElementById('btn-refresh')?.addEventListener('click', loadDashboard);
  document.getElementById('site-form')?.addEventListener('submit', saveSite);
  document.getElementById('modal-close')?.addEventListener('click', closeModal);
  document.getElementById('modal-overlay')?.addEventListener('click', (e) => {
    if (e.target.id === 'modal-overlay') closeModal();
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
  setText('card-total',   h.total_sites);
  setText('card-down',    h.sites_down);
  setText('card-avgrt',   h.avg_response + ' ms');
  setText('card-ssl',     h.ssl_warnings);
  setText('card-health',  h.health_score + '%');

  const downCard = document.getElementById('card-down-wrap');
  if (downCard) downCard.className = 'card ' + (h.sites_down > 0 ? 'red' : 'green');
}

// ── Sites table ───────────────────────────────────────────────────────────
function renderSitesTable(sites) {
  const tbody = document.getElementById('sites-tbody');
  if (!tbody) return;

  tbody.innerHTML = sites.map(s => {
    const uptime  = parseFloat(s.uptime_percentage) || 0;
    const barColor = uptime >= 99 ? 'green' : uptime >= 95 ? 'yellow' : 'red';
    const rt      = s.response_time ? s.response_time + ' ms' : '—';
    const checked = s.last_checked ? timeAgo(s.last_checked) : 'Never';

    return `<tr>
      <td><a href="site_details.php?id=${s.id}" class="text-blue">${esc(s.name)}</a></td>
      <td class="truncate text-muted">${esc(s.url)}</td>
      <td><span class="badge ${s.status || 'warning'}">${s.status || 'unknown'}</span></td>
      <td>${rt}</td>
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <div class="progress"><div class="progress-bar ${barColor}" style="width:${uptime}%"></div></div>
          <span style="font-size:12px">${uptime}%</span>
        </div>
      </td>
      <td class="text-muted">${checked}</td>
      <td>
        <button class="btn btn-ghost btn-sm" onclick="openSiteModal(${s.id})">Edit</button>
        <button class="btn btn-danger btn-sm" onclick="deleteSite(${s.id}, '${esc(s.name)}')">Del</button>
      </td>
    </tr>`;
  }).join('');

  // Init or reload DataTable
  if (window.jQuery && $.fn.DataTable) {
    if ($.fn.DataTable.isDataTable('#sites-table')) {
      $('#sites-table').DataTable().destroy();
    }
    $('#sites-table').DataTable({
      pageLength: 25,
      order: [[2, 'asc']],
      columnDefs: [{ orderable: false, targets: 6 }],
    });
  }
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
  const form  = document.getElementById('site-form');

  form.reset();
  document.getElementById('site-id').value = '';

  if (id) {
    title.textContent = 'Edit Site';
    const detail = await apiFetch(`site_detail&id=${id}`);
    const s = detail.site;
    document.getElementById('site-id').value           = s.id;
    document.getElementById('site-name').value         = s.name;
    document.getElementById('site-url').value          = s.url;
    document.getElementById('site-check-type').value   = s.check_type;
    document.getElementById('site-port').value         = s.port || '';
    document.getElementById('site-hostname').value     = s.hostname || '';
    document.getElementById('site-keyword').value      = s.keyword || '';
    document.getElementById('site-expected').value     = s.expected_status || 200;
    document.getElementById('site-email').value        = s.alert_email || '';
    document.getElementById('site-active').checked     = s.is_active == 1;
  } else {
    title.textContent = 'Add Site';
  }

  modal.classList.add('open');
}

function closeModal() {
  document.getElementById('modal-overlay')?.classList.remove('open');
}

async function saveSite(e) {
  e.preventDefault();
  const form = e.target;
  const data = {
    id:              document.getElementById('site-id').value || null,
    name:            document.getElementById('site-name').value,
    url:             document.getElementById('site-url').value,
    check_type:      document.getElementById('site-check-type').value,
    port:            document.getElementById('site-port').value,
    hostname:        document.getElementById('site-hostname').value,
    keyword:         document.getElementById('site-keyword').value,
    expected_status: document.getElementById('site-expected').value,
    alert_email:     document.getElementById('site-email').value,
    is_active:       document.getElementById('site-active').checked ? 1 : 0,
  };

  try {
    await apiPost('save_site', data);
    closeModal();
    showToast('Site saved successfully', 'success');
    loadDashboard();
  } catch (err) {
    showToast('Save failed: ' + err.message, 'error');
  }
}

async function deleteSite(id, name) {
  if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;
  try {
    await apiPost(`delete_site&id=${id}`, {});
    showToast('Site deleted', 'success');
    loadDashboard();
  } catch (err) {
    showToast('Delete failed: ' + err.message, 'error');
  }
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
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.textContent = msg;
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
