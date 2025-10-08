window.BemaLogs = {
    init: function () {
        this.bindEvents();
        this.initializeFilters();
        this.initializeAutoRefresh();
    },

    bindEvents: function () {
        const self = this;
        const $ = jQuery;

        // View log details
        $(document).on('click', '.view-log-details', function () {
            const id = $(this).data('id');
            self.loadLogDetails(id);
        });

        // Export logs
        $('#export-logs').on('click', function () {
            self.exportLogs();
        });

        // Clear logs
        $('#clear-logs').on('click', function () {
            self.clearLogs();
        });

        // Filter form submission
        $('.log-filters').on('submit', function (e) {
            e.preventDefault();
            self.filterLogs($(this));
        });

        // Real-time filter changes
        $('#filter-campaign, #filter-status, #filter-date').on('change', function () {
            $('.log-filters').submit();
        });

        // Search input debouncing
        let searchTimeout;
        $('input[name="search"]').on('input', function () {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                $('.log-filters').submit();
            }, 500);
        });
    },

    initializeFilters: function () {
        const urlParams = new URLSearchParams(window.location.search);

        // Set initial filter values from URL
        ['status', 'campaign', 'date'].forEach(filter => {
            const value = urlParams.get(`filter_${filter}`);
            if (value) {
                $(`#filter-${filter}`).val(value);
            }
        });

        if (urlParams.has('search')) {
            $('input[name="search"]').val(urlParams.get('search'));
        }
    },

    initializeAutoRefresh: function () {
        const self = this;
        let refreshInterval;

        // Start auto-refresh if sync is running
        if ($('.status-badge.status-running').length) {
            refreshInterval = setInterval(() => {
                self.refreshLogData();
            }, 5000); // Refresh every 5 seconds
        }

        // Stop auto-refresh when sync completes
        $(document).on('syncStatusChange', function (e, status) {
            if (status !== 'running' && refreshInterval) {
                clearInterval(refreshInterval);
            }
        });
    },

    refreshLogData: function () {
        const self = this;
        jQuery.ajax({
            url: bemaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'bema_get_sync_logs',
                nonce: bemaAdmin.nonce,
                current_filters: this.getCurrentFilters()
            },
            success: function (response) {
                if (response.success) {
                    self.updateLogTable(response.data.logs);
                    self.updateSyncStatus(response.data.sync_status);
                }
            },
            error: function (xhr, status, error) {
                console.log('Error refreshing log data:', error);
            }
        });
    },

    getCurrentFilters: function () {
        const filters = {};
        $('.log-filters select, .log-filters input[type="search"]').each(function () {
            const $el = $(this);
            if ($el.val()) {
                filters[$el.attr('name')] = $el.val();
            }
        });
        return filters;
    },

    loadLogDetails: function (id) {
        const self = this;
        jQuery.ajax({
            url: bemaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'bema_get_log_details',
                nonce: bemaAdmin.nonce,
                id: id
            },
            success: function (response) {
                if (response.success) {
                    self.displayLogDetails(response.data);
                } else {
                    BemaAdmin.showNotification(response.data.message || 'Error loading log details', 'error');
                }
            },
            error: function (xhr, status, error) {
                BemaAdmin.showNotification('Failed to load log details: ' + error, 'error');
            }
        });
    },

    displayLogDetails: function (data) {
        let html = '<div class="log-details">';

        // Main header section
        html += `
            <div class="log-header">
                <div class="status-section">
                    <span class="status-badge status-${data.status}">${this.formatLabel(data.status)}</span>
                    <span class="timestamp">${this.formatDate(data.created_at)}</span>
                </div>
            </div>`;

        // Campaign section
        if (data.campaign) {
            html += `
                <div class="section campaign-section">
                    <h3>Campaign</h3>
                    <div class="campaign-info">
                        <span class="campaign-badge">${data.campaign}</span>
                        ${data.campaign_progress ? `
                            <div class="campaign-progress">
                                <div class="progress-bar">
                                    <div class="progress" style="width: ${data.campaign_progress.percentage}%"></div>
                                </div>
                                <span class="progress-text">
                                    ${data.campaign_progress.processed} / ${data.campaign_progress.total}
                                </span>
                            </div>
                        ` : ''}
                    </div>
                </div>`;
        }

        // Performance metrics section
        if (data.memory_usage || data.duration) {
            html += `
                <div class="section performance-section">
                    <h3>Performance Metrics</h3>
                    <div class="metrics-grid">
                        ${data.memory_usage ? `
                            <div class="metric">
                                <span class="label">Memory Usage:</span>
                                <span class="value">${data.memory_usage}</span>
                            </div>
                        ` : ''}
                        ${data.peak_memory ? `
                            <div class="metric">
                                <span class="label">Peak Memory:</span>
                                <span class="value">${data.peak_memory}</span>
                            </div>
                        ` : ''}
                        ${data.duration ? `
                            <div class="metric">
                                <span class="label">Duration:</span>
                                <span class="value">${this.formatDuration(data.duration)}</span>
                            </div>
                        ` : ''}
                    </div>
                </div>`;
        }

        // Message and details section
        html += `
            <div class="section message-section">
                <h3>Message</h3>
                <div class="message">${data.message}</div>
                ${data.details ? `
                    <div class="details">
                        <pre>${this.formatJson(data.details)}</pre>
                    </div>
                ` : ''}
            </div>`;

        // Error section if applicable
        if (data.error) {
            html += `
                <div class="section error-section">
                    <h3>Error Details</h3>
                    <div class="error-message">${data.error}</div>
                    ${data.stack_trace ? `
                        <div class="stack-trace">
                            <pre>${data.stack_trace}</pre>
                        </div>
                    ` : ''}
                </div>`;
        }

        html += '</div>';

        BemaAdmin.showModal('Log Details', html);
    },

    updateSyncStatus: function (status) {
        if (!status) return;

        const $statusSection = $('.sync-status-summary');

        // Update status badge
        $statusSection.find('.status-badge')
            .removeClass()
            .addClass(`status-badge status-${status.status}`)
            .text(this.formatLabel(status.status));

        // Update progress
        if (status.total > 0) {
            const percentage = ((status.processed / status.total) * 100).toFixed(1);
            $statusSection.find('.progress')
                .css('width', `${percentage}%`);
            $statusSection.find('.progress-text')
                .text(`${status.processed} of ${status.total} processed (${percentage}%)`);
        }

        // Update memory usage
        if (status.memory_usage) {
            $statusSection.find('.memory-usage .value')
                .text(status.memory_usage);
        }
    },

    filterLogs: function ($form) {
        const self = this;
        const data = $form.serialize();

        $form.find('button, select, input').prop('disabled', true);

        jQuery.ajax({
            url: bemaAdmin.ajaxUrl,
            type: 'POST',
            data: data + '&action=bema_filter_logs&nonce=' + bemaAdmin.nonce,
            success: function (response) {
                if (response.success) {
                    self.updateLogTable(response.data.logs);
                    self.updatePagination(response.data.pagination);

                    // Update URL with new filters
                    const newUrl = new URL(window.location);
                    self.getCurrentFilters().forEach((value, key) => {
                        newUrl.searchParams.set(key, value);
                    });
                    window.history.pushState({}, '', newUrl);
                } else {
                    BemaAdmin.showNotification(response.data.message || 'Error filtering logs', 'error');
                }
            },
            error: function (xhr, status, error) {
                BemaAdmin.showNotification('Failed to filter logs: ' + error, 'error');
            },
            complete: function () {
                $form.find('button, select, input').prop('disabled', false);
            }
        });
    },

    updateLogTable: function (logs) {
        const $tbody = jQuery('.wp-list-table tbody');
        if (!logs.length) {
            $tbody.html('<tr><td colspan="6" class="no-items">No logs found.</td></tr>');
            return;
        }

        let html = '';
        logs.forEach(log => {
            html += this.generateLogRow(log);
        });
        $tbody.html(html);
    },

    generateLogRow: function (log) {
        return `
            <tr>
                <td class="column-date">
                    ${this.formatDate(log.created_at)}
                </td>
                <td class="column-campaign">
                    ${log.campaign ? `<span class="campaign-badge">${log.campaign}</span>` : ''}
                </td>
                <td class="column-status">
                    <span class="status-badge status-${log.status}">${this.formatLabel(log.status)}</span>
                </td>
                <td class="column-message">
                    ${log.message}
                    ${log.details ? `
                        <br><small class="details-preview">${this.formatDetailsPreview(log.details)}</small>
                    ` : ''}
                </td>
                <td class="column-memory">
                    ${log.memory_usage ? `<span class="memory-usage">${log.memory_usage}</span>` : ''}
                </td>
                <td class="column-actions">
                    ${log.details ? `
                        <button type="button" class="button button-small view-log-details" data-id="${log.id}">
                            View Details
                        </button>
                    ` : ''}
                </td>
            </tr>`;
    },

    exportLogs: function () {
        const filters = this.getCurrentFilters();

        jQuery.ajax({
            url: bemaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'bema_export_logs',
                nonce: bemaAdmin.nonce,
                filters: filters
            },
            success: function (response) {
                if (response.success) {
                    // Create and trigger download
                    const blob = new Blob([response.data], { type: 'text/csv' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.style.display = 'none';
                    a.href = url;
                    a.download = `sync_logs_${new Date().toISOString().split('T')[0]}.csv`;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                } else {
                    BemaAdmin.showNotification(response.data.message || 'Error exporting logs', 'error');
                }
            },
            error: function (xhr, status, error) {
                BemaAdmin.showNotification('Failed to export logs: ' + error, 'error');
            }
        });
    },

    clearLogs: function () {
        if (!confirm('Are you sure you want to clear all logs? This action cannot be undone.')) {
            return;
        }

        jQuery.ajax({
            url: bemaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'bema_clear_logs',
                nonce: bemaAdmin.nonce
            },
            success: function (response) {
                if (response.success) {
                    BemaAdmin.showNotification('Logs cleared successfully', 'success');
                    location.reload();
                } else {
                    BemaAdmin.showNotification(response.data.message || 'Error clearing logs', 'error');
                }
            },
            error: function (xhr, status, error) {
                BemaAdmin.showNotification('Failed to clear logs: ' + error, 'error');
            }
        });
    },

    // Utility Methods
    formatLabel: function (str) {
        return str.charAt(0).toUpperCase() +
            str.slice(1).toLowerCase().replace(/_/g, ' ');
    },

    formatDate: function (date) {
        return new Date(date).toLocaleString();
    },

    formatDuration: function (seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remainingSeconds = Math.floor(seconds % 60);

        const parts = [];
        if (hours > 0) parts.push(`${hours}h`);
        if (minutes > 0) parts.push(`${minutes}m`);
        if (remainingSeconds > 0 || parts.length === 0) parts.push(`${remainingSeconds}s`);

        return parts.join(' ');
    },

    formatJson: function (data) {
        try {
            const parsed = typeof data === 'string' ? JSON.parse(data) : data;
            return JSON.stringify(parsed, null, 2);
        } catch (e) {
            return data;
        }
    },

    formatDetailsPreview: function (details) {
        try {
            const parsed = typeof details === 'string' ? JSON.parse(details) : details;
            const preview = JSON.stringify(parsed);
            return preview.length > 100 ? preview.substring(0, 97) + '...' : preview;
        } catch (e) {
            return typeof details === 'string' && details.length > 100
                ? details.substring(0, 97) + '...'
                : details;
        }
    }
};

// Initialize on document ready
jQuery(document).ready(function () {
    BemaLogs.init();
});
