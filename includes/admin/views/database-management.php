<?php

namespace Bema\Admin\Views;

if (!defined('ABSPATH')) {
    exit;
}

if (!isset($admin) || !($admin instanceof \Bema\Admin\Bema_Admin_Interface)) {
    wp_die('Invalid admin interface initialization');
}

if (!isset($offset) || !isset($total_subscribers) || !isset($total_logs)) {
    wp_die('Required variables not initialized');
}

// Initialize variables
$offset = ($page - 1) * $admin->get_per_page();
$total_subscribers = $admin->get_total_subscribers();
$total_logs = $admin->get_total_logs();
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'subscribers';
$active_filters = $admin->get_active_filters();
?>

<div class="wrap bema-database-management">
    <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>

    <!-- Navigation Tabs -->
    <nav class="nav-tab-wrapper">
        <a href="?page=bema-database&tab=subscribers"
            class="nav-tab <?php echo $current_tab === 'subscribers' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Subscribers', 'bema-crm'); ?>
        </a>
        <a href="?page=bema-database&tab=sync-logs"
            class="nav-tab <?php echo $current_tab === 'sync-logs' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Sync Logs', 'bema-crm'); ?>
        </a>
        <a href="?page=bema-database&tab=maintenance"
            class="nav-tab <?php echo $current_tab === 'maintenance' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Maintenance', 'bema-crm'); ?>
        </a>
    </nav>

    <?php if ($current_tab === 'test'): ?>
        <!-- Subscribers Tab Content -->
        <div class="tablenav top">
            <!-- Bulk Actions -->
            <div class="alignleft actions bulkactions">
                <label for="bulk-action-selector-top" class="screen-reader-text">
                    <?php _e('Select bulk action', 'bema-crm'); ?>
                </label>
                <select name="action" id="bulk-action-selector-top">
                    <option value="-1"><?php _e('Bulk Actions', 'bema-crm'); ?></option>
                    <option value="delete"><?php _e('Delete', 'bema-crm'); ?></option>
                    <option value="update-tier"><?php _e('Update Tier', 'bema-crm'); ?></option>
                    <option value="resync"><?php _e('Resync', 'bema-crm'); ?></option>
                </select>
                <button type="submit" class="button action" id="doaction">
                    <?php _e('Apply', 'bema-crm'); ?>
                </button>
            </div>

            <!-- Filters -->
            <div class="alignleft actions">
                <form method="get" id="subscribers-filter">
                    <input type="hidden" name="page" value="bema-database">
                    <input type="hidden" name="tab" value="subscribers">

                    <!-- Search Box -->
                    <input type="search"
                        name="search"
                        value="<?php echo esc_attr($active_filters['search'] ?? ''); ?>"
                        placeholder="<?php esc_attr_e('Search subscribers...', 'bema-crm'); ?>">

                    <!-- Tier Filter -->
                    <select name="tier">
                        <option value=""><?php _e('All Tiers', 'bema-crm'); ?></option>
                        <?php
                        $tiers = [
                            'opt-in' => __('Opt-In', 'bema-crm'),
                            'gold' => __('Gold', 'bema-crm'),
                            'gold_purchased' => __('Gold Purchased', 'bema-crm'),
                            'silver' => __('Silver', 'bema-crm'),
                            'silver_purchased' => __('Silver Purchased', 'bema-crm'),
                            'bronze' => __('Bronze', 'bema-crm'),
                            'bronze_purchased' => __('Bronze Purchased', 'bema-crm'),
                            'wood' => __('Wood', 'bema-crm')
                        ];

                        foreach ($tiers as $value => $label):
                            $selected = isset($active_filters['tier']) && $active_filters['tier'] === $value ? 'selected' : '';
                        ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php echo $selected; ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Campaign Filter -->
                    <select name="campaign">
                        <option value=""><?php _e('All Campaigns', 'bema-crm'); ?></option>
                        <?php
                        if ($campaign_manager = $admin->get_campaign_manager()):
                            $valid_campaigns = $campaign_manager->get_all_valid_campaigns();
                            foreach ($valid_campaigns as $campaign):
                                $selected = isset($active_filters['campaign']) &&
                                    $active_filters['campaign'] === $campaign ? 'selected' : '';
                        ?>
                                <option value="<?php echo esc_attr($campaign); ?>" <?php echo $selected; ?>>
                                    <?php echo esc_html($campaign); ?>
                                </option>
                        <?php
                            endforeach;
                        endif;
                        ?>
                    </select>

                    <?php submit_button(__('Filter', 'bema-crm'), '', 'filter_action', false); ?>

                    <?php if (!empty($active_filters)): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=bema-database&tab=subscribers')); ?>"
                            class="button">
                            <?php _e('Reset Filters', 'bema-crm'); ?>
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Pagination Info -->
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php printf(
                        _n('%s subscriber', '%s subscribers', $total_subscribers, 'bema-crm'),
                        number_format_i18n($total_subscribers)
                    ); ?>
                </span>
                <?php
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total' => ceil($total_subscribers / $admin->get_per_page()),
                    'current' => $page
                ]);
                ?>
            </div>
        </div>

        <!-- Subscribers Table -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all-1">
                    </td>
                    <th scope="col" class="manage-column column-email">
                        <?php _e('Email', 'bema-crm'); ?>
                    </th>
                    <th scope="col" class="manage-column column-name">
                        <?php _e('Name', 'bema-crm'); ?>
                    </th>
                    <th scope="col" class="manage-column column-tier">
                        <?php _e('Tier', 'bema-crm'); ?>
                    </th>
                    <th scope="col" class="manage-column column-campaign">
                        <?php _e('Campaign', 'bema-crm'); ?>
                    </th>
                    <th scope="col" class="manage-column column-date">
                        <?php _e('Added Date', 'bema-crm'); ?>
                    </th>
                    <th scope="col" class="manage-column column-actions">
                        <?php _e('Actions', 'bema-crm'); ?>
                    </th>
                </tr>
            </thead>

            <tbody>
                <?php
                $subscribers = $admin->get_subscribers($offset);
                if (empty($subscribers)):
                ?>
                    <tr>
                        <td colspan="7" class="colspanchange">
                            <?php _e('No subscribers found.', 'bema-crm'); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subscribers as $subscriber): ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="subscribers[]"
                                    value="<?php echo esc_attr($subscriber['bema_id']); ?>">
                            </th>
                            <td class="column-email">
                                <?php echo esc_html($subscriber['subscriber']); ?>
                            </td>
                            <td class="column-name">
                                <?php
                                $name = trim($subscriber['first_name'] . ' ' . $subscriber['last_name']);
                                echo $name ? esc_html($name) : '&mdash;';
                                ?>
                            </td>
                            <td class="column-tier">
                                <span class="tier-badge tier-<?php echo esc_attr($subscriber['tier']); ?>">
                                    <?php echo esc_html(ucfirst(str_replace('_', ' ', $subscriber['tier']))); ?>
                                </span>
                            </td>
                            <td class="column-campaign">
                                <?php echo esc_html($subscriber['campaign']); ?>
                            </td>
                            <td class="column-date">
                                <?php
                                echo esc_html(
                                    date_i18n(
                                        get_option('date_format') . ' ' . get_option('time_format'),
                                        strtotime($subscriber['date_added'])
                                    )
                                );
                                ?>
                            </td>
                            <td class="column-actions">
                                <button type="button"
                                    class="button button-small view-subscriber-details"
                                    data-subscriber-id="<?php echo esc_attr($subscriber['bema_id']); ?>">
                                    <?php _e('View Details', 'bema-crm'); ?>
                                </button>
                                <button type="button"
                                    class="button button-small edit-subscriber"
                                    data-subscriber-id="<?php echo esc_attr($subscriber['bema_id']); ?>">
                                    <?php _e('Edit', 'bema-crm'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

    <?php elseif ($current_tab === 'subscribers'): ?>
			<?php
			$subscriber_table_view_path = plugin_dir_path(BEMA_FILE) . 'includes/' . 'admin/' . 'views/' . 'database/' . 'subscriber-table-view.php';

        if (file_exists($subscriber_table_view_path)) {
            include $subscriber_table_view_path;
        } else {
            echo '<div class="notice notice-error"><p>Missing view file: subscriber-table-view.php</p></div>';
        }
        ?>

    <?php elseif ($current_tab === 'sync-logs'): ?>
        <!-- Sync Logs Content -->
        <?php require 'sync-logs.php'; ?>

    <?php elseif ($current_tab === 'maintenance'): ?>
        <!-- Maintenance Tab Content -->
        <div class="maintenance-tools">
            <h2><?php _e('Database Maintenance', 'bema-crm'); ?></h2>

            <div class="card">
                <h3><?php _e('Optimization', 'bema-crm'); ?></h3>
                <form method="post" class="optimize-tables">
                    <?php wp_nonce_field('bema_db_maintenance'); ?>
                    <input type="hidden" name="action" value="optimize_tables">
                    <p><?php _e('Optimize database tables to improve performance.', 'bema-crm'); ?></p>
                    <button type="submit" class="button button-primary">
                        <?php _e('Optimize Tables', 'bema-crm'); ?>
                    </button>
                </form>
            </div>

            <div class="card">
                <h3><?php _e('Cleanup', 'bema-crm'); ?></h3>
                <form method="post" class="cleanup-data">
                    <?php wp_nonce_field('bema_db_maintenance'); ?>
                    <input type="hidden" name="action" value="cleanup_data">

                    <p><?php _e('Remove old data to free up space.', 'bema-crm'); ?></p>

                    <label>
                        <input type="checkbox" name="cleanup_logs" value="1">
                        <?php _e('Clean up logs older than:', 'bema-crm'); ?>
                    </label>
                    <select name="log_age">
                        <option value="30"><?php _e('30 days', 'bema-crm'); ?></option>
                        <option value="60"><?php _e('60 days', 'bema-crm'); ?></option>
                        <option value="90"><?php _e('90 days', 'bema-crm'); ?></option>
                    </select>

                    <br><br>

                    <label>
                        <input type="checkbox" name="cleanup_subscribers" value="1">
                        <?php _e('Remove inactive subscribers (no activity for over 1 year)', 'bema-crm'); ?>
                    </label>

                    <br><br>

                    <button type="submit" class="button button-primary cleanup-button">
                        <?php _e('Clean Up Data', 'bema-crm'); ?>
                    </button>
                </form>
            </div>

            <div class="card">
                <h3><?php _e('Database Repair', 'bema-crm'); ?></h3>
                <form method="post" class="repair-tables">
                    <?php wp_nonce_field('bema_db_maintenance'); ?>
                    <input type="hidden" name="action" value="repair_tables">
                    <p><?php _e('Check and repair database tables if needed.', 'bema-crm'); ?></p>
                    <button type="submit" class="button button-primary">
                        <?php _e('Repair Tables', 'bema-crm'); ?>
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Subscriber Details Modal -->
<div id="subscriber-modal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2 id="modal-title"></h2>
        <div id="modal-content"></div>
    </div>
</div>

<!-- Edit Subscriber Modal -->
<div id="edit-subscriber-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2><?php _e('Edit Subscriber', 'bema-crm'); ?></h2>
        <form id="edit-subscriber-form">
            <!-- Form fields will be populated dynamically -->
        </form>
    </div>
</div>

<script>
    jQuery(document).ready(function($) {
        // View subscriber details
        $('.view-subscriber-details').on('click', function() {
            const subscriberId = $(this).data('subscriber-id');

            $.ajax({
                url: bemaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bema_get_subscriber_details',
                    nonce: bemaAdmin.nonce,
                    id: subscriberId
                },
                beforeSend: function() {
                    // Show loading state
                    BemaAdmin.showNotification('Loading subscriber details...', 'info');
                },
                success: function(response) {
                    if (response.success) {
                        // Clear loading notification
                        $('#bema-notifications').empty();

                        // Format and display the data
                        const formattedDetails = formatSubscriberDetails(response.data);
                        $('#modal-title').text('Subscriber Details');
                        $('#modal-content').html(formattedDetails);
                        $('#subscriber-modal').fadeIn(300);
                    } else {
                        BemaAdmin.showNotification(response.data.message || 'Error loading subscriber details', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    BemaAdmin.showNotification('Failed to load subscriber details: ' + error, 'error');
                }
            });
        });

        // Edit subscriber
        $('.edit-subscriber').on('click', function() {
            const subscriberId = $(this).data('id');

            $.post(ajaxurl, {
                action: 'bema_get_subscriber_details',
                _ajax_nonce: '<?php echo wp_create_nonce('bema_subscriber_details'); ?>',
                id: subscriberId
            }, function(response) {
                if (response.success) {
                    populateEditForm(response.data);
                    $('#edit-subscriber-modal').show();
                } else {
                    alert(response.data.message);
                }
            });
        });

        // Handle subscriber edit form submission
        $('#edit-subscriber-form').on('submit', function(e) {
            e.preventDefault();
            const formData = $(this).serialize();

            $.post(ajaxurl, {
                action: 'bema_update_subscriber',
                _ajax_nonce: '<?php echo wp_create_nonce('bema_update_subscriber'); ?>',
                data: formData
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message);
                }
            });
        });

        // Close modals
        $('.modal .close, .modal').on('click', function(e) {
            if (e.target === this || $(e.target).hasClass('close')) {
                $(this).closest('.modal').fadeOut(300);
            }
        });

        $(window).on('click', function(event) {
            if ($(event.target).hasClass('modal')) {
                $('.modal').hide();
            }
        });

        // Format subscriber details for display
        function formatSubscriberDetails(data) {
            let html = '<table class="widefat">';

            for (const [key, value] of Object.entries(data)) {
                if (key !== 'id') {
                    html += `<tr>
                    <th>${formatLabel(key)}</th>
                    <td>${formatValue(value, key)}</td>
                </tr>`;
                }
            }

            html += '</table>';
            return html;
        }

        // Populate edit form
        function populateEditForm(data) {
            let html = `
            <input type="hidden" name="subscriber_id" value="${data.bema_id}">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="email"><?php _e('Email', 'bema-crm'); ?></label></th>
                    <td><input type="email" name="email" id="email" value="${data.subscriber}" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="first_name"><?php _e('First Name', 'bema-crm'); ?></label></th>
                    <td><input type="text" name="first_name" id="first_name" value="${data.first_name || ''}" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="last_name"><?php _e('Last Name', 'bema-crm'); ?></label></th>
                    <td><input type="text" name="last_name" id="last_name" value="${data.last_name || ''}" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tier"><?php _e('Tier', 'bema-crm'); ?></label></th>
                    <td>
                        <select name="tier" id="tier" required>
                            ${generateTierOptions(data.tier)}
                        </select>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="submit" class="button button-primary" value="<?php _e('Update Subscriber', 'bema-crm'); ?>">
            </p>
        `;

            $('#edit-subscriber-form').html(html);
        }

        // Format label text
        function formatLabel(key) {
            return key.split('_').map(word =>
                word.charAt(0).toUpperCase() + word.slice(1)
            ).join(' ');
        }

        // Format value based on type
        function formatValue(value, key) {
            if (value === null || value === '') return '&mdash;';

            if (key === 'date_added' || key.includes('date')) {
                return new Date(value).toLocaleString();
            }

            if (key === 'tier') {
                return `<span class="tier-badge tier-${value}">${formatLabel(value)}</span>`;
            }

            return value;
        }

        // Generate tier options
        function generateTierOptions(currentTier) {
            const tiers = {
                'opt-in': '<?php _e('Opt-In', 'bema-crm'); ?>',
                'gold': '<?php _e('Gold', 'bema-crm'); ?>',
                'gold_purchased': '<?php _e('Gold Purchased', 'bema-crm'); ?>',
                'silver': '<?php _e('Silver', 'bema-crm'); ?>',
                'silver_purchased': '<?php _e('Silver Purchased', 'bema-crm'); ?>',
                'bronze': '<?php _e('Bronze', 'bema-crm'); ?>',
                'bronze_purchased': '<?php _e('Bronze Purchased', 'bema-crm'); ?>',
                'wood': '<?php _e('Wood', 'bema-crm'); ?>'
            };

            return Object.entries(tiers)
                .map(([value, label]) =>
                    `<option value="${value}" ${value === currentTier ? 'selected' : ''}>${label}</option>`
                ).join('');
        }

        // Handle bulk actions
        $('#doaction').on('click', function(e) {
            e.preventDefault();
            const action = $('#bulk-action-selector-top').val();
            const selectedItems = $('input[name="subscribers[]"]:checked').length;

            if (action === '-1') {
                alert('<?php _e('Please select an action', 'bema-crm'); ?>');
                return;
            }

            if (selectedItems === 0) {
                alert('<?php _e('Please select at least one subscriber', 'bema-crm'); ?>');
                return;
            }

            if (confirm('<?php _e('Are you sure you want to perform this action?', 'bema-crm'); ?>')) {
                $('#subscribers-filter').submit();
            }
        });

        // Confirm data cleanup
        $('.cleanup-button').on('click', function(e) {
            if (!confirm('<?php _e('Are you sure you want to clean up the selected data? This action cannot be undone.', 'bema-crm'); ?>')) {
                e.preventDefault();
            }
        });
    });
</script>