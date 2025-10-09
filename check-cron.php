<?php
// Load WordPress
require_once(dirname(__FILE__) . '/../../../../wp-config.php');
require_once(dirname(__FILE__) . '/../../../../wp-includes/wp-db.php');

// Check if WP Cron is disabled
echo "DISABLE_WP_CRON constant: " . (defined('DISABLE_WP_CRON') ? (DISABLE_WP_CRON ? 'true' : 'false') : 'not defined') . "\n";

// Check scheduled events
echo "Scheduled cron events:\n";
$cron_jobs = _get_cron_array();
if (empty($cron_jobs)) {
    echo "No cron jobs scheduled\n";
} else {
    echo "Found " . count($cron_jobs) . " cron job entries\n";
    
    // Show next scheduled event
    $next_event = key($cron_jobs);
    echo "Next scheduled event timestamp: " . $next_event . " (" . date('Y-m-d H:i:s', $next_event) . ")\n";
}

// Check if ALTERNATE_WP_CRON is defined
echo "ALTERNATE_WP_CRON constant: " . (defined('ALTERNATE_WP_CRON') ? (ALTERNATE_WP_CRON ? 'true' : 'false') : 'not defined') . "\n";
?>