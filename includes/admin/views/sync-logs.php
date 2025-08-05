<?php

namespace Bema\Admin\Views;

if (!defined('ABSPATH')) {
    exit;
}

if (!isset($admin) || !($admin instanceof \Bema\Admin\Bema_Admin_Interface)) {
    wp_die('Invalid admin interface initialization');
}

// Initialize variables with real data
$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($page - 1) * $admin->get_per_page();
$current_filters = $filters ?? [];
$total_logs = $admin->get_total_logs($current_filters);
$logs = $admin->get_filtered_logs($current_filters, $page);
$sync_status = $admin->sync_instance ? $admin->sync_instance->getCurrentProgress() : [];

?>

<div class="wrap bema-sync-logs">
    <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>

    <!-- Add Sync Status Summary -->
    <div class="sync-status-summary">
        <div class="sync-status-grid">
            <div class="sync-status-item">
                <span class="label"><?php _e('Current Status', 'bema-crm'); ?></span>
                <span class="status-badge status-<?php echo esc_attr($sync_status['status'] ?? 'idle'); ?>">
                    <?php echo esc_html(ucfirst($sync_status['status'] ?? 'idle')); ?>
                </span>
            </div>

            <?php if (!empty($sync_status['current_campaign'])): ?>
                <div class="sync-status-item">
                    <span class="label"><?php _e('Current Campaign', 'bema-crm'); ?></span>
                    <span class="value"><?php echo esc_html($sync_status['current_campaign']); ?></span>
                </div>
            <?php endif; ?>

            <div class="sync-status-item">
                <span class="label"><?php _e('Progress', 'bema-crm'); ?></span>
                <div class="progress-container">
                    <?php
                    $progress = 0;
                    if (!empty($sync_status['total']) && !empty($sync_status['processed'])) {
                        $progress = ($sync_status['processed'] / $sync_status['total']) * 100;
                    }
                    ?>
                    <div class="progress-bar">
                        <div class="progress" style="width: <?php echo esc_attr($progress); ?>%"></div>
                    </div>
                    <span class="progress-text">
                        <?php printf(
                            __('%d of %d processed', 'bema-crm'),
                            $sync_status['processed'] ?? 0,
                            $sync_status['total'] ?? 0
                        ); ?>
                    </span>
                </div>
            </div>

            <div class="sync-status-item">
                <span class="label"><?php _e('Memory Usage', 'bema-crm'); ?></span>
                <span class="value">
                    <?php echo esc_html($sync_status['memory_usage'] ?? '0 MB'); ?>
                    / <?php echo esc_html($sync_status['peak_memory'] ?? '0 MB'); ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Enhanced Filters Section -->
    <div class="tablenav top">
        <div class="alignleft actions">
            <form method="get" class="log-filters">
                <input type="hidden" name="page" value="bema-sync-logs">

                <!-- Add Campaign Filter -->
                <?php if (!empty($campaigns)): ?>
                    <select name="filter_campaign" id="filter-campaign">
                        <option value=""><?php _e('All campaigns', 'bema-crm'); ?></option>
                        <?php foreach ($campaigns as $campaign): ?>
                            <option value="<?php echo esc_attr($campaign['name']); ?>"
                                <?php selected($current_filters['campaign'] ?? '', $campaign['name']); ?>>
                                <?php echo esc_html($campaign['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <!-- Enhanced Status Filter -->
                <select name="filter_status" id="filter-status">
                    <option value=""><?php _e('All statuses', 'bema-crm'); ?></option>
                    <?php
                    $statuses = [
                        'success' => __('Success', 'bema-crm'),
                        'error' => __('Error', 'bema-crm'),
                        'warning' => __('Warning', 'bema-crm'),
                        'running' => __('Running', 'bema-crm'),
                        'stopped' => __('Stopped', 'bema-crm')
                    ];

                    foreach ($statuses as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>"
                            <?php selected($current_filters['status'] ?? '', $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Date Range Filter with Predefined Options -->
                <select name="filter_date" id="filter-date">
                    <option value=""><?php _e('All dates', 'bema-crm'); ?></option>
                    <?php
                    $date_ranges = [
                        'today' => __('Today', 'bema-crm'),
                        'yesterday' => __('Yesterday', 'bema-crm'),
                        'week' => __('Last 7 days', 'bema-crm'),
                        'month' => __('Last 30 days', 'bema-crm')
                    ];

                    foreach ($date_ranges as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>"
                            <?php selected($current_filters['date_range'] ?? '', $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Enhanced Search Box -->
                <input type="search"
                    name="search"
                    value="<?php echo esc_attr($current_filters['search'] ?? ''); ?>"
                    placeholder="<?php esc_attr_e('Search logs...', 'bema-crm'); ?>">

                <?php submit_button(__('Apply Filters', 'bema-crm'), '', 'filter_action', false); ?>

                <?php if (!empty($current_filters)): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=bema-sync-logs')); ?>"
                        class="button"><?php _e('Reset Filters', 'bema-crm'); ?></a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Export & Clear Logs Buttons -->
        <div class="alignright">
            <button type="button" id="export-logs" class="button">
                <?php _e('Export Logs', 'bema-crm'); ?>
            </button>
            <?php if (current_user_can('manage_options')): ?>
                <button type="button" id="clear-logs" class="button">
                    <?php _e('Clear Logs', 'bema-crm'); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Enhanced Logs Table -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col" class="manage-column column-date">
                    <?php _e('Date/Time', 'bema-crm'); ?>
                </th>
                <th scope="col" class="manage-column column-campaign">
                    <?php _e('Campaign', 'bema-crm'); ?>
                </th>
                <th scope="col" class="manage-column column-status">
                    <?php _e('Status', 'bema-crm'); ?>
                </th>
                <th scope="col" class="manage-column column-message">
                    <?php _e('Message', 'bema-crm'); ?>
                </th>
                <th scope="col" class="manage-column column-memory">
                    <?php _e('Memory', 'bema-crm'); ?>
                </th>
                <th scope="col" class="manage-column column-actions">
                    <?php _e('Actions', 'bema-crm'); ?>
                </th>
            </tr>
        </thead>

        <tbody id="the-list">
            <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="6" class="no-items">
                        <?php _e('No logs found.', 'bema-crm'); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="column-date">
                            <?php echo esc_html(date_i18n(
                                get_option('date_format') . ' ' . get_option('time_format'),
                                strtotime($log['created_at'])
                            )); ?>
                        </td>
                        <td class="column-campaign">
                            <?php if (!empty($log['campaign'])): ?>
                                <span class="campaign-badge">
                                    <?php echo esc_html($log['campaign']); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="column-status">
                            <span class="status-badge status-<?php echo esc_attr($log['status']); ?>">
                                <?php echo esc_html(ucfirst($log['status'])); ?>
                            </span>
                        </td>
                        <td class="column-message">
                            <?php echo esc_html($log['message']); ?>
                            <?php if (!empty($log['details'])): ?>
                                <br>
                                <small class="details-preview">
                                    <?php echo esc_html(wp_trim_words(
                                        is_string($log['details']) ? $log['details'] : json_encode($log['details']),
                                        10
                                    )); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td class="column-memory">
                            <?php if (!empty($log['memory_usage'])): ?>
                                <span class="memory-usage">
                                    <?php echo esc_html($log['memory_usage']); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="column-actions">
                            <?php if (!empty($log['details'])): ?>
                                <button type="button"
                                    class="button button-small view-log-details"
                                    data-id="<?php echo esc_attr($log['id']); ?>">
                                    <?php _e('View Details', 'bema-crm'); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Enhanced Pagination -->
    <?php if ($total_logs > $per_page): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php printf(
                        _n('%s item', '%s items', $total_logs, 'bema-crm'),
                        number_format_i18n($total_logs)
                    ); ?>
                </span>
                <?php
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total' => ceil($total_logs / $per_page),
                    'current' => $page
                ]);
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Enhanced Log Details Modal -->
<div id="log-details-modal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2><?php _e('Log Details', 'bema-crm'); ?></h2>
        <div class="log-details-content"></div>
    </div>
</div>

<script>
    jQuery(document).ready(function($) {
        // Format JSON data for display
        function formatJsonData(data) {
            try {
                const jsonData = typeof data === 'string' ? JSON.parse(data) : data;
                return JSON.stringify(jsonData, null, 2);
            } catch (e) {
                return data;
            }
        }

        // View log details
        $('.view-details').on('click', function() {
            const logId = $(this).data('log-id');
            const modal = $('#log-details-modal');

            $.post(ajaxurl, {
                action: 'bema_get_log_details',
                _ajax_nonce: '<?php echo wp_create_nonce('bema_log_details'); ?>',
                log_id: logId
            }, function(response) {
                if (response.success) {
                    modal.find('.log-details-content').html(
                        '<pre>' + formatJsonData(response.data) + '</pre>'
                    );
                    modal.show();
                } else {
                    alert(response.data.message || '<?php _e('Error loading log details', 'bema-crm'); ?>');
                }
            });
        });

        // Close modal
        $('.modal .close').on('click', function() {
            $('#log-details-modal').hide();
        });

        // Click outside modal to close
        $(window).on('click', function(event) {
            if ($(event.target).is('.modal')) {
                $('.modal').hide();
            }
        });

        // Filter form submission handling
        $('.log-filters').on('submit', function(e) {
            const emptyFields = $(this).find('select').filter(function() {
                return !$(this).val();
            });
            emptyFields.removeAttr('name');
        });
    });
</script>
