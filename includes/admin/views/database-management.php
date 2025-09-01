<?php

namespace Bema\Admin\Views;

if (!defined('ABSPATH')) {
    exit;
}

// Initialize variables
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'subscribers';

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
    

    <?php if ($current_tab === 'subscribers'): ?>
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

    <?php endif; ?>
</div>