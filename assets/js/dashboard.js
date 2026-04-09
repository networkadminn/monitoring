// =============================================================================
// dashboard.js - Dashboard interactivity, charts, DataTables
// =============================================================================

const API = 'api.php';

// Chart.js global defaults - will be initialized after Chart.js is confirmed loaded
function initializeChartDefaults() {
  if (typeof Chart !== 'undefined') {
    Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
    Chart.defaults.font.size = 12;
  }
}

function getChartColor(key) {
  const isLight = document.body.classList.contains('light-theme');
  const colors = {
    text: isLight ? '#1e293b' : '#e4eaf6',
    muted: isLight ? '#64748b' : '#7a87a8',
    grid: isLight ? 'rgba(0,0,0,0.05)' : 'rgba(255,255,255,0.05)',
  };
  return colors[key];
}

// ── State ─────────────────────────────────────────────────────────────────
const charts = {};
let sitesData = [];
let sitesTable = null; // DataTable instance
let refreshTimer = null;
let lastAddedSiteId = null; // To highlight newly added site

// Global error handler for debugging
window.addEventListener('error', (e) => {
  console.error('Unhandled JS Error:', e.error);
  showToast('Interface error: ' + (e.error?.message || 'Check console'), 'error');
});

// Time filtering helpers
function getTimeFilterParams() {
  const timeRange = document.getElementById('time-range')?.value || '24h';
  const customRange = document.getElementById('custom-range');
  
  if (timeRange === 'custom' && customRange.style.display !== 'none') {
    const startDateEl = document.getElementById('start-date');
    const endDateEl = document.getElementById('end-date');
    let startDate = startDateEl?.value;
    let endDate = endDateEl?.value;
    
    if (startDate && endDate) {
      // Validate date format first
      const start = new Date(startDate);
      const end = new Date(endDate);
      
      if (isNaN(start.getTime()) || isNaN(end.getTime())) {
        showToast('Invalid date format. Please use YYYY-MM-DD format.', 'error');
        return getTimeRangeParams('24h'); // Fallback to default
      }
      
      // Validate date range and swap if needed
      if (start > end) {
        console.warn('Start date cannot be after end date, swapping');
        showToast('Start date was after end date. Dates have been swapped.', 'warning');
        
        // Update DOM values
        [startDateEl.value, endDateEl.value] = [endDate, startDate];
        [startDate, endDate] = [endDate, startDate];
      }
      
      return {
        startDate: startDate + ' 00:00:00',
        endDate: endDate + ' 23:59:59',
        granularity: getGranularityFromDateRange(startDate, endDate)
      };
    }
  }
  
  return getTimeRangeParams(timeRange);
}

function getTimeRangeParams(range) {
  const now = new Date();
  let startDate, endDate, granularity;
  
  switch (range) {
    case '1h':
      startDate = new Date(now.getTime() - 60 * 60 * 1000);
      endDate = now;
      granularity = 'minute';
      break;
    case '6h':
      startDate = new Date(now.getTime() - 6 * 60 * 60 * 1000);
      endDate = now;
      granularity = 'hour';
      break;
    case '24h':
      startDate = new Date(now.getTime() - 24 * 60 * 60 * 1000);
      endDate = now;
      granularity = 'hour';
      break;
    case '7d':
      startDate = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
      endDate = now;
      granularity = 'day';
      break;
    case '30d':
      startDate = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000);
      endDate = now;
      granularity = 'day';
      break;
    case '90d':
      startDate = new Date(now.getTime() - 90 * 24 * 60 * 60 * 1000);
      endDate = now;
      granularity = 'week';
      break;
    default:
      startDate = new Date(now.getTime() - 24 * 60 * 60 * 1000);
      endDate = now;
      granularity = 'hour';
  }
  
  return {
    startDate: startDate.toISOString().slice(0, 19).replace('T', ' '),
    endDate: endDate.toISOString().slice(0, 19).replace('T', ' '),
    granularity: granularity
  };
}

function getGranularityFromDateRange(start, end) {
  const days = Math.ceil((new Date(end) - new Date(start)) / (1000 * 60 * 60 * 24));
  if (days <= 1) return 'hour';
  if (days <= 7) return 'day';
  if (days <= 30) return 'day';
  if (days <= 90) return 'week';
  return 'month';
}

// ── Init ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Initialize Chart.js defaults first
  initializeChartDefaults();
  initTheme();
  initCheckboxDelegation();

  // Load dashboard data if either dashboard or sites table is present
  if (document.getElementById('chart-status-types') || document.getElementById('sites-table')) {
    loadDashboard();
    refreshTimer = setInterval(loadDashboard, 30000);
  }

  if (document.getElementById('site-detail-container')) {
    initSiteDetails();
  }

  // Topbar buttons
  document.getElementById('btn-add-site')?.addEventListener('click', () => openSiteModal());
  document.getElementById('btn-refresh')?.addEventListener('click', loadDashboard);
  document.getElementById('btn-run-cron')?.addEventListener('click', runManualCheck);
  document.getElementById('btn-bulk-delete')?.addEventListener('click', bulkDeleteSites);
  document.getElementById('btn-theme-toggle')?.addEventListener('click', toggleTheme);

  // Time filter controls
  const timeRange = document.getElementById('time-range');
  const customRange = document.getElementById('custom-range');
  const startDate = document.getElementById('start-date');
  const endDate = document.getElementById('end-date');

  if (timeRange) {
    timeRange.addEventListener('change', (e) => {
      if (e.target.value === 'custom') {
        customRange.style.display = 'block';
        // Set default dates (last 30 days)
        const end = new Date();
        const start = new Date();
        start.setDate(start.getDate() - 30);
        endDate.value = end.toISOString().split('T')[0];
        startDate.value = start.toISOString().split('T')[0];
      } else {
        customRange.style.display = 'none';
      }
      loadDashboard(); // Reload charts with new time range
    });
  }

  if (startDate && endDate) {
    [startDate, endDate].forEach(input => {
      input.addEventListener('change', () => loadDashboard());
    });
  }

  // Modal save/cancel
  document.getElementById('modal-save')?.addEventListener('click', saveSite);
  document.getElementById('modal-test')?.addEventListener('click', testConnection);
  document.getElementById('modal-cancel')?.addEventListener('click', closeModal);
  document.getElementById('modal-close')?.addEventListener('click', closeModal);
  document.getElementById('modal-overlay')?.addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeModal();
  });

  // Search filter
  document.getElementById('site-search')?.addEventListener('input', (e) => {
    filterSites(e.target.value);
  });

  // Confirm modal
  document.getElementById('confirm-cancel')?.addEventListener('click', closeConfirm);
  document.getElementById('confirm-overlay')?.addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeConfirm();
  });

  // Tabs in modal
  document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => switchTab(tab.dataset.tab));
  });
});

// ── Main loader ───────────────────────────────────────────────────────────
async function loadDashboard() {
  console.log('loadDashboard started');
  const overlay = document.getElementById('sites-loading-overlay');
  if (overlay) overlay.classList.add('active');

  const path = window.location.pathname;
  const page = path.split('/').pop() || 'index.php';
  const params = new URLSearchParams(window.location.search);
  const filterType = params.get('type');
  const filterTag  = params.get('tag');

  // Robust page detection
  const isDashboard = document.getElementById('chart-status-types') !== null;
  const isSitesPage = document.getElementById('sites-table') !== null;
  console.log('Detection results:', { isDashboard, isSitesPage });

  if (!isDashboard && !isSitesPage) {
    console.warn('No dashboard or sites table element found on this page.');
    return;
  }

  try {
    if (isDashboard) {
      // Load data with individual error handling to prevent one failure from breaking everything
      let health = null, sites = null, incidents = null, ssl = null, slowest = null, systemUptime = null;
      
      try {
        health = await apiFetch('health');
      } catch (err) {
        console.error('Failed to load health data:', err);
        showToast('Failed to load system health data', 'error');
      }
      
      try {
        sites = await apiFetch('sites');
      } catch (err) {
        console.error('Failed to load sites data:', err);
        showToast('Failed to load sites data', 'error');
      }
      
      try {
        incidents = await apiFetch('incidents');
      } catch (err) {
        console.error('Failed to load incidents data:', err);
        showToast('Failed to load incidents data', 'error');
      }
      
      try {
        ssl = await apiFetch('ssl_expiry');
      } catch (err) {
        console.error('Failed to load SSL data:', err);
        showToast('Failed to load SSL data', 'error');
      }
      
      try {
        slowest = await apiFetch('slowest');
      } catch (err) {
        console.error('Failed to load slowest data:', err);
        showToast('Failed to load slowest data', 'error');
      }
      
      try {
        systemUptime = await apiFetch('system_uptime');
      } catch (err) {
        console.error('Failed to load system uptime data:', err);
        showToast('Failed to load system uptime data', 'error');
      }

      // Only set sitesData if we successfully loaded it
      if (sites) {
        sitesData = sites;
      }
      
      // Render each component with its own error handling
      if (health) {
        renderHealthCards(health);
        renderGauge(health.health_score);
      }
      
      if (incidents) {
        renderIncidentsTable(incidents);
      }
      
      if (ssl) {
        renderSSLChart(ssl);
      }
      
      if (sites) {
        renderStatusTypesChart(sites);
        renderResponseTrendChart(sites);
        renderHistogramChart(sites);
      }
      
      if (systemUptime) {
        renderSystemUptimeChart();
      }
      
      if (slowest) {
        renderSlowestList(slowest);
      }
    } 
    
    if (isSitesPage) {
      console.log('Loading sites for table view...');
      let sites = await apiFetch('sites');
      
      if (!sites || !Array.isArray(sites)) {
        throw new Error('Invalid sites data received from API');
      }

      console.log('API sites fetched:', sites.length);
      
      // Apply URL-based filtering and sidebar highlighting
      if (filterType || filterTag) {
        console.log('Applying filter:', { filterType, filterTag });
        document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
        
        if (filterType) {
          const navMap = { websites: 'nav-websites', ssl: 'nav-ssl', ports: 'nav-ports' };
          const activeNav = document.getElementById(navMap[filterType]);
          if (activeNav) {
            activeNav.classList.add('active');
          }

          const originalCount = sites.length;
          sites = sites.filter(s => {
            const ct = (s.check_type || 'http').toLowerCase();
            if (filterType === 'websites') return ['http', 'keyword', 'ssl'].includes(ct);
            if (filterType === 'ssl') return (s.ssl_expiry_days !== null && s.ssl_expiry_days !== undefined);
            if (filterType === 'ports') return ct === 'port';
            return true;
          });
          console.log(`Filtered from ${originalCount} to ${sites.length} sites for type "${filterType}"`);
        }

        if (filterTag) {
          const originalCount = sites.length;
          sites = sites.filter(s => {
            if (!s.tags) return false;
            const tagList = s.tags.toLowerCase().split(',').map(t => t.trim());
            return tagList.includes(filterTag.toLowerCase());
          });
          console.log(`Filtered from ${originalCount} to ${sites.length} sites for tag "${filterTag}"`);
        }
      } else {
        // Highlight "All Monitors" if no filters
        document.getElementById('nav-all')?.classList.add('active');
      }

      sitesData = sites;
      console.log('Rendering sites table with:', sites.length, 'sites');
      renderSitesTable(sites);
    }

    updateLastUpdated();
  } catch (err) {
    console.error('Load error:', err);
    showToast('Failed to load data: ' + err.message, 'error');
  } finally {
    if (overlay) overlay.classList.remove('active');
  }
}

function filterSites(query) {
  const q = query.toLowerCase().trim();
  const filtered = sitesData.filter(s =>
    s.name.toLowerCase().includes(q) ||
    s.url.toLowerCase().includes(q) ||
    (s.tags && s.tags.toLowerCase().includes(q))
  );
  renderSitesTable(filtered);
}

function renderSlowestList(slowest) {
  const list = document.getElementById('slowest-list');
  if (!list) return;

  if (!slowest.length) {
    list.innerHTML = '<div class="empty-state">No data available</div>';
    return;
  }

  const maxRt = Math.max(...slowest.map(s => s.avg_rt));
  list.innerHTML = slowest.map(s => {
    const pct = (s.avg_rt / maxRt) * 100;
    return `
      <div class="slowest-item">
        <div class="slowest-name truncate" title="${esc(s.name)}">${esc(s.name)}</div>
        <div class="slowest-rt">${Math.round(s.avg_rt)} ms</div>
        <div class="slowest-bar-wrap">
          <div class="slowest-bar" style="width:${pct}%"></div>
        </div>
      </div>
    `;
  }).join('');
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
  console.log('renderSitesTable called with:', sites.length, 'sites');
  
  // Destroy DataTable FIRST before touching the DOM
  if (sitesTable) {
    console.log('Destroying existing DataTable');
    sitesTable.destroy();
    sitesTable = null;
  }

  const tbody = document.getElementById('sites-tbody');
  if (!tbody) {
    console.error('CRITICAL: sites-tbody element NOT found in DOM');
    return;
  }

  if (!sites || !sites.length) {
    console.warn('renderSitesTable: No sites to display');
    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">
      No monitors found for this view.
    </td></tr>`;
    return;
  }

  tbody.innerHTML = sites.map(s => {
    try {
      const uptime   = parseFloat(s.uptime_percentage) || 0;
      const barColor = uptime >= 99 ? 'green' : uptime >= 95 ? 'yellow' : 'red';
      const rt       = s.response_time ? Math.round(s.response_time) + ' ms' : '—';
      const checked  = s.last_checked ? timeAgo(s.last_checked) : 'Never';
      const status   = s.status || 'unknown';
      const domain   = (() => { try { return new URL(s.url).hostname; } catch(e) { return s.url; } })();
      const tags     = (s.tags || '').split(',').map(t => t.trim()).filter(t => t).map(t => `<span class="tag-badge">${esc(t)}</span>`).join('');
      const error    = s.status === 'down' ? `<div class="text-red" style="font-size:11px;margin-top:4px">${esc(s.error_message)}</div>` : '';
      
      // SSL Badge
      let sslBadge = '';
      if (s.ssl_expiry_days !== null) {
        const sslCls = s.ssl_expiry_days <= 7 ? 'red' : s.ssl_expiry_days <= 30 ? 'yellow' : 'green';
        sslBadge = `<div style="font-size:10px;margin-top:4px;color:var(--${sslCls})">
          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="vertical-align:middle;margin-right:2px"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          ${s.ssl_expiry_days}d left
        </div>`;
      }

      // 30 uptime blocks proportional to uptime %
      const filledCount = Math.round(uptime * 30 / 100);
      const blockCls    = uptime >= 99 ? 'up' : uptime >= 90 ? 'partial' : 'down';
      const blocks = Array.from({length: 30}, (_, i) =>
        `<div class="uptime-block ${i < filledCount ? blockCls : 'empty'}" title="Day ${i+1}"></div>`
      ).join('');

      const isNew = lastAddedSiteId == s.id;
      const rowCls = isNew ? 'row-new pulse-highlight' : '';

      return `<tr class="${rowCls}">
        <td><input type="checkbox" class="site-checkbox" data-id="${s.id}" data-name="${esc(s.name)}" style="cursor:pointer"></td>
        <td>
          <div class="site-name-cell">
            <div class="site-favicon">
              <img src="https://www.google.com/s2/favicons?domain=${encodeURIComponent(domain)}&sz=32" onerror="this.style.display='none'" alt="">
            </div>
            <div>
              <div style="display:flex;align-items:center;gap:6px">
                <a href="site_details.php?id=${s.id}" class="site-name-link">${esc(s.name)}</a>
                ${tags}
              </div>
              <div class="site-url-small truncate">${esc(s.url)}</div>
            </div>
          </div>
        </td>
        <td>
          <span class="badge ${status}"><span class="badge-dot"></span>${status}</span>
          ${error}
          ${sslBadge}
        </td>
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
            <button class="btn btn-ghost btn-sm" onclick="openSiteModal(${s.id})">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              Edit
            </button>
            <button class="btn btn-danger btn-sm" onclick="confirmDeleteSite(${s.id}, '${esc(s.name)}')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
            </button>
          </div>
        </td>
      </tr>`;
    } catch (e) {
      console.error('Error rendering site row:', e, s);
      return '';
    }
  }).join('');

  // Init DataTable — use scrollX for wide tables
  if (window.jQuery && $.fn.DataTable) {
    const tableEl = $('#sites-table');
    console.log('Initializing DataTable on #sites-table');
    
    // Safety check for empty tbody
    if (tbody.rows.length === 0) {
      console.warn('DataTable init skipped: tbody is empty');
      return;
    }

    sitesTable = tableEl.DataTable({
      pageLength: 25,
      stateSave: true,
      stateDuration: 60 * 60 * 24, // 24 hours
      order: [[2, 'asc']],
      columnDefs: [{ orderable: false, targets: [0, 5, 7] }],
      language: { search: 'Filter:', lengthMenu: 'Show _MENU_ monitors' },
      drawCallback: function() {
        bindCheckboxEvents();
      },
    });

    // If we're on a filtered view, ensure DataTables search is cleared to avoid double-filtering
    const params = new URLSearchParams(window.location.search);
    if (params.get('type') || params.get('tag')) {
      sitesTable.search('').draw(false);
    }
  } else {
    bindCheckboxEvents();
  }

  // Clear highlight after a few seconds
  if (lastAddedSiteId) {
    setTimeout(() => {
      document.querySelectorAll('.pulse-highlight').forEach(el => el.classList.remove('pulse-highlight'));
      lastAddedSiteId = null;
    }, 4000);
  }

  const countEl = document.getElementById('sites-count');
  if (countEl) countEl.textContent = sites.length + ' monitor' + (sites.length !== 1 ? 's' : '');
}

// ── Checkbox / bulk-delete wiring ─────────────────────────────────────────
// Called once on init — uses event delegation so it survives DataTable redraws
function initCheckboxDelegation() {
  // Select-all: delegated on document since DataTables moves thead
  document.addEventListener('change', (e) => {
    if (e.target.id === 'select-all-sites') {
      document.querySelectorAll('.site-checkbox').forEach(cb => cb.checked = e.target.checked);
      updateBulkBtn();
    }
    if (e.target.classList.contains('site-checkbox')) {
      const all  = document.querySelectorAll('.site-checkbox').length;
      const done = document.querySelectorAll('.site-checkbox:checked').length;
      const sa   = document.getElementById('select-all-sites');
      if (sa) sa.checked = all > 0 && all === done;
      updateBulkBtn();
    }
  });
}

function bindCheckboxEvents() {
  // Reset select-all state on each render
  const sa = document.getElementById('select-all-sites');
  if (sa) sa.checked = false;
  updateBulkBtn();
}

function updateBulkBtn() {
  const n       = document.querySelectorAll('.site-checkbox:checked').length;
  const bulkBtn = document.getElementById('btn-bulk-delete');
  const count   = document.getElementById('bulk-count');
  if (bulkBtn)  bulkBtn.style.display = n > 0 ? '' : 'none';
  if (count)    count.textContent = n;
}

// ── Incidents table ───────────────────────────────────────────────────────
function renderIncidentsTable(incidents) {
  // Destroy existing DataTable if it exists
  if ($.fn.DataTable.isDataTable('#incidents-table')) {
    $('#incidents-table').DataTable().destroy();
  }

  const tbody = document.getElementById('incidents-tbody');
  if (!tbody) return;

  if (!incidents.length) {
    tbody.innerHTML = ''; // Keep it empty for DataTables
    const wrap = tbody.closest('.table-wrap');
    if (wrap) {
      const existing = wrap.querySelector('.empty-state');
      if (existing) existing.remove();
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No incidents recorded';
      wrap.appendChild(empty);
    }
    return;
  }

  // Remove empty state if it exists
  const wrap = tbody.closest('.table-wrap');
  if (wrap) {
    const existing = wrap.querySelector('.empty-state');
    if (existing) existing.remove();
  }

  tbody.innerHTML = incidents.map(i => {
    const dur = i.duration_seconds
      ? formatDuration(i.duration_seconds)
      : '<span class="text-red">Ongoing</span>';
    return `<tr>
      <td><a href="site_details.php?id=${i.site_id || ''}" class="site-name-link">${esc(i.site_name || '')}</a></td>
      <td>${formatDate(i.started_at)}</td>
      <td>${i.ended_at ? formatDate(i.ended_at) : '—'}</td>
      <td>${dur}</td>
      <td class="text-muted" style="font-size:12px">${esc(i.error_message || '')}</td>
    </tr>`;
  }).join('');

  if (window.jQuery && $.fn.DataTable && incidents.length > 0) {
    $('#incidents-table').DataTable({
      pageLength: 10,
      order: [[1, 'desc']],
      columnDefs: [{ orderable: false, targets: [4] }],
      language: { search: 'Filter incidents:' }
    });
  }
}

// ── Response time trend (multi-site line chart) with flexible time filtering
async function renderResponseTrendChart(sites) {
  if (!sites || !sites.length) return;
  const ids = sites.slice(0, 8).map(s => s.id).join(',');
  if (!ids) return;

  // Check if Chart.js is available
  if (typeof Chart === 'undefined') {
    console.error('Chart.js is not loaded');
    showToast('Chart library not available', 'error');
    return;
  }

  const ctx = document.getElementById('chart-response-trend');
  if (!ctx) return;

  try {
    const timeParams = getTimeFilterParams();
    const queryParams = new URLSearchParams({
      action: 'response_trend_flexible',
      ids: ids,
      start_date: timeParams.startDate,
      end_date: timeParams.endDate,
      granularity: timeParams.granularity
    });

    const data = await apiFetch(queryParams.toString());

  // Build unified label set
    const allPeriods = new Set();
    Object.values(data).forEach(rows => rows.forEach(r => allPeriods.add(r.period)));
    const labels = [...allPeriods].sort();

    const colors = ['#3b82f6','#22c55e','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#f97316','#ec4899'];
    const datasets = sites.slice(0, 8).map((site, i) => {
      const rows = data[site.id] || [];
      const map  = Object.fromEntries(rows.map(r => [r.period, r.avg_rt]));
      return {
        label: site.name,
        data: labels.map(p => map[p] ?? null),
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
      data: { labels: formatLabels(labels, timeParams.granularity), datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { 
          legend: { display: false },
          tooltip: {
            backgroundColor: () => getChartColor('surface'),
            titleColor: () => getChartColor('text'),
            bodyColor: () => getChartColor('text'),
          }
        },
        scales: {
          y: { 
            title: { display: true, text: 'Response Time (ms)', color: () => getChartColor('muted') }, 
            beginAtZero: true,
            grid: { color: () => getChartColor('grid') },
            ticks: { color: () => getChartColor('muted') }
          },
          x: { 
            grid: { display: false },
            ticks: { color: () => getChartColor('muted'), maxTicksLimit: 12 }
          }
        },
      },
    });

    // Render custom legend
    const legend = document.getElementById('trend-legend');
    if (legend) {
      legend.innerHTML = sites.slice(0, 8).map((s, i) => `
        <div class="legend-item">
          <span class="legend-dot" style="background:${colors[i]}"></span>
          <span>${esc(s.name)}</span>
        </div>
      `).join('');
    }
  } catch (err) {
    console.error('Failed to render response trend chart:', err);
    showToast('Failed to load response trend chart', 'error');
    
    // Show error message in chart container
    if (ctx) {
      ctx.getContext('2d').font = '14px Inter';
      ctx.getContext('2d').fillStyle = getChartColor('muted');
      ctx.getContext('2d').textAlign = 'center';
      ctx.getContext('2d').textBaseline = 'middle';
      ctx.getContext('2d').fillText('Failed to load chart data', ctx.width/2, ctx.height/2);
    }
  }
}

// Helper to format labels based on granularity
function formatLabels(labels, granularity) {
  switch (granularity) {
    case 'minute':
      return labels.map(l => l.slice(11, 16));
    case 'hour':
      return labels.map(l => l.slice(11, 16));
    case 'day':
      return labels.map(l => l.slice(5));
    case 'week':
      return labels.map(l => `Week ${l.split('-')[1]}`);
    case 'month':
      return labels.map(l => l);
    default:
      return labels.map(l => l.slice(11, 16));
  }
}

// ── SSL expiry bar chart ──────────────────────────────────────────────────
function renderSSLChart(sslData) {
  // Check if Chart.js is available
  if (typeof Chart === 'undefined') {
    console.error('Chart.js is not loaded');
    showToast('Chart library not available', 'error');
    return;
  }
  
  const ctx = document.getElementById('chart-ssl');
  if (!ctx) return;
  
  if (!sslData || !sslData.length) {
    // Show empty state message
    ctx.getContext('2d').font = '14px Inter';
    ctx.getContext('2d').fillStyle = getChartColor('muted');
    ctx.getContext('2d').textAlign = 'center';
    ctx.getContext('2d').textBaseline = 'middle';
    ctx.getContext('2d').fillText('No SSL data available', ctx.width/2, ctx.height/2);
    return;
  }

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
      plugins: { 
        legend: { display: false },
        tooltip: {
          backgroundColor: () => getChartColor('surface'),
          titleColor: () => getChartColor('text'),
          bodyColor: () => getChartColor('text'),
        }
      },
      scales: {
        y: { 
          title: { display: true, text: 'Days Remaining', color: () => getChartColor('muted') }, 
          beginAtZero: true,
          grid: { color: () => getChartColor('grid') },
          ticks: { color: () => getChartColor('muted') }
        },
        x: {
          grid: { display: false },
          ticks: { color: () => getChartColor('muted') }
        }
      },
    },
  });
}

// ── Status by type doughnut ───────────────────────────────────────────────
function renderStatusTypesChart(sites) {
  // Check if Chart.js is available
  if (typeof Chart === 'undefined') {
    console.error('Chart.js is not loaded');
    showToast('Chart library not available', 'error');
    return;
  }
  
  const ctx = document.getElementById('chart-status-types');
  if (!ctx) return;
  
  if (!sites || !sites.length) {
    // Show empty state message
    ctx.getContext('2d').font = '14px Inter';
    ctx.getContext('2d').fillStyle = getChartColor('muted');
    ctx.getContext('2d').textAlign = 'center';
    ctx.getContext('2d').textBaseline = 'middle';
    ctx.getContext('2d').fillText('No sites data available', ctx.width/2, ctx.height/2);
    return;
  }

  const counts = {};
  sites.forEach(s => {
    const t = s.check_type || 'http';
    counts[t] = (counts[t] || 0) + 1;
  });

  const labels = Object.keys(counts).map(k => k.toUpperCase());
  const data   = Object.values(counts);
  const colors = ['#3b82f6', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6'];

  destroyChart('status-types');
  charts['status-types'] = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data,
        backgroundColor: colors,
        borderWidth: 0,
        hoverOffset: 4
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { 
          position: 'right', 
          labels: { 
            boxWidth: 12, 
            padding: 15,
            color: () => getChartColor('muted')
          } 
        },
        tooltip: {
          backgroundColor: () => getChartColor('surface'),
          titleColor: () => getChartColor('text'),
          bodyColor: () => getChartColor('text'),
        }
      },
      cutout: '60%'
    }
  });
}

// ── System-wide uptime trend with flexible time filtering ──────────────────
async function renderSystemUptimeChart() {
  // Check if Chart.js is available
  if (typeof Chart === 'undefined') {
    console.error('Chart.js is not loaded');
    showToast('Chart library not available', 'error');
    return;
  }

  const ctx = document.getElementById('chart-uptime');
  if (!ctx) return;

  try {
    const timeParams = getTimeFilterParams();
    const queryParams = new URLSearchParams({
      action: 'system_uptime_flexible',
      start_date: timeParams.startDate,
      end_date: timeParams.endDate,
      granularity: timeParams.granularity
    });

    const data = await apiFetch(queryParams.toString());
    if (!data.length) return;

  destroyChart('uptime');
  charts['uptime'] = new Chart(ctx, {
    type: 'line',
    data: {
      labels: formatLabels(data.map(d => d.period), timeParams.granularity),
      datasets: [{
        label: 'System Uptime %',
        data: data.map(d => d.uptime_percentage),
        borderColor: '#22c55e',
        backgroundColor: 'rgba(34,197,94,0.1)',
        fill: true,
        tension: 0.4,
        pointRadius: 2,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { 
        legend: { display: false },
        tooltip: {
          backgroundColor: () => getChartColor('surface'),
          titleColor: () => getChartColor('text'),
          bodyColor: () => getChartColor('text'),
        }
      },
      scales: {
        y: { 
          min: 0, max: 100, 
          ticks: { stepSize: 20, color: () => getChartColor('muted') }, 
          grid: { color: () => getChartColor('grid') } 
        },
        x: { 
          grid: { display: false },
          ticks: { color: () => getChartColor('muted') }
        }
      },
    },
  });
  } catch (err) {
    console.error('Failed to render system uptime chart:', err);
    showToast('Failed to load system uptime chart', 'error');
    
    // Show error message in chart container
    if (ctx) {
      ctx.getContext('2d').font = '14px Inter';
      ctx.getContext('2d').fillStyle = getChartColor('muted');
      ctx.getContext('2d').textAlign = 'center';
      ctx.getContext('2d').textBaseline = 'middle';
      ctx.getContext('2d').fillText('Failed to load chart data', ctx.width/2, ctx.height/2);
    }
  }
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
  
  // Check if Chart.js is available
  if (typeof Chart === 'undefined') {
    console.error('Chart.js is not loaded');
    showToast('Chart library not available', 'error');
    return;
  }
  
  const ctx  = document.getElementById('chart-histogram');
  if (!ctx) return;

  try {
    const data = await apiFetch(`histogram&id=${id}`);

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
      plugins: { 
        legend: { display: false },
        tooltip: {
          backgroundColor: () => getChartColor('surface'),
          titleColor: () => getChartColor('text'),
          bodyColor: () => getChartColor('text'),
        }
      },
      scales: {
        y: { 
          title: { display: true, text: 'Count', color: () => getChartColor('muted') }, 
          beginAtZero: true,
          grid: { color: () => getChartColor('grid') },
          ticks: { color: () => getChartColor('muted') }
        },
        x: { 
          title: { display: true, text: 'Response Time', color: () => getChartColor('muted') },
          grid: { display: false },
          ticks: { color: () => getChartColor('muted') }
        },
      },
    },
  });
  } catch (err) {
    console.error('Failed to render histogram chart:', err);
    showToast('Failed to load histogram chart', 'error');
    
    // Show error message in chart container
    if (ctx) {
      ctx.getContext('2d').font = '14px Inter';
      ctx.getContext('2d').fillStyle = getChartColor('muted');
      ctx.getContext('2d').textAlign = 'center';
      ctx.getContext('2d').textBaseline = 'middle';
      ctx.getContext('2d').fillText('Failed to load chart data', ctx.width/2, ctx.height/2);
    }
  }
}

// ── Health gauge (doughnut) ───────────────────────────────────────────────
function renderGauge(score) {
  // Check if Chart.js is available
  if (typeof Chart === 'undefined') {
    console.error('Chart.js is not loaded');
    showToast('Chart library not available', 'error');
    return;
  }
  
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
        backgroundColor: [color, () => getChartColor('grid')],
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
  const saveBtn = document.getElementById('modal-save');
  const testRes = document.getElementById('test-result');

  // Reset form + errors
  document.getElementById('site-form').reset();
  document.getElementById('site-id').value = '';
  document.querySelectorAll('.form-error').forEach(el => el.textContent = '');
  document.querySelectorAll('.form-control').forEach(el => el.classList.remove('error'));
  if (testRes) {
    testRes.style.display = 'none';
    testRes.textContent = '';
  }
  switchTab('basic');

  if (id) {
    title.textContent = 'Edit Monitor';
    saveBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Save Changes';
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
      document.getElementById('site-teams').value      = s.alert_teams || '';
      document.getElementById('site-slack').value      = s.alert_slack || '';
      document.getElementById('site-discord').value    = s.alert_discord || '';
      document.getElementById('site-webhook').value    = s.alert_webhook || '';
      document.getElementById('site-pagerduty').value  = s.alert_pagerduty || '';
      document.getElementById('site-active').checked   = s.is_active == 1;
      document.getElementById('site-tags').value       = s.tags || '';
      document.getElementById('site-failure-threshold').value   = s.failure_threshold || 3;
      document.getElementById('site-recovery-threshold').value  = s.recovery_threshold || 3;
      document.getElementById('site-interval').value   = s.check_interval || 1;
      // Load check locations
      const savedLocs = (s.check_locations || 'local').split(',').map(l => l.trim());
      document.querySelectorAll('#location-checkboxes input[name="loc"]').forEach(cb => {
        cb.checked = savedLocs.includes(cb.value);
      });
    } catch (err) {
      showToast('Failed to load monitor: ' + err.message, 'error');
      return;
    }
  } else {
    title.textContent = 'Add Monitor';
    saveBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Save Monitor';
  }

  modal.classList.add('open');
  document.getElementById('site-name').focus();
}

async function testConnection() {
  const btn = document.getElementById('modal-test');
  const res = document.getElementById('test-result');
  if (!btn || !res) return;

  const url = document.getElementById('site-url').value.trim();
  if (!url) {
    showToast('URL is required to test connection', 'error');
    return;
  }

  const data = {
    url,
    check_type:      document.getElementById('site-check-type').value,
    port:            document.getElementById('site-port').value,
    hostname:        document.getElementById('site-hostname').value,
    keyword:         document.getElementById('site-keyword').value,
    expected_status: document.getElementById('site-expected').value || 200,
  };

  const origHTML = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Testing…';
  res.style.display = 'block';
  res.className = 'test-result testing';
  res.textContent = 'Connecting to ' + url + '...';
  res.style.backgroundColor = 'rgba(255,255,255,0.05)';
  res.style.color = 'var(--text)';

  try {
    const result = await apiPost('test_connection', data);
    btn.disabled = false;
    btn.innerHTML = origHTML;

    if (result.status === 'up') {
      res.style.backgroundColor = 'rgba(34,197,94,0.1)';
      res.style.color = '#22c55e';
      res.innerHTML = `<strong>Success!</strong> Connection established in ${Math.round(result.response_time)}ms.`;
    } else {
      res.style.backgroundColor = 'rgba(239,68,68,0.1)';
      res.style.color = '#ef4444';
      res.innerHTML = `<strong>Failed:</strong> ${esc(result.error_message || 'Unknown error')}`;
    }
  } catch (err) {
    btn.disabled = false;
    btn.innerHTML = origHTML;
    res.style.backgroundColor = 'rgba(239,68,68,0.1)';
    res.style.color = '#ef4444';
    res.textContent = 'Test failed: ' + err.message;
  }
}

function closeModal() {
  document.getElementById('modal-overlay')?.classList.remove('open');
}

async function saveSite() {
  const name = document.getElementById('site-name').value.trim();
  const url  = document.getElementById('site-url').value.trim();
  const isEdit = !!document.getElementById('site-id').value;
  let valid  = true;

  // Clear previous errors
  document.querySelectorAll('.form-error').forEach(el => el.textContent = '');
  document.querySelectorAll('.form-control').forEach(el => el.classList.remove('error'));

  if (!name) {
    document.getElementById('err-name').textContent = 'Name is required';
    document.getElementById('site-name').classList.add('error');
    // Switch to basic tab
    switchTab('basic');
    valid = false;
  }
  if (!url) {
    document.getElementById('err-url').textContent = 'URL is required';
    document.getElementById('site-url').classList.add('error');
    switchTab('basic');
    valid = false;
  } else if (!/^https?:\/\/.+/.test(url)) {
    document.getElementById('err-url').textContent = 'Must start with http:// or https://';
    document.getElementById('site-url').classList.add('error');
    switchTab('basic');
    valid = false;
  }

  if (!valid) return;

  const saveBtn  = document.getElementById('modal-save');
  const origHTML = saveBtn.innerHTML;
  saveBtn.disabled = true;
  saveBtn.innerHTML = '<span class="spinner"></span> Saving…';

  const data = {
    id:                  document.getElementById('site-id').value || null,
    name,
    url,
    check_type:          document.getElementById('site-check-type').value,
    port:                document.getElementById('site-port').value,
    hostname:            document.getElementById('site-hostname').value,
    keyword:             document.getElementById('site-keyword').value,
    expected_status:     document.getElementById('site-expected').value || 200,
    alert_email:         document.getElementById('site-email').value,
    alert_teams:         document.getElementById('site-teams').value,
    alert_slack:         document.getElementById('site-slack')?.value || '',
    alert_discord:       document.getElementById('site-discord')?.value || '',
    alert_webhook:       document.getElementById('site-webhook')?.value || '',
    alert_pagerduty:     document.getElementById('site-pagerduty')?.value || '',
    is_active:           document.getElementById('site-active').checked ? 1 : 0,
    tags:                document.getElementById('site-tags').value,
    failure_threshold:   parseInt(document.getElementById('site-failure-threshold').value) || 3,
    recovery_threshold:  parseInt(document.getElementById('site-recovery-threshold').value) || 3,
    check_interval:      parseInt(document.getElementById('site-interval')?.value) || 1,
    check_locations:     [...document.querySelectorAll('#location-checkboxes input[name="loc"]:checked')].map(el => el.value).join(',') || 'local',
  };

  try {
    const result = await apiPost(isEdit ? 'update_site' : 'add_site', data);
    closeModal();
    showToast(result.message || (isEdit ? 'Monitor updated successfully' : 'Monitor added successfully'), 'success');
    
    // Capture the ID for highlighting if it's a new site
    if (!isEdit && result.created) {
      lastAddedSiteId = result.created;
    } else if (isEdit && data.id) {
      lastAddedSiteId = data.id;
    }
    
    loadDashboard();
  } catch (err) {
    showToast('Save failed: ' + err.message, 'error');
    saveBtn.disabled = false;
    saveBtn.innerHTML = origHTML;
  }
}

function switchTab(name) {
  document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.id === 'tab-' + name));
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
      const row = document.querySelector(`.site-checkbox[data-id="${id}"]`)?.closest('tr');
      if (row) {
        row.classList.add('row-removing');
      }

      await apiPost(`delete_site&id=${id}`, {});
      showToast('Monitor deleted', 'success');

      // Wait for animation to finish before reloading
      setTimeout(() => loadDashboard(), 400);
    } catch (err) {
      showToast('Delete failed: ' + err.message, 'error');
      loadDashboard(); // Refresh anyway to restore state
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
      checked.forEach(cb => {
        const row = cb.closest('tr');
        if (row) {
          row.classList.add('row-removing');
        }
      });

      const result = await apiPost('bulk_delete_sites', { ids });
      showToast(`${result.deleted} monitor(s) deleted`, 'success');

      setTimeout(() => loadDashboard(), 400);
    } catch (err) {
      showToast('Bulk delete failed: ' + err.message, 'error');
      loadDashboard();
    }
  });
}

async function runManualCheck() {
  const btn = document.getElementById('btn-run-cron');
  if (!btn) return;

  const origHTML = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Running…';
  showToast('Manual check started. This may take a minute...', 'info');

  try {
    const res = await apiPost('run_cron', {});
    btn.disabled = false;
    btn.innerHTML = origHTML;
    
    if (res.success) {
      showToast('All monitors checked successfully', 'success');
    } else {
      showToast('Check completed with some issues', 'warning');
    }
    loadDashboard();
  } catch (err) {
    btn.disabled = false;
    btn.innerHTML = origHTML;
    showToast('Check failed: ' + err.message, 'error');
  }
}

// ── API helpers ───────────────────────────────────────────────────────────
async function apiFetch(action) {
  try {
    const res  = await fetch(`${API}?action=${action}&t=${Date.now()}`, {
      cache: 'no-store' // Prevent API caching
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const json = await res.json();
    if (!json.success) throw new Error(json.error || 'API error');
    return json.data;
  } catch (err) {
    console.error(`apiFetch failed for action "${action}":`, err);
    throw err;
  }
}

async function apiPost(action, body) {
  try {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const res  = await fetch(`${API}?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const json = await res.json();
    if (!json.success) throw new Error(json.error || 'API error');
    return json.data;
  } catch (err) {
    console.error(`apiPost failed for action "${action}":`, err);
    throw err;
  }
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

// ── Site Details Page ─────────────────────────────────────────────────────
function initSiteDetails() {
  const params = new URLSearchParams(window.location.search);
  const siteId = params.get('id');
  if (!siteId) return;

  // Init DataTables with proper error handling
  if (window.jQuery && $.fn.DataTable) {
    // Only init if table has actual data rows (not just header)
    const logsCount = $('#logs-table tbody tr').length;
    if (logsCount > 0 && !$('#logs-table tbody tr:first td').attr('colspan')) {
      $('#logs-table').DataTable({ pageLength: 25, order: [[0, 'desc']] });
    }
    
    const incCount = $('#incidents-table tbody tr').length;
    if (incCount > 0 && !$('#incidents-table tbody tr:first td').attr('colspan')) {
      $('#incidents-table').DataTable({ pageLength: 10, order: [[0, 'desc']] });
    }
  }

  // Render charts for this specific site
  renderSiteDetailsCharts(siteId);
}

async function renderSiteDetailsCharts(id) {
  try {
    const [uptime, hist] = await Promise.all([
      apiFetch(`uptime_chart&id=${id}`),
      apiFetch(`histogram&id=${id}`),
    ]);

    const uCtx = document.getElementById('chart-uptime-detail');
    if (uCtx) {
      destroyChart('uptime-detail');
      charts['uptime-detail'] = new Chart(uCtx, {
        type: 'line',
        data: {
          labels: uptime.map(d => d.date),
          datasets: [{
            label: 'Uptime %',
            data: uptime.map(d => d.uptime_percentage),
            borderColor: '#22c55e',
            backgroundColor: 'rgba(34,197,94,0.15)',
            fill: true,
            tension: 0.3,
          }],
        },
        options: { responsive: true, plugins: { legend: { display: false } } }
      });
    }

    const hCtx = document.getElementById('chart-histogram-detail');
    if (hCtx) {
      destroyChart('histogram-detail');
      charts['histogram-detail'] = new Chart(hCtx, {
        type: 'bar',
        data: {
          labels: Object.keys(hist).map(k => k + ' ms'),
          datasets: [{
            label: 'Checks',
            data: Object.values(hist),
            backgroundColor: '#3b82f6',
            borderRadius: 4,
          }],
        },
        options: { responsive: true, plugins: { legend: { display: false } } }
      });
    }
  } catch (err) {
    console.error('Failed to load site charts:', err);
  }
}

// ── Theme management ──────────────────────────────────────────────────────
function initTheme() {
  const saved = localStorage.getItem('theme');
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const isLight = saved === 'light' || (!saved && !prefersDark);
  
  if (isLight) {
    document.documentElement.classList.add('light-theme');
    document.body.classList.add('light-theme');
    updateThemeIcons(true);
  } else {
    document.documentElement.classList.remove('light-theme');
    document.body.classList.remove('light-theme');
    updateThemeIcons(false);
  }
  
  // Set Chart.js defaults based on theme (if Chart is available)
  if (typeof Chart !== 'undefined') {
    Chart.defaults.color = isLight ? '#64748b' : '#7a87a8';
    Chart.defaults.borderColor = isLight ? 'rgba(0,0,0,0.05)' : 'rgba(255,255,255,0.05)';
  }
}

function toggleTheme() {
  const isLight = document.body.classList.toggle('light-theme');
  document.documentElement.classList.toggle('light-theme', isLight);
  localStorage.setItem('theme', isLight ? 'light' : 'dark');
  updateThemeIcons(isLight);
  
  // Update Chart.js defaults for the new theme
  if (typeof Chart !== 'undefined') {
    Chart.defaults.color = isLight ? '#64748b' : '#7a87a8';
    Chart.defaults.borderColor = isLight ? 'rgba(0,0,0,0.05)' : 'rgba(255,255,255,0.05)';
  }

  // Reload dashboard to update charts with new colors
  if (typeof loadDashboard === 'function') {
    loadDashboard();
  }
}

function updateThemeIcons(isLight) {
  const sun = document.querySelector('.sun-icon');
  const moon = document.querySelector('.moon-icon');
  if (sun && moon) {
    sun.style.display = isLight ? 'none' : 'block';
    moon.style.display = isLight ? 'block' : 'none';
  }
}
