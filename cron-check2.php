<?php
// Simple script to check WP Cron status
echo "Checking WP Cron status...\n";

// Check if DISABLE_WP_CRON is defined
if (defined('DISABLE_WP_CRON')) {
    echo "DISABLE_WP_CRON: " . (DISABLE_WP_CRON ? 'true' : 'false') . "\n";
} else {
    echo "DISABLE_WP_CRON: Not defined\n";
}

// Check if ALTERNATE_WP_CRON is defined
if (defined('ALTERNATE_WP_CRON')) {
    echo "ALTERNATE_WP_CRON: " . (ALTERNATE_WP_CRON ? 'true' : 'false') . "\n";
} else {
    echo "ALTERNATE_WP_CRON: Not defined\n";
}

// Try to load WordPress to check cron events
$wp_config_path = dirname(__FILE__) . '/../../wp-config.php';
if (file_exists($wp_config_path)) {
    echo "Found wp-config.php\n";
    require_once($wp_config_path);
    
    // Check if WordPress functions are available
    if (function_exists('_get_cron_array')) {
        $cron = _get_cron_array();
        echo "Cron events: " . (is_array($cron) ? count($cron) . " events found" : "None found") . "\n";
        
        if (is_array($cron) && !empty($cron)) {
            echo "Next scheduled event: " . key($cron) . " (" . date('Y-m-d H:i:s', key($cron)) . ")\n";
        }
    } else {
        echo "WordPress cron functions not available\n";
    }
} else {
    echo "wp-config.php not found\n";
}

echo "Script completed.\n";
?>