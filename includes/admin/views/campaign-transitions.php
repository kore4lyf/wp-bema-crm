<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap bema-transitions">
    <h1><?php _e('Campaign Transitions', 'bema-crm'); ?></h1>

    <div class="transition-grid">

        <!-- Tiers  -->
        <?php
            $tier_table_view_path = plugin_dir_path(BEMA_FILE) . 'includes/' . 'admin/' . 'views/' . 'settings/' . 'tier-view.php';

            if (file_exists($tier_table_view_path)) {
                include $tier_table_view_path;
            } else {
                echo '<div class="notice notice-error"><p>Missing view file: table-view.php</p></div>';
            }
        ?>
        <!-- Campaign Connections -->
        <div class="postbox">
            <h2 class="transitions-subtitle"><span><?php _e('Campaign Connections', 'bema-crm'); ?></span></h2>

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
        <?php
            $transition_table_view_path = plugin_dir_path(BEMA_FILE) . 'includes/' . 'admin/' . 'views/' . 'settings/' . 'transitions-matrix-view.php';

            if (file_exists($transition_table_view_path)) {
                include $transition_table_view_path;
            } else {
                echo '<div class="notice notice-error"><p>Missing view file: transitions-matrix-view.php</p></div>';
            }
        ?>






    </div>
</div>

<style>
    .transitions-subtitle {
        margin-left: 12px;
    }

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
