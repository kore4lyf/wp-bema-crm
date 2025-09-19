<?php
if (!defined('ABSPATH'))
    exit;
?>

<?php
// Fetch current tab
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'controls';
?>

<div class="wrap bema-transitions">
    <h1><?php _e('Campaign Transitions', 'bema-crm'); ?></h1>

    <?php
    // Display any validation errors or warnings registered during submission.
    settings_errors();
    ?>

    <!-- Navigation Tabs -->
    <nav class="nav-tab-wrapper">
        <a href="?page=bema-transitions&tab=controls"
            class="nav-tab <?php echo $current_tab === 'controls' ? 'nav-tab-active' : ''; ?>"> Controls </a>
        <a href="?page=bema-transitions&tab=history"
            class="nav-tab <?php echo $current_tab === 'history' ? 'nav-tab-active' : ''; ?>"> History </a>
        <a href="?page=bema-transitions&tab=settings"
            class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>"> Settings </a>
    </nav>

    <?php if ($current_tab === 'controls'): ?>

        <!-- Tiers  -->
        <?php
            $transition_controls_view_path = plugin_dir_path(BEMA_FILE) . 'includes/' . 'admin/' . 'views/' . 'transitions/' . 'transition-controls-view.php';

            if (file_exists($transition_controls_view_path)) {
                include $transition_controls_view_path;
            } else {
                echo '<div class="notice notice-error"><p>Missing view file: transition-controls-view.php</p></div>';
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

    <?php elseif ($current_tab === 'history'): ?>
        <!-- Campaign Connections -->
        <?php
            $transition_history_view_path = plugin_dir_path(BEMA_FILE) . 'includes/' . 'admin/' . 'views/' . 'transitions/' . 'transition-history-view.php';

            if (file_exists($transition_history_view_path)) {
                include $transition_history_view_path;
            } else {
                echo '<div class="notice notice-error"><p>Missing view file: transition-history-view.php</p></div>';
            }
        ?>

    <?php elseif ($current_tab === 'settings'): ?>
        <!-- Tiers  -->
        <?php
            $tier_table_view_path = plugin_dir_path(BEMA_FILE) . 'includes/' . 'admin/' . 'views/' . 'transitions/' . 'tier-view.php';

            if (file_exists($tier_table_view_path)) {
                include $tier_table_view_path;
            } else {
                echo '<div class="notice notice-error"><p>Missing view file: tier-view.php</p></div>';
            }
        ?>

    <?php endif; ?>

</div>