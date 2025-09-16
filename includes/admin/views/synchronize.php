<?php

namespace Bema\Admin\Views;

use Bema_CRM_Notify;

if (!defined('ABSPATH')) {
    exit;
}

// Sync State Constants
define('IDLE', 'Idle');
define('RUNNING', 'Running');
define('COMPLETE', 'Complete');
define('SYNC_CRON_JOB', 'bema_crm_sync_cron_job');

// Option Key
$sync_option_key = 'bema_crm_sync_status';

// Ensure DB Manager Available
$sync_db_manager = new \Bema\Database\Sync_Database_Manager();

$sync_state = get_option($sync_option_key, []);
$sync_state['status'] = COMPLETE;
update_option($sync_option_key, $sync_state);

// Helper to Trigger Immediate Sync
function trigger_immediate_sync()
{
    if (!wp_next_scheduled(SYNC_CRON_JOB)) {
        wp_schedule_single_event(time(), SYNC_CRON_JOB);
    }
}

// Handle Start Sync Form
if (isset($_POST['start_sync'])) {
    // Load saved plugin settings once to avoid repeated get_option() calls
    $settings = get_option('bema_crm_settings', []);
    // Ensure 'api' is an array to avoid notices when accessing keys
    $api = isset($settings['api']) && is_array($settings['api']) ? $settings['api'] : [];

    // Define required keys and their human-friendly labels
    $required = [
        'mailerlite_api_key' => 'Mailerlite API key',
        'edd_api_key'        => 'EDD public key',
        'edd_token'          => 'EDD token',
    ];

    // Collect any missing keys so we can show a single message
    $missing = [];
    foreach ($required as $key => $label) {
        if (empty($api[$key])) {
            $missing[] = $label;
        }
    }

    // If any keys are missing, show an error and stop the sync attempt
    if (!empty($missing)) {
        $link = esc_url(admin_url('admin.php?page=bema-settings')); // safe URL
        $labels = implode(', ', array_map('esc_html', $missing));   // escape labels
        \Bema\bema_notice("$labels missing. Visit settings page to configure.", 'error', 'Configuration Error');
        return; // abort starting sync
    }

    // Read current sync status (provide default to avoid warnings)
    $current_status = get_option($sync_option_key, []);
    $status = isset($current_status['status']) ? $current_status['status'] : null;

    // Only start a new sync if there is no active running state
    if (empty($current_status) || in_array($status, [IDLE, COMPLETE], true)) {
        // Prepare new sync state and store it
        $sync_state = [
            'status' => RUNNING,
            // Use WP-aware current_time('timestamp') then format for display
            'last_sync_time' => date('F j, Y g:i A', current_time('timestamp')),
        ];
        update_option($sync_option_key, $sync_state);

        wp_clear_scheduled_hook( SYNC_CRON_JOB );

        // Trigger the actual immediate sync process
        trigger_immediate_sync();
        \Bema\bema_notice('Sync process started successfully!', 'success', 'Sync Started');
    } else {
        // Inform the user that a sync is already in progress
        \Bema\bema_notice('Sync is already running. Please wait for it to complete.', 'info', 'Sync In Progress');
    }
}

// Handle AJAX Request for Sync Record
if (isset($_POST['action']) && $_POST['action'] === 'view_sync_record' && isset($_POST['id'])) {

    $record_id = intval($_POST['id']);
    $sync_record = $sync_db_manager->get_sync_record_by_id($record_id);

    $response = ['success' => false];
    if ($sync_record) {
        $response['success'] = true;
        $synced_data = !empty($sync_record->data) ? unserialize($sync_record->data) : [];

        $response['data'] = [
            'sync_details' => [
                'Date' => $sync_record->sync_date,
                'Status' => $sync_record->status,
                'Users Synced' => $sync_record->synced_users,
                'Notes' => $sync_record->notes
            ],
            'synced_data' => $synced_data
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    wp_die();
}


// $admin->sync_instance->sync_album_campaign_data();
// $admin->sync_instance->sync_mailerlite_group_data();

// Load Current Status & Sync History
$current_status = get_option($sync_option_key, []);

// Load Sync History
$sync_history = $sync_db_manager->get_sync_records_without_data();


?>

<div class="wrap">
    <h1>Synchronize Mailerlite</h1>

    <div class="status-grid">
        <div class="status-item">
            <div class="label">Controls</div>
            <div class="status-gap"></div>
            <form method="post">
                <input type="submit" name="start_sync" class="button button-primary" value="Start Sync"
                    <?php echo (isset($current_status['status']) && $current_status['status'] === RUNNING) ? 'disabled' : ''; ?> />
                <input type="submit" name="view_status" class="button button-secondary" value="View Sync Status" />
            </form>
        </div>

        <div class="status-item">
            <div class="label">Status</div>
            <div>
                <p><strong>State:</strong> <?php echo esc_html($current_status['status'] ?? IDLE); ?></p>
                <p><strong>Message:</strong> <span id="sync-msg"><?php echo esc_html($current_status['status_message']); ?></span></p>
                <p><strong>Last Sync Time:</strong> <?php echo esc_html($current_status['last_sync_time'] ?? 'N/A'); ?></p>
            </div>
            <div class="sync-progress-container">
                <div class="sync-progress-bar" style="width:<?php echo esc_html($current_status['percentage'] ?? 0); ?>%;">
                    <?php echo esc_html($current_status['percentage'] ?? 0); ?>%
                </div>
            </div>
        </div>
    </div>

    <h2>Sync History</h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>Date/Time</th>
                <th>Status</th>
                <th>Subscribers</th>
                <th>Notes</th>
                <th>View</th>
                <th>Restore</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($sync_history)): ?>
                <tr class="text-center">
                    <td colspan="6">No sync history found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($sync_history as $record): ?>
                    <tr>
                        <td><?php echo esc_html($record['sync_date']); ?></td>
                        <td><?php echo esc_html($record['status']); ?></td>
                        <td><?php echo intval($record['synced_subscribers']); ?></td>
                        <td><?php echo esc_html($record['notes']); ?></td>
                        <td><?php echo $record['status'] === 'Completed' ? '<a href="#" class="view-sync" data-id="' . intval($record['id']) . '">View</a>' : ''; ?></td>
                        <td><?php echo $record['status'] === 'Completed' ? '<a href="#" class="restore-sync" data-id="' . intval($record['id']) . '">Restore</a>' : ''; ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="sync-view-container" style="display:none;">
    <h2>Sync Record Details</h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>Field</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody id="sync-view-data"></tbody>
    </table>
    <button id="close-sync-view" class="button button-secondary" style="margin-top: 10px;">Close View</button>
</div>

<style>
    .text-center {
        text-align: center;
    }

    .status-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin: 15px 0;
    }

    .status-item {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 4px;
        border: 1px solid #dee2e6;
        display: grid;
        grid-template-rows: auto 1fr auto;
    }

    .status-item .label {
        font-size: 1.2rem;
    }

    .sync-progress-container {
        width: 100%;
        background: #eee;
        border: 1px solid #ccc;
        border-radius: 4px;
        height: 25px;
    }

    .sync-progress-bar {
        height: 100%;
        background: #2271b1;
        color: #fff;
        text-align: center;
        line-height: 25px;
        border-radius: 4px;
        transition: width 0.5s ease-in-out;
    }

    pre {
        white-space: pre-wrap;
        word-wrap: break-word;
        font-family: monospace;
        font-size: 14px;
    }
</style>

<script>
    jQuery(document).ready(function($) {
        $('.view-sync').on('click', function(e) {
            e.preventDefault();
            var recordId = $(this).data('id');
            $('#sync-view-container').hide();
            $('#sync-view-data').html('<tr><td colspan="2">Loading...</td></tr>');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'view_sync_record',
                    id: recordId
                },
                success: function(response) {
                    if (response.success && response.data) {
                        var data = response.data;
                        var tableBody = $('#sync-view-data');
                        tableBody.empty();
                        $.each(data.sync_details, function(key, value) {
                            tableBody.append('<tr><td><strong>' + key + '</strong></td><td>' + value + '</td></tr>');
                        });
                        tableBody.append('<tr><td colspan="2"><h3>Synced Data</h3></td></tr>');
                        if ($.isEmptyObject(data.synced_data)) {
                            tableBody.append('<tr><td colspan="2">No detailed data available.</td></tr>');
                        } else {
                            $.each(data.synced_data, function(key, value) {
                                if (typeof value === 'object' && value !== null) {
                                    value = JSON.stringify(value, null, 2);
                                }
                                tableBody.append('<tr><td>' + key + '</td><td><pre>' + value + '</pre></td></tr>');
                            });
                        }
                        $('#sync-view-container').show();
                    } else {
                        $('#sync-view-data').html('<tr><td colspan="2">Error loading data.</td></tr>');
                        $('#sync-view-container').show();
                    }
                },
                error: function() {
                    $('#sync-view-data').html('<tr><td colspan="2">An error occurred.</td></tr>');
                    $('#sync-view-container').show();
                }
            });
        });

        $('#close-sync-view').on('click', function() {
            $('#sync-view-container').hide();
        });
    });
</script>