<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap bema-transitions">
    <h1><?php _e('Campaign Transitions', 'bema-crm'); ?></h1>

    <div class="transition-grid">
        <!-- Campaign Connections -->
        <div class="postbox">
            <h2 class="hndle"><span><?php _e('Campaign Connections', 'bema-crm'); ?></span></h2>
            <div class="inside">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Source Campaign', 'bema-crm'); ?></th>
                            <th><?php _e('Destination Campaign', 'bema-crm'); ?></th>
                            <th><?php _e('Status', 'bema-crm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campaign_connections as $connection): ?>
                            <tr>
                                <td><?php echo esc_html($connection['source']); ?></td>
                                <td><?php echo esc_html($connection['destination']); ?></td>
                                <td>
                                    <span class="status-indicator <?php echo $connection['valid'] ? 'valid' : 'invalid'; ?>">
                                        <?php echo $connection['valid'] ? '✓' : '✗'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tier Transition Matrix -->
        <div class="postbox">
            <h2 class="hndle"><span><?php _e('Tier Transition Matrix', 'bema-crm'); ?></span></h2>
            <div class="inside">
                <table class="wp-list-table widefat fixed striped transition-matrix">
                    <thead>
                        <tr>
                            <th><?php _e('Current Tier', 'bema-crm'); ?></th>
                            <th><?php _e('Next Campaign Tier', 'bema-crm'); ?></th>
                            <th><?php _e('Purchase Required', 'bema-crm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transition_matrix as $transition): ?>
                            <tr>
                                <td><?php echo esc_html($transition['current_tier']); ?></td>
                                <td><?php echo esc_html($transition['next_tier']); ?></td>
                                <td>
                                    <?php if ($transition['requires_purchase']): ?>
                                        <span class="dashicons dashicons-yes"></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    .transition-grid {
        display: grid;
        gap: 20px;
        margin-top: 20px;
    }

    .status-indicator {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 3px;
        font-weight: bold;
    }

    .status-indicator.valid {
        background-color: #d4edda;
        color: #155724;
    }

    .status-indicator.invalid {
        background-color: #f8d7da;
        color: #721c24;
    }

    .transition-matrix td {
        vertical-align: middle;
    }

    .transition-matrix .dashicons-yes {
        color: #46b450;
    }
</style>
