<?php

namespace Bema\Admin\Views;

if (!defined('ABSPATH')) {
    exit;
}

if (!isset($admin) || !($admin instanceof \Bema\Admin\Bema_Admin_Interface)) {
    wp_die('Invalid admin interface initialization');
}

if (!isset($campaign_manager) || !isset($groups)) {
    return;
}


// Get sync status data
$sync_status = isset($sync_status) ? $sync_status : [];
$current_campaign = $sync_status['current_campaign'] ?? '';
$current_group = $sync_status['current_group'] ?? '';
?>

<div class="campaign-groups-wrapper">
    <h2><?php _e('Campaign Groups Status', 'bema-crm'); ?></h2>

    <?php if (empty($groups)): ?>
        <p class="no-groups"><?php _e('No campaign groups are currently configured.', 'bema-crm'); ?></p>
    <?php else: ?>
        <div class="groups-grid">
            <?php foreach ($groups as $campaign_name => $campaign_groups): ?>
                <div class="campaign-section">
                    <h3 class="campaign-name">
                        <?php echo esc_html($campaign_name); ?>
                        <?php if ($campaign_name === $current_campaign): ?>
                            <span class="status-badge status-active"><?php _e('Active', 'bema-crm'); ?></span>
                        <?php endif; ?>
                    </h3>

                    <div class="group-items">
                        <?php foreach ($campaign_groups as $group_type => $group_name): ?>
                            <div class="group-item <?php echo $group_type === $current_group ? 'active' : ''; ?>" data-group-type="<?php echo esc_attr($group_type); ?>">
                                <div class="group-header">
                                    <span class="group-type"><?php echo esc_html(ucfirst($group_type)); ?></span>
                                    <span class="group-name"><?php echo esc_html($group_name); ?></span>
                                </div>

                                <div class="group-stats">
                                    <div class="stat-row">
                                        <span class="stat-label"><?php _e('Processed:', 'bema-crm'); ?></span>
                                        <span class="processed-count">
                                            <?php echo isset($sync_status['campaign_details']['group_progress'][$group_type]['processed'])
                                                ? esc_html($sync_status['campaign_details']['group_progress'][$group_type]['processed'])
                                                : '0'; ?>
                                        </span>
                                    </div>
                                    <div class="stat-row">
                                        <span class="stat-label"><?php _e('Total:', 'bema-crm'); ?></span>
                                        <span class="total-count">
                                            <?php echo isset($sync_status['campaign_details']['group_progress'][$group_type]['total'])
                                                ? esc_html($sync_status['campaign_details']['group_progress'][$group_type]['total'])
                                                : '0'; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="current-operation">
                                    <?php if ($group_type === $current_group && isset($sync_status['current_page'])): ?>
                                        <span class="operation-status">
                                            <?php printf(
                                                __('Processing page %d (%d pages processed)', 'bema-crm'),
                                                $sync_status['current_page'],
                                                $sync_status['total_pages_processed']
                                            ); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    /* Campaign Groups Styles */
    .campaign-section {
        background: #fff;
        border: 1px solid #e2e4e7;
        border-radius: 4px;
        margin-bottom: 20px;
        padding: 20px;
    }

    .campaign-name {
        font-size: 18px;
        margin: 0 0 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid #e2e4e7;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .group-items {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 15px;
    }

    .group-item {
        background: #f8f9fa;
        border: 1px solid #e2e4e7;
        border-radius: 4px;
        padding: 15px;
    }

    .group-item.active {
        border-color: #007cba;
        box-shadow: 0 0 0 1px #007cba;
    }

    .group-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .group-type {
        font-weight: 600;
        color: #1e1e1e;
    }

    .group-name {
        color: #757575;
        font-size: 0.9em;
    }

    .group-stats {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        margin-top: 10px;
        font-size: 0.9em;
    }

    .stat-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 5px;
        background: #fff;
        border-radius: 3px;
    }

    .stat-label {
        color: #757575;
    }

    .stat-value {
        font-weight: 500;
    }

    .current-operation {
        margin-top: 10px;
        padding: 8px;
        background: #f0f6fc;
        border-radius: 3px;
        font-size: 0.9em;
        color: #1e1e1e;
    }

    .group-errors {
        margin-top: 10px;
        padding: 8px;
        background: #fcf0f1;
        border-radius: 3px;
        color: #cc1818;
        font-size: 0.9em;
    }

    .status-badge {
        font-size: 12px;
        padding: 4px 8px;
        border-radius: 3px;
        margin-left: 10px;
    }

    .status-badge.status-active {
        background: #00a32a;
        color: #fff;
    }
</style>