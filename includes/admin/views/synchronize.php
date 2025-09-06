<?php

namespace Bema\Admin\Views;

if (!defined('ABSPATH')) {
    exit;
}

// --- Sync State Constants ---
define('IDLE', 'Idle');
define('RUNNING', 'Running');
define('COMPLETE', 'Complete');

// --- Option Key ---
$sync_option_key = 'bema_crm_sync_status';

$sync_state = get_option($sync_option_key, []);
$sync_state['status'] = COMPLETE;
$sync_state['last_sync_time'] = date('F j, Y g:i A', strtotime(current_time('mysql')));
update_option($sync_option_key, $sync_state);

// --- Ensure DB Manager Available ---
$this->sync_db_manager = new \Bema\Database\Sync_Database_Manager();

// --- Helper to Trigger Immediate Sync ---
function trigger_immediate_sync()
{
    if (!wp_next_scheduled('bema_crm_sync_cron_job')) {
        wp_schedule_single_event(time(), 'bema_crm_sync_cron_job');
    }
}

// --- Handle Start Sync Form ---
if (isset($_POST['start_sync'])) {
    $are_api_keys_missing = false;

    if (empty(get_option('bema_crm_settings')['api']['mailerlite_api_key'])) {
        echo '<div class="notice notice-error"><p><b>Error:</b> Mailerlite API key is missing. Visit <a href="' . admin_url() . 'admin.php?page=bema-settings' . '">settings page  »</a> .</p></div>';
        $are_api_keys_missing = true;
    } else if (empty(get_option('bema_crm_settings')['api']['edd_api_key'])) {
        echo '<div class="notice notice-error"><p><b>Error:</b> EDD public key is missing. Visit <a href="' . admin_url() . 'admin.php?page=bema-settings' . '">settings page  »</a> .</p></div>';
        $are_api_keys_missing = true;
    } else if (empty(get_option('bema_crm_settings')['api']['edd_token'])) {
        echo '<div class="notice notice-error"><p><b>Error:</b> EDD token is missing. Visit <a href="' . admin_url() . 'admin.php?page=bema-settings' . '">settings page  »</a> </p></div>';
        $are_api_keys_missing = true;
    }

    if (!$are_api_keys_missing) {
        $current_status = get_option($sync_option_key, []);

        if (empty($current_status) || $current_status['status'] === IDLE || $current_status['status'] === COMPLETE) {
            $sync_state = [
                'status' => RUNNING,
                'last_sync_time' => date('F j, Y g:i A', strtotime(current_time('mysql')))
            ];
            update_option($sync_option_key, $sync_state);
            trigger_immediate_sync();
        }
    }
}

// --- Handle AJAX Request for Sync Record ---
if (isset($_POST['action']) && $_POST['action'] === 'view_sync_record' && isset($_POST['id'])) {

    $record_id = intval($_POST['id']);
    $sync_record = $this->sync_db_manager->get_sync_record_by_id($record_id);

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

// --- Load Current Status & Sync History ---
$current_status = get_option($sync_option_key, []);

$sync_history = $this->sync_db_manager->get_sync_records_without_data();

?>

<div class="wrap">
    <h1>Synchronize Mailerlite</h1>

    <div class="status-grid">
        <div class="status-item">
            <div class="label">Controls</div>
            <div class="status-gap"></div>
            <form method="post">
                <input type="submit" name="start_sync" class="button button-primary"
                    value="Start Sync"
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
                <tr>
                    <td className="text-center" colspan="6">No sync history found.</td>
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
        border: 1px solid red;
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