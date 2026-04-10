// =============================================================================
// enhanced-monitoring.js - Enhanced monitoring features and UI components
// =============================================================================

class EnhancedMonitoring {
    constructor() {
        this.config = null;
        this.currentSiteId = null;
        this.performanceChart = null;
        this.errorChart = null;
        this.init();
    }

    async init() {
        await this.loadMonitoringConfig();
        this.setupEventListeners();
        this.initializeCharts();
    }

    async loadMonitoringConfig() {
        try {
            const response = await fetch('api.php?action=monitoring_config');
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.error || 'Failed to load config');
            }
            this.config = data.data;
            console.log('Monitoring config loaded:', this.config);
        } catch (error) {
            console.error('Failed to load monitoring config:', error);
            this.config = {};
        }
    }

    setupEventListeners() {
        // Enhanced site details modal
        document.addEventListener('click', (e) => {
            const button = e.target.closest('.btn-enhanced-details');
            if (button) {
                const siteId = button.dataset.siteId;
                // Validate siteId is a positive integer
                if (siteId && /^[1-9]\d*$/.test(siteId)) {
                    this.showEnhancedSiteDetails(parseInt(siteId));
                } else {
                    console.error('Invalid site ID:', siteId);
                }
            }
        });

        // Performance trends toggle
        document.addEventListener('change', (e) => {
            if (e.target.id === 'performance-time-range') {
                this.updatePerformanceTrends();
            }
        });

        // Error analysis filters
        document.addEventListener('change', (e) => {
            if (e.target.classList.contains('error-filter')) {
                this.updateErrorAnalysis();
            }
        });
    }

    async showEnhancedSiteDetails(siteId) {
        this.currentSiteId = siteId;
        
        try {
            // Show loading state
            this.showModal('enhanced-details-modal', 'Loading enhanced details...');
            
            const response = await fetch(`api.php?action=detailed_site_status&site_id=${siteId}`);
            const data = await response.json();
            
            this.renderEnhancedDetails(data);
            
        } catch (error) {
            console.error('Failed to load enhanced site details:', error);
            this.showModal('enhanced-details-modal', 'Failed to load enhanced details');
        }
    }

    renderEnhancedDetails(data) {
        const { site, latest_log, performance_metrics, ssl_certificates, error_statistics } = data;
        
        let html = `
            <div class="enhanced-details-container">
                <div class="site-header">
                    <h3>${this.escapeHtml(site.name)}</h3>
                    <div class="site-url">${this.escapeHtml(site.url)}</div>
                    <div class="site-status">
                        <span class="badge ${latest_log?.status || 'unknown'}">
                            <span class="badge-dot"></span>${latest_log?.status || 'Unknown'}
                        </span>
                        ${latest_log?.retry_count > 0 ? `<span class="retry-info">Retries: ${latest_log.retry_count}</span>` : ''}
                    </div>
                </div>
                
                <div class="details-tabs">
                    <button class="tab-btn active" data-tab="performance">Performance</button>
                    <button class="tab-btn" data-tab="ssl">SSL Analysis</button>
                    <button class="tab-btn" data-tab="errors">Error Analysis</button>
                    <button class="tab-btn" data-tab="connectivity">Connectivity</button>
                </div>
                
                <div class="tab-content">
                    ${this.renderPerformanceTab(performance_metrics, latest_log)}
                    ${this.renderSslTab(ssl_certificates)}
                    ${this.renderErrorsTab(error_statistics)}
                    ${this.renderConnectivityTab(latest_log)}
                </div>
            </div>
        `;
        
        this.showModal('enhanced-details-modal', html);
        this.setupTabSwitching();
        this.initializePerformanceCharts(performance_metrics);
    }

    renderPerformanceTab(metrics, latestLog) {
        const performance = latestLog?.performance_data ? JSON.parse(latestLog.performance_data) : {};
        
        return `
            <div class="tab-pane active" id="performance-tab">
                <div class="performance-grid">
                    <div class="metric-card">
                        <div class="metric-label">DNS Time</div>
                        <div class="metric-value">${performance.dns_time || 'N/A'} ms</div>
                        <div class="metric-status ${this.getPerformanceStatus(performance.dns_time, 100)}"></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">Connect Time</div>
                        <div class="metric-value">${performance.connect_time || 'N/A'} ms</div>
                        <div class="metric-status ${this.getPerformanceStatus(performance.connect_time, 500)}"></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">Time to First Byte</div>
                        <div class="metric-value">${performance.ttfb || 'N/A'} ms</div>
                        <div class="metric-status ${this.getPerformanceStatus(performance.ttfb, 1000)}"></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">Total Time</div>
                        <div class="metric-value">${performance.total_time || 'N/A'} ms</div>
                        <div class="metric-status ${this.getPerformanceStatus(performance.total_time, 3000)}"></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">Download Size</div>
                        <div class="metric-value">${this.formatBytes(performance.download_size || 0)}</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">Redirects</div>
                        <div class="metric-value">${performance.redirect_count || 0}</div>
                        <div class="metric-status ${performance.redirect_count > 3 ? 'warning' : 'good'}"></div>
                    </div>
                </div>
                
                <div class="performance-chart-container">
                    <h4>24-Hour Performance Trends</h4>
                    <canvas id="performance-chart" width="400" height="200"></canvas>
                </div>
                
                ${metrics.length > 0 ? this.renderAggregatedMetrics(metrics) : '<p>No historical data available</p>'}
            </div>
        `;
    }

    renderSslTab(certificates) {
        if (certificates.length === 0) {
            return `
                <div class="tab-pane" id="ssl-tab">
                    <p>No SSL certificate data available</p>
                </div>
            `;
        }

        let html = '<div class="tab-pane" id="ssl-tab"><div class="ssl-chain">';
        
        certificates.forEach((cert, index) => {
            const daysLeft = cert.days_until_expiry;
            const statusClass = daysLeft < 7 ? 'critical' : daysLeft < 30 ? 'warning' : 'good';
            
            html += `
                <div class="cert-card ${statusClass}">
                    <div class="cert-header">
                        <h5>Certificate #${index + 1} ${index === 0 ? '(Leaf)' : '(Chain)'}</h5>
                        <span class="cert-status ${statusClass}">${daysLeft} days left</span>
                    </div>
                    <div class="cert-details">
                        <div class="cert-row">
                            <span class="label">Subject:</span>
                            <span class="value">${this.escapeHtml(cert.subject || 'N/A')}</span>
                        </div>
                        <div class="cert-row">
                            <span class="label">Issuer:</span>
                            <span class="value">${this.escapeHtml(cert.issuer || 'N/A')}</span>
                        </div>
                        <div class="cert-row">
                            <span class="label">Valid From:</span>
                            <span class="value">${cert.issue_date || 'N/A'}</span>
                        </div>
                        <div class="cert-row">
                            <span class="label">Expires:</span>
                            <span class="value">${cert.expiry_date || 'N/A'}</span>
                        </div>
                        <div class="cert-row">
                            <span class="label">Algorithm:</span>
                            <span class="value">${cert.signature_algorithm || 'N/A'}</span>
                        </div>
                        ${cert.issues && cert.issues.length > 0 ? `
                            <div class="cert-issues">
                                <span class="label">Issues:</span>
                                <ul class="issues-list">
                                    ${cert.issues.map(issue => `<li>${this.escapeHtml(issue)}</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
        });
        
        html += '</div></div>';
        return html;
    }

    renderErrorsTab(errorStats) {
        if (errorStats.length === 0) {
            return `
                <div class="tab-pane" id="errors-tab">
                    <p>No error statistics available</p>
                </div>
            `;
        }

        let html = `
            <div class="tab-pane" id="errors-tab">
                <div class="error-stats">
                    <h4>Error Categories (Last 30 Days)</h4>
                    <div class="error-grid">
        `;
        
        errorStats.forEach(error => {
            const severity = this.getErrorSeverity(error.error_category);
            html += `
                <div class="error-card ${severity}">
                    <div class="error-category">${this.escapeHtml(error.error_category)}</div>
                    <div class="error-count">${error.count} occurrences</div>
                    <div class="error-timeframe">
                        First: ${this.formatDate(error.first_seen)}<br>
                        Last: ${this.formatDate(error.last_seen)}
                    </div>
                </div>
            `;
        });
        
        html += `
                    </div>
                </div>
                <div class="error-chart-container">
                    <h4>Error Distribution</h4>
                    <canvas id="error-chart" width="400" height="200"></canvas>
                </div>
            </div>
        `;
        
        return html;
    }

    renderConnectivityTab(latestLog) {
        const connectivity = latestLog?.connectivity_data ? JSON.parse(latestLog.connectivity_data) : {};
        
        return `
            <div class="tab-pane" id="connectivity-tab">
                <div class="connectivity-info">
                    <div class="connectivity-row">
                        <span class="label">Protocol:</span>
                        <span class="value">${connectivity.protocol || 'N/A'}</span>
                    </div>
                    <div class="connectivity-row">
                        <span class="label">HTTP Version:</span>
                        <span class="value">${connectivity.http_version || 'N/A'}</span>
                    </div>
                    <div class="connectivity-row">
                        <span class="label">Server Software:</span>
                        <span class="value">${this.escapeHtml(connectivity.server_info?.software || 'N/A')}</span>
                    </div>
                    ${connectivity.cache_indicators ? `
                        <div class="connectivity-row">
                            <span class="label">CDN/Cache:</span>
                            <span class="value">${this.escapeHtml(connectivity.cache_indicators)}</span>
                        </div>
                    ` : ''}
                </div>
                
                ${Object.keys(connectivity.security_headers || {}).length > 0 ? `
                    <div class="security-headers">
                        <h4>Security Headers</h4>
                        <div class="headers-grid">
                            ${Object.entries(connectivity.security_headers).map(([header, value]) => `
                                <div class="header-item">
                                    <span class="header-name">${this.escapeHtml(header)}</span>
                                    <span class="header-value">${this.escapeHtml(value)}</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                ` : '<p>No security headers detected</p>'}
                
                ${Object.keys(connectivity.response_headers || {}).length > 0 ? `
                    <details class="response-headers">
                        <summary>All Response Headers</summary>
                        <div class="headers-list">
                            ${Object.entries(connectivity.response_headers).map(([header, value]) => `
                                <div class="header-item">
                                    <span class="header-name">${this.escapeHtml(header)}</span>
                                    <span class="header-value">${this.escapeHtml(value)}</span>
                                </div>
                            `).join('')}
                        </div>
                    </details>
                ` : ''}
            </div>
        `;
    }

    renderAggregatedMetrics(metrics) {
        let html = '<div class="aggregated-metrics"><h4>Aggregated Metrics (24h)</h4><div class="metrics-table">';
        
        const metricTypes = {};
        metrics.forEach(metric => {
            if (!metricTypes[metric.metric_type]) {
                metricTypes[metric.metric_type] = [];
            }
            metricTypes[metric.metric_type].push(metric);
        });
        
        Object.entries(metricTypes).forEach(([type, values]) => {
            const avg = values.reduce((sum, v) => sum + parseFloat(v.avg_value), 0) / values.length;
            const min = Math.min(...values.map(v => parseFloat(v.min_value)));
            const max = Math.max(...values.map(v => parseFloat(v.max_value)));
            
            html += `
                <div class="metric-row">
                    <span class="metric-type">${this.formatMetricType(type)}</span>
                    <span class="metric-avg">${avg.toFixed(2)} ms</span>
                    <span class="metric-range">${min.toFixed(2)} - ${max.toFixed(2)} ms</span>
                </div>
            `;
        });
        
        html += '</div></div>';
        return html;
    }

    initializeCharts() {
        // Charts will be initialized when data is available
    }

    initializePerformanceCharts(metrics) {
        if (!metrics.length) return;
        
        const ctx = document.getElementById('performance-chart');
        if (!ctx) return;
        
        // Destroy existing chart if it exists
        if (this.performanceChart) {
            this.performanceChart.destroy();
        }
        
        const chartData = this.preparePerformanceChartData(metrics);
        
        this.performanceChart = new Chart(ctx, {
            type: 'line',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Response Time (ms)'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Time'
                        }
                    }
                }
            }
        });
    }

    preparePerformanceChartData(metrics) {
        const datasets = {};
        const labels = new Set();
        
        metrics.forEach(metric => {
            const type = metric.metric_type;
            if (!datasets[type]) {
                datasets[type] = {
                    label: this.formatMetricType(type),
                    data: [],
                    borderColor: this.getMetricColor(type),
                    backgroundColor: this.getMetricColor(type) + '20',
                    tension: 0.1
                };
            }
            
            const hour = new Date(metric.hour_bucket).toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit' 
            });
            labels.add(hour);
            
            datasets[type].data.push({
                x: hour,
                y: parseFloat(metric.metric_value)
            });
        });
        
        return {
            labels: Array.from(labels).sort(),
            datasets: Object.values(datasets)
        };
    }

    setupTabSwitching() {
        const tabButtons = document.querySelectorAll('.tab-btn');
        const tabPanes = document.querySelectorAll('.tab-pane');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const targetTab = button.dataset.tab;
                
                // Update button states
                tabButtons.forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');
                
                // Update pane visibility
                tabPanes.forEach(pane => {
                    pane.classList.remove('active');
                    if (pane.id === `${targetTab}-tab`) {
                        pane.classList.add('active');
                    }
                });
            });
        });
    }

    showModal(modalId, content) {
        let modal = document.getElementById(modalId);
        if (!modal) {
            modal = document.createElement('div');
            modal.id = modalId;
            modal.className = 'modal-overlay';
            modal.innerHTML = `
                <div class="modal">
                    <div class="modal-header">
                        <h3>Enhanced Monitoring Details</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        ${content}
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Setup close handlers
            modal.querySelector('.modal-close').addEventListener('click', () => {
                modal.remove();
            });
            
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        } else {
            modal.querySelector('.modal-body').innerHTML = content;
            modal.style.display = 'flex';
        }
    }

    // Utility methods
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    formatMetricType(type) {
        return type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    }

    getMetricColor(type) {
        const colors = {
            'dns_time': '#3b82f6',
            'connect_time': '#10b981',
            'ttfb': '#f59e0b',
            'total_time': '#ef4444',
            'download_size': '#8b5cf6',
            'upload_size': '#ec4899'
        };
        return colors[type] || '#6b7280';
    }

    getPerformanceStatus(value, threshold) {
        if (!value) return 'unknown';
        return value > threshold ? 'warning' : 'good';
    }

    getErrorSeverity(category) {
        const severities = {
            'timeout': 'high',
            'dns_error': 'high',
            'ssl_error': 'high',
            'connection_refused': 'high',
            'server_error': 'medium',
            'client_error': 'medium',
            'content_validation': 'low',
            'network_error': 'medium'
        };
        return severities[category] || 'low';
    }

    formatDate(dateString) {
        return new Date(dateString).toLocaleString();
    }
}

// Initialize enhanced monitoring when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.enhancedMonitoring = new EnhancedMonitoring();
});

// Add CSS styles for enhanced monitoring
const enhancedStyles = `
<style>
.enhanced-details-container {
    max-width: 1000px;
    margin: 0 auto;
}

.site-header {
    padding: 20px;
    border-bottom: 1px solid #e5e7eb;
    margin-bottom: 20px;
}

.site-header h3 {
    margin: 0 0 8px 0;
    font-size: 1.5rem;
}

.site-url {
    color: #6b7280;
    font-size: 0.9rem;
    margin-bottom: 12px;
}

.site-status {
    display: flex;
    align-items: center;
    gap: 12px;
}

.retry-info {
    font-size: 0.8rem;
    color: #f59e0b;
    background: #fef3c7;
    padding: 2px 8px;
    border-radius: 12px;
}

.details-tabs {
    display: flex;
    border-bottom: 1px solid #e5e7eb;
    margin-bottom: 20px;
}

.tab-btn {
    padding: 12px 24px;
    border: none;
    background: none;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: all 0.2s;
}

.tab-btn:hover {
    background: #f9fafb;
}

.tab-btn.active {
    border-bottom-color: #3b82f6;
    color: #3b82f6;
    font-weight: 500;
}

.tab-content {
    min-height: 400px;
}

.tab-pane {
    display: none;
}

.tab-pane.active {
    display: block;
}

.performance-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}

.metric-card {
    background: #f9fafb;
    padding: 16px;
    border-radius: 8px;
    border-left: 4px solid #e5e7eb;
    position: relative;
}

.metric-label {
    font-size: 0.8rem;
    color: #6b7280;
    margin-bottom: 4px;
}

.metric-value {
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 8px;
}

.metric-status {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
}

.metric-status.good { background: #10b981; }
.metric-status.warning { background: #f59e0b; }
.metric-status.unknown { background: #6b7280; }

.performance-chart-container {
    margin-bottom: 32px;
}

.ssl-chain {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.cert-card {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px;
}

.cert-card.good { border-left-color: #10b981; }
.cert-card.warning { border-left-color: #f59e0b; }
.cert-card.critical { border-left-color: #ef4444; }

.cert-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.cert-status {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
}

.cert-status.good { background: #d1fae5; color: #065f46; }
.cert-status.warning { background: #fef3c7; color: #92400e; }
.cert-status.critical { background: #fee2e2; color: #991b1b; }

.cert-row {
    display: flex;
    margin-bottom: 8px;
}

.cert-row .label {
    min-width: 120px;
    font-weight: 500;
    color: #374151;
}

.cert-issues {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #e5e7eb;
}

.issues-list {
    margin: 8px 0 0 0;
    padding-left: 20px;
    color: #ef4444;
}

.error-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}

.error-card {
    padding: 16px;
    border-radius: 8px;
    border-left: 4px solid;
}

.error-card.high { border-left-color: #ef4444; background: #fef2f2; }
.error-card.medium { border-left-color: #f59e0b; background: #fffbeb; }
.error-card.low { border-left-color: #3b82f6; background: #eff6ff; }

.error-category {
    font-weight: 600;
    margin-bottom: 4px;
}

.error-count {
    color: #6b7280;
    margin-bottom: 8px;
}

.error-timeframe {
    font-size: 0.8rem;
    color: #6b7280;
}

.connectivity-info {
    margin-bottom: 24px;
}

.connectivity-row {
    display: flex;
    margin-bottom: 8px;
}

.connectivity-row .label {
    min-width: 150px;
    font-weight: 500;
    color: #374151;
}

.security-headers {
    margin-bottom: 24px;
}

.headers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 8px;
}

.header-item {
    display: flex;
    padding: 8px;
    background: #f9fafb;
    border-radius: 4px;
}

.header-name {
    font-weight: 500;
    min-width: 200px;
    color: #374151;
}

.header-value {
    color: #6b7280;
    word-break: break-all;
}

.response-headers {
    margin-top: 24px;
}

.response-headers summary {
    cursor: pointer;
    padding: 8px;
    background: #f3f4f6;
    border-radius: 4px;
    font-weight: 500;
}

.headers-list {
    margin-top: 16px;
    max-height: 300px;
    overflow-y: auto;
}

.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal {
    background: white;
    border-radius: 8px;
    max-width: 90vw;
    max-height: 90vh;
    overflow: hidden;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #6b7280;
}

.modal-body {
    padding: 20px;
    max-height: calc(90vh - 80px);
    overflow-y: auto;
}

.aggregated-metrics {
    margin-top: 24px;
}

.metrics-table {
    background: #f9fafb;
    border-radius: 8px;
    padding: 16px;
}

.metric-row {
    display: flex;
    padding: 8px 0;
    border-bottom: 1px solid #e5e7eb;
}

.metric-row:last-child {
    border-bottom: none;
}

.metric-type {
    min-width: 150px;
    font-weight: 500;
}

.metric-avg {
    min-width: 100px;
    text-align: right;
    font-weight: 600;
}

.metric-range {
    color: #6b7280;
    text-align: right;
    flex: 1;
}
</style>
`;

document.head.insertAdjacentHTML('beforeend', enhancedStyles);
