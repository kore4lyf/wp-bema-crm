<?php
/**
 * Sync Management using new Sync_Manager and Transition_Manager
 */

namespace Bema\Admin\Views;

if (!defined('ABSPATH')) {
    exit;
}

use Bema\Manager_Factory;

// Get managers
$sync_manager = Manager_Factory::get_sync_manager();
$transition_manager = Manager_Factory::get_transition_manager();
?>

<div class="wrap bema-sync-managers">
    <h1><?php _e('Sync Management (New Managers)', 'bema-crm'); ?></h1>

    <div class="nav-tab-wrapper">
        <a href="#sync-tab" class="nav-tab nav-tab-active" data-tab="sync-tab">
            <?php _e('Sync Operations', 'bema-crm'); ?>
        </a>
        <a href="#transition-tab" class="nav-tab" data-tab="transition-tab">
            <?php _e('Campaign Transitions', 'bema-crm'); ?>
        </a>
    </div>

    <!-- Sync Operations Tab -->
    <div id="sync-tab" class="tab-content active">
        <div class="postbox">
            <h2><?php _e('MailerLite Sync Operations', 'bema-crm'); ?></h2>
            <div class="inside">
                <p><?php _e('Use these buttons to perform specific sync operations.', 'bema-crm'); ?></p>
                
                <div class="sync-buttons">
                    <button type="button" class="button button-primary" id="sync-campaigns">
                        <?php _e('Sync Campaign Data', 'bema-crm'); ?>
                    </button>
                    
                    <button type="button" class="button button-primary" id="sync-fields">
                        <?php _e('Sync Field Data', 'bema-crm'); ?>
                    </button>
                    
                    <button type="button" class="button button-primary" id="sync-groups">
                        <?php _e('Sync Group Data', 'bema-crm'); ?>
                    </button>
                    
                    <button type="button" class="button button-primary" id="sync-subscribers">
                        <?php _e('Sync Subscribers', 'bema-crm'); ?>
                    </button>
                    
                    <button type="button" class="button button-secondary" id="sync-all">
                        <?php _e('Sync All Data', 'bema-crm'); ?>
                    </button>
                </div>

                <div id="sync-results" class="sync-results" style="margin-top: 20px;"></div>
            </div>
        </div>
    </div>

    <!-- Campaign Transitions Tab -->
    <div id="transition-tab" class="tab-content" style="display: none;">
        <div class="postbox">
            <h2><?php _e('Campaign Transitions', 'bema-crm'); ?></h2>
            <div class="inside">
                <p><?php _e('Transition subscribers between campaigns.', 'bema-crm'); ?></p>
                
                <form id="transition-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="source-campaign"><?php _e('Source Campaign', 'bema-crm'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="source-campaign" name="source_campaign" class="regular-text" required />
                                <p class="description"><?php _e('Enter the name of the source campaign', 'bema-crm'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="destination-campaign"><?php _e('Destination Campaign', 'bema-crm'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="destination-campaign" name="destination_campaign" class="regular-text" required />
                                <p class="description"><?php _e('Enter the name of the destination campaign', 'bema-crm'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary" id="start-transition">
                            <?php _e('Start Transition', 'bema-crm'); ?>
                        </button>
                    </p>
                </form>

                <div id="transition-results" class="transition-results" style="margin-top: 20px;"></div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Tab switching
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        const targetTab = $(this).data('tab');
        
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        $('.tab-content').hide();
        $('#' + targetTab).show();
    });

    // Sync operations
    $('#sync-campaigns').on('click', function() {
        performSyncOperation('sync_album_campaign_data', 'Campaign data sync');
    });

    $('#sync-fields').on('click', function() {
        performSyncOperation('sync_mailerlite_field_data', 'Field data sync');
    });

    $('#sync-groups').on('click', function() {
        performSyncOperation('sync_mailerlite_group_data', 'Group data sync');
    });

    $('#sync-subscribers').on('click', function() {
        performSyncOperation('sync_subscribers', 'Subscriber sync');
    });

    $('#sync-all').on('click', function() {
        performSyncOperation('sync_all_mailerlite_data', 'Full sync');
    });

    // Campaign transition
    $('#transition-form').on('submit', function(e) {
        e.preventDefault();
        
        const sourceCampaign = $('#source-campaign').val();
        const destinationCampaign = $('#destination-campaign').val();
        
        if (!sourceCampaign || !destinationCampaign) {
            showResult('#transition-results', 'Please fill in both campaign names', 'error');
            return;
        }

        $('#start-transition').prop('disabled', true).text('Processing...');
        
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
                if (response.success) {
                    showResult('#transition-results', response.data.message, 'success');
                } else {
                    showResult('#transition-results', response.data.message, 'error');
                }
            },
            error: function() {
                showResult('#transition-results', 'AJAX request failed', 'error');
            },
            complete: function() {
                $('#start-transition').prop('disabled', false).text('<?php _e('Start Transition', 'bema-crm'); ?>');
            }
        });
    });

    function performSyncOperation(operation, description) {
        const button = $(`#${operation.replace('_', '-')}`);
        const originalText = button.text();
        
        button.prop('disabled', true).text('Processing...');
        showResult('#sync-results', `Starting ${description}...`, 'info');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bema_sync_with_manager',
                nonce: '<?php echo wp_create_nonce('bema_admin_nonce'); ?>',
                operation: operation
            },
            success: function(response) {
                if (response.success) {
                    showResult('#sync-results', `${description} completed successfully`, 'success');
                } else {
                    showResult('#sync-results', response.data.message, 'error');
                }
            },
            error: function() {
                showResult('#sync-results', `${description} failed - AJAX error`, 'error');
            },
            complete: function() {
                button.prop('disabled', false).text(originalText);
            }
        });
    }

    function showResult(container, message, type) {
        const alertClass = type === 'success' ? 'notice-success' : 
                          type === 'error' ? 'notice-error' : 'notice-info';
        
        $(container).html(`<div class="notice ${alertClass} is-dismissible"><p>${message}</p></div>`);
    }
});
</script>

<style>
.sync-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.sync-buttons .button {
    min-width: 150px;
}

.tab-content {
    margin-top: 20px;
}

.sync-results, .transition-results {
    min-height: 50px;
}
</style>
