(function ($) {
    'use strict';

    // Client-side debug function
    function debugLog(data, label) {
        if (!bemaAdmin || !bemaAdmin.debug || !bemaAdmin.debug.enabled) {
            return;
        }

        console.log(`${label || ''}: `, data);

        // Send to server for logging
        $.post(bemaAdmin.ajaxUrl, {
            action: 'bema_debug_log',
            nonce: bemaAdmin.nonce,
            data: data,
            label: label
        });
    }

    window.BemaSync = {
        currentSyncRequest: null,
        currentSyncId: null,
        statusInterval: null,
        init: function () {
            debugLog('Initializing BemaSync', 'SYNC_JS');
            this.bindEvents();
            this.initializeStatusCheck();
            this.initializeTabs();
        },

        // Add this new method
        initializeTabs: function () {
            // Tab switching functionality
            $('.nav-tab').on('click', function (e) {
                e.preventDefault();

                // Update tabs
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');

                // Update content
                const targetId = $(this).attr('href').substring(1);
                $('.tab-pane').removeClass('active');
                $(`#${targetId}`).addClass('active');

                // Save active tab to localStorage
                localStorage.setItem('bema_active_tab', targetId);
            });

            // Restore active tab on page load
            const savedTab = localStorage.getItem('bema_active_tab');
            if (savedTab) {
                $(`.nav-tab[href="#${savedTab}"]`).trigger('click');
            }
        },

        bindEvents: function () {
            const self = this;

            $('#start-sync').on('click', function () {
                self.startSync();
            });

            $('#stop-sync').on('click', function () {
                self.stopSync();
            });

            $('#retry-failed').on('click', function () {
                self.retryFailedJobs();
            });

            // Campaign selection handling
            $('.campaign-checkbox').on('change', function () {
                self.updateBulkActions();
            });
        },

        initializeStatusCheck: function () {
            if ($('#sync-status-display .status').hasClass('running')) {
                this.startStatusPolling();
            }
        },

        startStatusPolling: function () {
            if (this.statusInterval) {
                clearInterval(this.statusInterval);
            }

            this.statusInterval = setInterval(() => {
                this.checkSyncStatus();
            }, 5000); // Check every 5 seconds
        },

        stopStatusPolling: function () {
            if (this.statusInterval) {
                clearInterval(this.statusInterval);
            }
        },

        checkSyncStatus: function () {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bema_get_sync_status',
                    nonce: bemaAdmin.nonce
                },
                success: (response) => {
                    if (response.success) {
                        const status = response.data;
                        debugLog('Full sync status update:', status);

                        // Update main status display
                        this.updateStatusDisplay(status);

                        // Update campaign groups display
                        this.updateCampaignGroupsDisplay(status);

                        // Update performance metrics
                        this.updatePerformanceMetrics(status.performance);

                        // Continue polling if sync is active
                        if (status.status === 'running') {
                            this.ensurePolling();
                        } else {
                            this.stopStatusPolling();
                        }
                    }
                }
            });
        },

        updateCampaignGroupsDisplay: function (status) {
            const campaignDetails = status.campaign_details;
            if (!campaignDetails) return;

            $('.campaign-section').each(function () {
                const $section = $(this);
                const campaignName = $section.find('.campaign-name').text().trim();

                if (campaignName === status.current_campaign) {
                    // Update campaign progress
                    $section.find('.campaign-progress').text(
                        `Campaign ${campaignDetails.campaign_number} of ${campaignDetails.total_campaigns}`
                    );

                    // Update group status
                    $section.find('.group-item').each(function () {
                        const $group = $(this);
                        const groupType = $group.data('group-type');

                        if (groupType === campaignDetails.current_group) {
                            $group.addClass('active');

                            // Update page progress
                            $group.find('.current-operation').html(
                                `<span class="operation-status">
                                    Processing page ${campaignDetails.current_page} 
                                    (${campaignDetails.total_pages_processed} pages processed)
                                </span>`
                            );

                            // Update group statistics
                            if (campaignDetails.group_progress[groupType]) {
                                const stats = campaignDetails.group_progress[groupType];
                                $group.find('.processed-count').text(stats.processed);
                                $group.find('.total-count').text(stats.total);
                            }
                        } else {
                            $group.removeClass('active');
                        }
                    });
                } else {
                    $section.find('.group-item').removeClass('active');
                }
            });
        },

        updatePerformanceMetrics: function (performance) {
            if (!performance) return;

            $('.memory-usage').text(performance.memory_usage);
            $('.peak-memory').text(performance.peak_memory);

            if (performance.start_time) {
                const duration = this.formatDuration(performance.duration);
                $('.sync-duration').text(duration);
            }
        },

        updateGroupDisplay: function (data) {
            // Update active campaign and group
            $('.campaign-section').each(function () {
                const campaignName = $(this).find('.campaign-name').text().trim();
                if (campaignName === data.current_campaign) {
                    $(this).find('.status-badge').show();

                    // Update group stats
                    $(this).find('.group-item').each(function () {
                        const groupType = $(this).find('.group-type').text().trim().toLowerCase();
                        if (groupType === data.current_group) {
                            $(this).addClass('active');

                            // Update operation status if available
                            if (data.current_page && data.total_pages) {
                                $(this).find('.current-operation').html(
                                    `<span class="operation-status">Processing page ${data.current_page} of ${data.total_pages}</span>`
                                );
                            }
                        } else {
                            $(this).removeClass('active');
                        }
                    });
                } else {
                    $(this).find('.status-badge').hide();
                    $(this).find('.group-item').removeClass('active');
                }
            });
        },

        updateStatusDisplay: function (data) {
            debugLog('Updating status display:', data);

            const statusDisplay = $('#sync-status-display');
            const progressBar = $('.progress-bar .progress');
            const progressText = $('.progress-text');
            const currentCampaign = $('.current-campaign');
            const currentGroup = $('.current-group');
            const memoryUsage = $('.memory-usage');

            // Update status badge
            statusDisplay.attr('data-status', data.status);
            $('.status-badge')
                .text(data.status.charAt(0).toUpperCase() + data.status.slice(1))
                .attr('class', `value status-badge status-${data.status}`);

            // Update progress
            if (data.total > 0) {
                const progress = Math.round((data.processed / data.total) * 100);
                progressBar.css('width', `${progress}%`);
                progressText.text(`${data.processed} of ${data.total} processed (${progress}%)`);
            }

            // Update current campaign and group
            if (data.current_campaign) {
                currentCampaign.text(data.current_campaign);
            }
            if (data.current_group) {
                currentGroup.text(data.current_group);
            }

            // Update memory usage
            if (data.memory_usage) {
                let memoryText = data.memory_usage;
                if (data.peak_memory) {
                    memoryText += ` (Peak: ${data.peak_memory})`;
                }
                memoryUsage.text(memoryText);
            }

            // Update debug info if present
            if ($('.debug-info').length && data.debug_info) {
                $('.debug-details').text(JSON.stringify(data.debug_info, null, 2));
            }

            // Handle status-specific UI updates
            switch (data.status) {
                case 'running':
                    $('#start-sync').hide();
                    $('#stop-sync').show().prop('disabled', false);
                    break;
                case 'stopped':
                case 'completed':
                case 'failed':
                    $('#stop-sync').hide();
                    $('#start-sync').show().prop('disabled', false);
                    this.stopStatusPolling();

                    // Show appropriate notification
                    const messages = {
                        'completed': 'Sync completed successfully',
                        'stopped': 'Sync stopped by user',
                        'failed': data.error || 'Sync failed'
                    };
                    const types = {
                        'completed': 'success',
                        'stopped': 'warning',
                        'failed': 'error'
                    };
                    BemaAdmin.showNotification(messages[data.status], types[data.status]);
                    break;
            }
        },

        getSelectedCampaigns: function () {
            const campaign = $('#campaign-select').val();
            debugLog('Selected campaign from dropdown:', campaign);

            if (!campaign) {
                return [];
            }

            // Format campaign data properly
            const campaignData = {
                name: campaign,
                field: campaign + '_PURCHASED',
                tag: '$' + campaign.toLowerCase() + '_purchased'
            };

            // Validate campaign format
            if (!bemaAdmin.validCampaigns.includes(campaignData.name)) {
                BemaAdmin.showNotification('Invalid campaign format', 'error');
                return [];
            }

            debugLog('Formatted campaign data:', campaignData);
            return [campaignData];
        },

        startSync: function () {
            const self = this;
            const selectedCampaigns = this.getSelectedCampaigns();

            debugLog('Selected campaigns:', selectedCampaigns);

            if (selectedCampaigns.length === 0) {
                BemaAdmin.showNotification('Please select at least one campaign', 'error');
                return;
            }

            // Disable start button and show loading state
            $('#start-sync').prop('disabled', true);
            $('#start-sync-form').hide(); // Hide the entire form
            $('#stop-sync').show().prop('disabled', false); // Show stop button immediately

            const postData = {
                action: 'bema_start_sync',
                nonce: bemaAdmin.nonce,
                campaigns: JSON.stringify(selectedCampaigns)
            };

            debugLog('Sending request with data:', postData);

            // Store the current sync request
            this.currentSyncRequest = $.ajax({
                url: bemaAdmin.ajaxUrl,
                type: 'POST',
                data: postData,
                success: function (response) {
                    debugLog('Response received:', response);
                    if (response.success) {
                        // Start polling for status updates
                        self.startStatusPolling();
                        BemaAdmin.showNotification(response.data.message, 'success');

                        // Store the sync ID if provided
                        if (response.data.sync_id) {
                            self.currentSyncId = response.data.sync_id;
                        }
                    } else {
                        BemaAdmin.showNotification(response.data.message || 'Sync failed to start', 'error');
                        $('#start-sync-form').show();
                        $('#stop-sync').hide();
                        $('#start-sync').prop('disabled', false);
                    }
                },
                error: function (xhr, status, error) {
                    debugLog('Ajax error:', { xhr, status, error });
                    BemaAdmin.showNotification('Failed to start sync: ' + error, 'error');
                    $('#start-sync-form').show();
                    $('#stop-sync').hide();
                    $('#start-sync').prop('disabled', false);
                }
            });
        },

        // Enhanced stop sync functionality
        stopSync: function () {
            const self = this;

            if (!confirm(bemaAdmin.strings.confirmStop)) {
                return;
            }

            debugLog('Stopping sync process', 'SYNC_JS');

            // Disable stop button and show loading state
            const $stopButton = $('#stop-sync');
            $stopButton.prop('disabled', true).text('Stopping...');

            // Clear any existing intervals first
            this.stopStatusPolling();

            // Set a timeout to prevent hanging
            const stopTimeout = setTimeout(() => {
                BemaAdmin.showNotification(
                    'Sync stop taking longer than expected. Refreshing page...',
                    'warning'
                );
                window.location.reload();
            }, 30000);

            $.ajax({
                url: bemaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bema_stop_sync',
                    nonce: bemaAdmin.nonce
                },
                success: (response) => {
                    clearTimeout(stopTimeout);
                    debugLog('Stop sync response:', response);

                    if (response.success && response.data) {
                        BemaAdmin.showNotification(
                            response.data.message || 'Sync stopped successfully',
                            'success'
                        );

                        // Update display
                        this.updateStatusDisplay({
                            status: 'stopped',
                            processed: response.data.processed || 0,
                            total: response.data.total || 0,
                            memory_usage: response.data.memory_usage || ''
                        });

                        // Reset UI state
                        $('#start-sync-form').show();
                        $stopButton
                            .hide()
                            .prop('disabled', false)
                            .text('Stop Sync');

                        // Force refresh after a short delay
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    } else {
                        this.handleStopError(response.data?.message);
                    }
                },
                error: (xhr, status, error) => {
                    clearTimeout(stopTimeout);
                    debugLog('Stop sync ajax error:', { xhr, status, error });
                    this.handleStopError(error);
                }
            });
        },

        handleStopError: function (errorMsg = null) {
            const message = errorMsg || 'Failed to stop sync';
            BemaAdmin.showNotification(message, 'error');

            $('#stop-sync')
                .prop('disabled', false)
                .text('Stop Sync');

            // Force refresh after error
            setTimeout(() => {
                window.location.reload();
            }, 3000);
        },

        retryFailedJobs: function () {
            if (!confirm(bemaAdmin.strings.confirmRetry)) {
                return;
            }

            $.ajax({
                url: bemaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bema_retry_failed',
                    nonce: bemaAdmin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        BemaAdmin.showNotification('Failed jobs queued for retry', 'success');
                        location.reload();
                    } else {
                        BemaAdmin.showNotification(response.data.message, 'error');
                    }
                }
            });
        },

        updateBulkActions: function () {
            const count = $('.campaign-checkbox:checked').length;
            $('#bulk-action-button').prop('disabled', count === 0);
        }
    };

    $(document).ready(function () {
        console.log('Document ready');
        console.log('bemaAdmin object:', bemaAdmin);
        console.log('jQuery version:', $.fn.jquery);

        if (window.BemaSync) {
            console.log('Initializing BemaSync');
            window.BemaSync.init();
        } else {
            console.error('BemaSync not found');
        }
    });
})(jQuery);
