<?php
/**
 * Enhanced Sync Management View using new Sync_Manager
 */

namespace Bema\Admin\Views;

use Bema\Manager_Factory;

if (!defined('ABSPATH')) {
    exit;
}

// Get the new managers
try {
    $sync_manager = Manager_Factory::get_sync_manager();
    $transition_manager = Manager_Factory::get_transition_manager();
} catch (Exception $e) {
    \Bema\bema_notice('Failed to initialize managers: ' . $e->getMessage(), 'error', 'Initialization Error');
    return;
}

?>

<div class="wrap bema-sync-new">
    <h1><?php _e('Enhanced Sync Management', 'bema-crm'); ?></h1>
    
    <div class="notice notice-info">
        <p><?php _e('This page demonstrates the new Sync_Manager and Transition_Manager implementation for better separation of concerns.', 'bema-crm'); ?></p>
    </div>

    <div class="nav-tab-wrapper">
        <a href="#sync-operations" class="nav-tab nav-tab-active" data-tab="sync-operations">
            <?php _e('Sync Operations', 'bema-crm'); ?>
        </a>
        <a href="#campaign-transitions" class="nav-tab" data-tab="campaign-transitions">
            <?php _e('Campaign Transitions', 'bema-crm'); ?>
        </a>
    </div>

    <!-- Sync Operations Tab -->
    <div id="sync-operations" class="tab-content active">
        <div class="postbox">
            <h2 class="hndle"><?php _e('Individual Sync Operations', 'bema-crm'); ?></h2>
            <div class="inside">
                <p><?php _e('Perform specific sync operations using the Sync_Manager:', 'bema-crm'); ?></p>
                
                <div class="sync-operations-grid">
                    <div class="sync-operation">
                        <h3><?php _e('Campaign Data', 'bema-crm'); ?></h3>
                        <p><?php _e('Sync album and custom campaign data with MailerLite.', 'bema-crm'); ?></p>
                        <button type="button" class="button button-primary sync-operation-btn" 
                                data-operation="sync_album_campaign_data">
                            <?php _e('Sync Campaigns', 'bema-crm'); ?>
                        </button>
                    </div>

                    <div class="sync-operation">
                        <h3><?php _e('Field Data', 'bema-crm'); ?></h3>
                        <p><?php _e('Sync campaign purchase fields with MailerLite.', 'bema-crm'); ?></p>
                        <button type="button" class="button button-primary sync-operation-btn" 
                                data-operation="sync_mailerlite_field_data">
                            <?php _e('Sync Fields', 'bema-crm'); ?>
                        </button>
                    </div>

                    <div class="sync-operation">
                        <h3><?php _e('Group Data', 'bema-crm'); ?></h3>
                        <p><?php _e('Sync MailerLite group data with local database.', 'bema-crm'); ?></p>
                        <button type="button" class="button button-primary sync-operation-btn" 
                                data-operation="sync_mailerlite_group_data">
                            <?php _e('Sync Groups', 'bema-crm'); ?>
                        </button>
                    </div>

                    <div class="sync-operation">
                        <h3><?php _e('Subscribers', 'bema-crm'); ?></h3>
                        <p><?php _e('Sync subscribers from MailerLite.', 'bema-crm'); ?></p>
                        <button type="button" class="button button-primary sync-operation-btn" 
                                data-operation="sync_subscribers">
                            <?php _e('Sync Subscribers', 'bema-crm'); ?>
                        </button>
                    </div>
                </div>

                <div class="full-sync-section">
                    <h3><?php _e('Complete Sync', 'bema-crm'); ?></h3>
                    <p><?php _e('Perform a complete synchronization of all MailerLite data.', 'bema-crm'); ?></p>
                    <button type="button" class="button button-secondary" id="full-sync-btn">
                        <?php _e('Start Full Sync', 'bema-crm'); ?>
                    </button>
                </div>

                <div id="sync-status" class="sync-status"></div>
            </div>
        </div>
    </div>

    <!-- Campaign Transitions Tab -->
    <div id="campaign-transitions" class="tab-content" style="display: none;">
        <div class="postbox">
            <h2 class="hndle"><?php _e('Campaign Transitions', 'bema-crm'); ?></h2>
            <div class="inside">
                <p><?php _e('Use the Transition_Manager to move subscribers between campaigns:', 'bema-crm'); ?></p>
                
                <form id="transition-form" class="transition-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="source-campaign"><?php _e('Source Campaign', 'bema-crm'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="source-campaign" name="source_campaign" 
                                       class="regular-text" placeholder="<?php _e('Enter source campaign name', 'bema-crm'); ?>" required />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="destination-campaign"><?php _e('Destination Campaign', 'bema-crm'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="destination-campaign" name="destination_campaign" 
                                       class="regular-text" placeholder="<?php _e('Enter destination campaign name', 'bema-crm'); ?>" required />
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary" id="transition-btn">
                            <?php _e('Start Transition', 'bema-crm'); ?>
                        </button>
                    </p>
                </form>

                <div id="transition-status" class="transition-status"></div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Tab functionality
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        const targetTab = $(this).data('tab');
        
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        $('.tab-content').hide();
        $('#' + targetTab).show();
    });

    // Individual sync operations
    $('.sync-operation-btn').on('click', function() {
        const operation = $(this).data('operation');
        const button = $(this);
        const originalText = button.text();
        
        button.prop('disabled', true).text('<?php _e('Processing...', 'bema-crm'); ?>');
        
        performSyncOperation(operation, function(success, message) {
            button.prop('disabled', false).text(originalText);
            showSyncStatus(message, success ? 'success' : 'error');
        });
    });

    // Full sync
    $('#full-sync-btn').on('click', function() {
        const button = $(this);
        const originalText = button.text();
        
        button.prop('disabled', true).text('<?php _e('Processing...', 'bema-crm'); ?>');
        
        performSyncOperation('sync_all_mailerlite_data', function(success, message) {
            button.prop('disabled', false).text(originalText);
            showSyncStatus(message, success ? 'success' : 'error');
        });
    });

    // Campaign transition
    $('#transition-form').on('submit', function(e) {
        e.preventDefault();
        
        const sourceCampaign = $('#source-campaign').val().trim();
        const destinationCampaign = $('#destination-campaign').val().trim();
        
        if (!sourceCampaign || !destinationCampaign) {
            showTransitionStatus('<?php _e('Please fill in both campaign names', 'bema-crm'); ?>', 'error');
            return;
        }

        const button = $('#transition-btn');
        const originalText = button.text();
        
        button.prop('disabled', true).text('<?php _e('Processing...', 'bema-crm'); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bema_transition_campaigns',
                nonce: '<?php echo wp_create_nonce('bema_admin_nonce'); ?>',
                source_campaign: sourceCampaign,
                destination_campaign: destinationCampaign
            },
            success: function(response) {
                const success = response.success;
                const message = success ? response.data.message : response.data.message;
                showTransitionStatus(message, success ? 'success' : 'error');
            },
            error: function() {
                showTransitionStatus('<?php _e('Request failed', 'bema-crm'); ?>', 'error');
            },
            complete: function() {
                button.prop('disabled', false).text(originalText);
            }
        });
    });

    function performSyncOperation(operation, callback) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bema_sync_with_manager',
                nonce: '<?php echo wp_create_nonce('bema_admin_nonce'); ?>',
                operation: operation
            },
            success: function(response) {
                callback(response.success, response.data.message);
            },
            error: function() {
                callback(false, '<?php _e('Request failed', 'bema-crm'); ?>');
            }
        });
    }

    function showSyncStatus(message, type) {
        const alertClass = type === 'success' ? 'notice-success' : 'notice-error';
        $('#sync-status').html(`<div class="notice ${alertClass} is-dismissible"><p>${message}</p></div>`);
    }

    function showTransitionStatus(message, type) {
        const alertClass = type === 'success' ? 'notice-success' : 'notice-error';
        $('#transition-status').html(`<div class="notice ${alertClass} is-dismissible"><p>${message}</p></div>`);
    }
});
</script>

<style>
.sync-operations-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.sync-operation {
    border: 1px solid #ddd;
    padding: 15px;
    border-radius: 4px;
    background: #f9f9f9;
}

.sync-operation h3 {
    margin-top: 0;
    margin-bottom: 10px;
}

.sync-operation p {
    margin-bottom: 15px;
    color: #666;
}

.full-sync-section {
    border-top: 1px solid #ddd;
    padding-top: 20px;
    margin-top: 20px;
}

.tab-content {
    margin-top: 20px;
}

.sync-status, .transition-status {
    margin-top: 20px;
}

.transition-form {
    max-width: 600px;
}
</style>
