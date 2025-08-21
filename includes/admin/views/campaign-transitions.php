<?php if (!defined('ABSPATH'))
    exit; ?>

<?php
// Fetch current tab
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'history';
?>

<div class="wrap bema-transitions">
    <h1><?php _e('Campaign Transitions', 'bema-crm'); ?></h1>

    <?php
    // Display any validation errors or warnings registered during submission.
    settings_errors();
    ?>

    <!-- Navigation Tabs -->
    <nav class="nav-tab-wrapper">
        <a href="?page=bema-transitions&tab=history"
            class="nav-tab <?php echo $current_tab === 'history' ? 'nav-tab-active' : ''; ?>"> History </a>
        <a href="?page=bema-transitions&tab=settings"
            class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>"> Settings </a>
    </nav>

    <?php if ($current_tab === 'history'): ?>

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

    <?php elseif ($current_tab === 'settings'): ?>
        <!-- Tiers  -->
        <?php
        $tier_table_view_path = plugin_dir_path(BEMA_FILE) . 'includes/' . 'admin/' . 'views/' . 'transitions/' . 'tier-view.php';

        if (file_exists($tier_table_view_path)) {
            include $tier_table_view_path;
        } else {
            echo '<div class="notice notice-error"><p>Missing view file: table-view.php</p></div>';
        }
        ?>



        <!-- Tier Transition Matrix -->
        <?php
        $transition_table_view_path = plugin_dir_path(BEMA_FILE) . 'includes/' . 'admin/' . 'views/' . 'transitions/' . 'transitions-matrix-view.php';

        if (file_exists($transition_table_view_path)) {
            include $transition_table_view_path;
        } else {
            echo '<div class="notice notice-error"><p>Missing view file: transitions-matrix-view.php</p></div>';
        }
        ?>

    <?php endif; ?>

</div>