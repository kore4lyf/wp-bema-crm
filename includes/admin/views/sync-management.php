<?php

namespace Bema\Admin\Views;

use Exception;
// Reuse Bema\Bema_CRM_Logger;

if (!defined('ABSPATH')) {
    exit;
}

if (!isset($admin) || !($admin instanceof \Bema\Admin\Bema_Admin_Interface)) {
    wp_die('Invalid admin interface initialization');
}

if (!$admin->has_sync_capability()) {
?>
    <div class="wrap">
        <h1><?php _e('Sync Management', 'bema-crm'); ?></h1>
        <div class="notice notice-info">
            <p><?php _e('Please configure Easy Digital Downloads Pro API credentials in settings to enable sync features.', 'bema-crm'); ?>
            </p>
            <p><a href="<?php echo admin_url('admin.php?page=bema-settings'); ?>"
                    class="button button-primary"><?php _e('Configure Settings', 'bema-crm'); ?></a></p>
        </div>
    </div>
<?php
    return;
}

try {
    global $wpdb;
    $admin = !isset($admin) ? null : $admin;

    if (!isset($sync_status) || !is_array($sync_status)) {
        throw new Exception('Invalid sync status data');
    }

    $current_status = $sync_status['status'] ?? 'idle';
    $processed = abs(intval($sync_status['processed'] ?? 0));
    $total = abs(intval($sync_status['total'] ?? 0));
    $progress = $total > 0 ? min(100, round(($processed / $total) * 100)) : 0;

    $required_tables = ['bemacrmmeta', 'subscribers', 'sync_logs'];
    foreach ($required_tables as $table) {
        if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->prefix . $table))) {
            throw new Exception("Required table {$wpdb->prefix}{$table} is missing");
        }
    }

    $valid_campaigns = [];
    if ($admin && method_exists($admin, 'get_campaign_manager')) {
        $campaign_manager = $admin->get_campaign_manager();
        if ($campaign_manager) {
            $valid_campaigns = $campaign_manager->get_all_valid_campaign();
        $admin->logger->debug('Sync management error', 'error', [
            'error' => $e->getMessage(),
            'file' => __FILE__,
            'line' => __LINE__
        ]);
    }

    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        esc_html__('Error loading sync management page: ', 'bema-crm') . esc_html($e->getMessage())
    );
    return;
}
}
?>

<div class="wrap bema-sync-manager">
    <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php if (isset($_GET['sync_error'])): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html(urldecode($_GET['sync_error'])); ?></p>
        </div>
    <?php endif; ?>

    <!-- Sync Status Card -->
    <div class="sync-status-card">
        <h2><?php _e('Sync Status', 'bema-crm'); ?></h2>
        <div class="sync-status" id="sync-status-display" data-status="<?php echo esc_attr($current_status); ?>">
            <div class="status-grid">
                <!-- Status Badge -->
                <div class="status-item">
                    <span class="label"><?php _e('Status:', 'bema-crm'); ?></span>
                    <span class="value status-badge status-<?php echo esc_attr($current_status); ?>">
                        <?php echo esc_html(ucfirst($current_status)); ?>
                    </span>
                </div>

                <!-- Progress Bar -->
                <div class="status-item">
                    <span class="label"><?php _e('Progress:', 'bema-crm'); ?></span>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress" style="width: <?php echo esc_attr($progress); ?>%"></div>
                        </div>
                        <span class="progress-text">
                            <?php
                            if ($total > 0) {
                                printf(
                                    __('%d of %d processed (%d%%)', 'bema-crm'),
                                    $processed,
                                    $total,
                                    $progress
                                );
                            } else {
                                _e('Preparing...', 'bema-crm');
                            }
                            ?>
                        </span>
                    </div>
                </div>

                <!-- Current Campaign -->
                <?php if (isset($sync_status['current_campaign'])): ?>
                    <div class="status-item">
                        <span class="label"><?php _e('Current Campaign:', 'bema-crm'); ?></span>
                        <span class="value current-campaign">
                            <?php echo esc_html($sync_status['current_campaign']); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <!-- Add the new performance metrics -->
                <div class="status-item performance-metrics">
                    <span class="label"><?php _e('Performance:', 'bema-crm'); ?></span>
                    <div class="metrics-grid">
                        <div class="metric">
                            <span class="metric-label"><?php _e('Memory:', 'bema-crm'); ?></span>
                            <span class="memory-usage"><?php echo esc_html($sync_status['memory_usage'] ?? '0 MB'); ?></span>
                        </div>
                        <div class="metric">
                            <span class="metric-label"><?php _e('Peak:', 'bema-crm'); ?></span>
                            <span class="peak-memory"><?php echo esc_html($sync_status['peak_memory'] ?? '0 MB'); ?></span>
                        </div>
                        <div class="metric">
                            <span class="metric-label"><?php _e('Duration:', 'bema-crm'); ?></span>
                            <span class="sync-duration">
                                <?php
                                if (isset($sync_status['start_time'])) {
                                    echo esc_html(human_time_diff($sync_status['start_time'], time()));
                                } else {
                                    echo '-';
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Current Group -->
                <?php if (isset($sync_status['current_group'])): ?>
                    <div class="status-item">
                        <span class="label"><?php _e('Current Group:', 'bema-crm'); ?></span>
                        <span class="value current-group">
                            <?php echo esc_html($sync_status['current_group']); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <!-- Memory Usage -->
                <?php if (isset($sync_status['memory_usage'])): ?>
                    <div class="status-item">
                        <span class="label"><?php _e('Memory Usage:', 'bema-crm'); ?></span>
                        <span class="value memory-usage">
                            <?php echo esc_html($sync_status['memory_usage']); ?>
                            <?php if (isset($sync_status['peak_memory'])): ?>
                                (Peak: <?php echo esc_html($sync_status['peak_memory']); ?>)
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add this after the sync status card -->
    <div class="nav-tab-wrapper">
        <a href="#sync-status" class="nav-tab nav-tab-active" data-tab="sync-status">
            <?php _e('Sync Status', 'bema-crm'); ?>
        </a>
        <a href="#campaign-groups" class="nav-tab" data-tab="campaign-groups">
            <?php _e('Campaign Groups', 'bema-crm'); ?>
        </a>
        <?php if (!empty($failed_jobs)): ?>
            <a href="#failed-jobs" class="nav-tab" data-tab="failed-jobs">
                <?php _e('Failed Jobs', 'bema-crm'); ?>
            </a>
        <?php endif; ?>
    </div>

    <!-- Wrap existing content in tab panels -->
    <div class="tab-content">
        <!-- Sync Status tab -->
        <div id="sync-status" class="tab-pane active">
            <!-- Move existing sync controls and sync form here -->
            <div class="sync-controls">
                <form method="post" class="sync-form" id="start-sync-form">
                    <?php wp_nonce_field('bema_admin_nonce'); ?>
                    <div class="campaign-selector">
                        <label for="campaign-select"><?php _e('Select Campaign:', 'bema-crm'); ?></label>
                        <select name="campaign" id="campaign-select" class="campaign-select">
                            <option value=""><?php _e('Select Campaign', 'bema-crm'); ?></option>
                            <?php foreach ($valid_campaigns as $campaign): ?>
                                <option value="<?php echo esc_attr($campaign); ?>">
                                    <?php echo esc_html($campaign); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="button-group">
                        <button type="button" class="button button-primary" id="start-sync">
                            <?php _e('Start Sync', 'bema-crm'); ?>
                        </button>
                    </div>
                </form>
                <div class="button-group">
                    <button type="button" class="button button-secondary" id="stop-sync" style="display: none;">
                        <?php _e('Stop Sync', 'bema-crm'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Campaign Groups tab -->
        <div id="campaign-groups" class="tab-pane">
            <?php
            if ($campaign_manager) {
                $groups = [];
                foreach ($valid_campaigns as $campaign) {
                    $campaign_groups = $campaign_manager->get_campaign_groups($campaign);
                    if ($campaign_groups) {
                        $groups[$campaign] = $campaign_groups;
                    }
                }
                require_once BEMA_PATH . 'includes/admin/views/campaign-groups.php';
            }
            ?>
        </div>

        <!-- Failed Jobs tab -->
        <?php if (!empty($failed_jobs)): ?>
            <div id="failed-jobs" class="tab-pane">
                <div class="failed-jobs-section">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col"><?php _e('Campaign', 'bema-crm'); ?></th>
                                <th scope="col"><?php _e('Error', 'bema-crm'); ?></th>
                                <th scope="col"><?php _e('Retries', 'bema-crm'); ?></th>
                                <th scope="col"><?php _e('Last Attempt', 'bema-crm'); ?></th>
                                <th scope="col"><?php _e('Actions', 'bema-crm'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($failed_jobs as $campaign => $job): ?>
                                <tr>
                                    <td><?php echo esc_html($campaign); ?></td>
                                    <td class="error-message">
                                        <?php echo esc_html($job['last_error']); ?>
                                        <?php if (strlen($job['last_error']) > 100): ?>
                                            <button type="button" class="button button-small show-full-error">
                                                <?php _e('Show More', 'bema-crm'); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($job['retries']); ?>/<?php echo esc_html($max_retries); ?></td>
                                    <td>
                                        <?php echo esc_html(
                                            human_time_diff(
                                                $job['last_attempt'],
                                                time()
                                            ) . ' ' . __('ago', 'bema-crm')
                                        ); ?>
                                    </td>
                                    <td>
                                        <form method="post" class="retry-form" style="display: inline;">
                                            <?php wp_nonce_field('bema_sync_action'); ?>
                                            <input type="hidden" name="sync_action" value="retry">
                                            <input type="hidden" name="campaign" value="<?php echo esc_attr($campaign); ?>">
                                            <button type="submit" class="button button-small">
                                                <?php _e('Retry', 'bema-crm'); ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Templates for dynamic content -->
<script type="text/template" id="error-modal-template">
    <div class="error-modal">
        <div class="error-modal-content">
            <span class="close">&times;</span>
            <h2><?php _e('Error Details', 'bema-crm'); ?></h2>
            <pre class="error-details"></pre>
        </div>
    </div>
</script>

<script>
    jQuery(document).ready(function($) {
        // Status polling interval
        let statusCheckInterval;

        // Status display update function
        function updateStatusDisplay(data) {
            const statusDisplay = $('#sync-status-display');
            const progressBar = $('.progress-bar .progress');
            const progressText = $('.progress-text');
            const currentCampaign = $('.current-campaign');
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
                progressText.text(`${data.processed} of ${data.total} subscribers (${progress}%)`);
            } else {
                progressText.text('<?php _e("Stopped", "bema-crm"); ?>');
                progressBar.css('width', '0%');
            }

            // Update current campaign
            if (data.current_campaign) {
                currentCampaign.text(data.current_campaign);
            } else {
                currentCampaign.text('');
            }

            // Update memory usage
            if (data.memory_usage) {
                memoryUsage.text(data.memory_usage);
            }

            // Handle special statuses
            switch (data.status) {
                case 'stopped':
                    $('#start-sync').show().prop('disabled', false);
                    $('#stop-sync').hide();
                    break;
                case 'running':
                    $('#start-sync').hide();
                    $('#stop-sync').show().prop('disabled', false);
                    break;
            }
        }

        // Status check function
        const refreshStatus = () => {
            if ($('#sync-status-display').data('status') === 'running') {
                $.post(ajaxurl, {
                    action: 'bema_get_sync_status',
                    nonce: '<?php echo wp_create_nonce("bema_sync_status"); ?>'
                }, function(response) {
                    if (response.success) {
                        updateStatusDisplay(response.data);
                    }
                });
            }
        };

        // Start polling if sync is running
        if ($('#sync-status-display').data('status') === 'running') {
            statusCheckInterval = setInterval(refreshStatus, 5000);
        }

        // Stop sync handler
        $('#stop-sync').on('click', function() {
            if (!confirm('<?php echo esc_js(__("Are you sure you want to stop the sync process?", "bema-crm")); ?>')) {
                return;
            }

            // Disable the stop button immediately and show loading state
            const $stopButton = $(this);
            $stopButton.prop('disabled', true).text('<?php _e("Stopping...", "bema-crm"); ?>');

            // Clear any existing intervals first
            if (statusCheckInterval) {
                clearInterval(statusCheckInterval);
                statusCheckInterval = null;
            }

            // Set a timeout to prevent hanging
            const stopTimeout = setTimeout(() => {
                BemaAdmin.showNotification(
                    '<?php _e("Sync stop taking longer than expected. Refreshing page...", "bema-crm"); ?>',
                    'warning'
                );
                window.location.reload();
            }, 30000);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bema_stop_sync',
                    nonce: bemaAdmin.nonce
                },
                success: function(response) {
                    clearTimeout(stopTimeout);

                    if (response.success && response.data) {
                        BemaAdmin.showNotification(response.data.message || '<?php _e("Sync stopped successfully", "bema-crm"); ?>', 'success');

                        // Update display
                        updateStatusDisplay({
                            status: 'stopped',
                            processed: response.data.processed || 0,
                            total: response.data.total || 0,
                            memory_usage: response.data.memory_usage || ''
                        });

                        // Reset UI state
                        $('#start-sync-form').show();
                        $stopButton.hide().prop('disabled', false).text('<?php _e("Stop Sync", "bema-crm"); ?>');

                        // Force refresh after a short delay
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    } else {
                        handleStopError(response.data?.message);
                    }
                },
                error: function(xhr, status, error) {
                    clearTimeout(stopTimeout);
                    handleStopError(error);
                }
            });

            function handleStopError(errorMsg = null) {
                BemaAdmin.showNotification(
                    errorMsg || '<?php _e("Failed to stop sync", "bema-crm"); ?>',
                    'error'
                );
                $stopButton.prop('disabled', false).text('<?php _e("Stop Sync", "bema-crm"); ?>');

                // Force refresh after error
                setTimeout(() => {
                    window.location.reload();
                }, 3000);
            }

            // Ensure page refresh if everything else fails
            setTimeout(() => {
                if ($stopButton.prop('disabled')) {
                    window.location.reload();
                }
            }, 35000);
        });

        // Start sync handler
        $('#start-sync').on('click', function(e) {
            e.preventDefault();
            const selectedCampaign = $('#campaign-select').val();

            if (!selectedCampaign) {
                BemaAdmin.showNotification('<?php _e("Please select at least one campaign", "bema-crm"); ?>', 'error');
                return;
            }

            // Prepare campaign data
            const campaignData = {
                name: selectedCampaign,
                field: selectedCampaign + '_PURCHASED',
                tag: '$' + selectedCampaign.toLowerCase() + '_purchased'
            };

            // Disable start button and show loading state
            $(this).prop('disabled', true);
            $('#start-sync-form').hide();
            $('#stop-sync').show().prop('disabled', false);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bema_start_sync',
                    nonce: bemaAdmin.nonce,
                    campaigns: JSON.stringify([campaignData])
                },
                success: function(response) {
                    if (response.success) {
                        BemaAdmin.showNotification(response.data.message, 'success');

                        // Start status polling
                        statusCheckInterval = setInterval(refreshStatus, 5000);

                        // Update initial status
                        updateStatusDisplay({
                            status: 'running',
                            processed: 0,
                            total: 0,
                            current_campaign: selectedCampaign
                        });
                    } else {
                        handleStartError(response.data?.message);
                    }
                },
                error: function(xhr, status, error) {
                    handleStartError(error);
                }
            });

            function handleStartError(errorMsg = null) {
                BemaAdmin.showNotification(
                    errorMsg || '<?php _e("Failed to start sync", "bema-crm"); ?>',
                    'error'
                );
                $('#start-sync-form').show();
                $('#stop-sync').hide();
                $('#start-sync').prop('disabled', false);
            }
        });

        // Error modal handling
        $('.show-full-error').on('click', function() {
            const errorMessage = $(this).closest('.error-message').text().trim();
            const modal = $($('#error-modal-template').html());
            modal.find('.error-details').text(errorMessage);
            $('body').append(modal);

            modal.find('.close').on('click', function() {
                modal.remove();
            });
        });
    });
</script>