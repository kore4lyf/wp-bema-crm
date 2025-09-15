<?php

use Bema\Database\Campaign_Database_Manager;
use Bema\Database\Transition_Database_Manager;
use Bema\Database\Transition_Subscribers_Database_Manager;
use Bema\Transition_Manager;

$campaign_database = new Campaign_Database_Manager();
$transition_database = new Transition_Database_Manager();
$transition_subscribers_database = new Transition_Subscribers_Database_Manager();

$transition_manager = new Transition_Manager();
$transition_manager->campaign_database = $campaign_database;
$transition_manager->transition_database = $transition_database;
$transition_manager->transition_subscribers_database = $transition_subscribers_database;

$available_campaigns = $campaign_database->get_all_campaigns();
$transition_history = $transition_database->get_all_records();

/**
 * Handles the form submission for campaign transition.
 */
function handle_campaign_transition($transition_manager)
{
    $source_campaign = isset($_POST['source_campaign']) ? sanitize_text_field($_POST['source_campaign']) : '';
    $destination_campaign = isset($_POST['destination_campaign']) ? sanitize_text_field($_POST['destination_campaign']) : '';

    $transition_manager->transition_campaigns($source_campaign, $destination_campaign);
}

// Check if the form has been submitted
if (isset($_POST['submit_transition_button'])) {
    handle_campaign_transition($transition_manager);
}

?>

<div>
    <h2>Campaign Transition</h2>
    <p>Initiate a transition from an old campaign to a new one.</p>

    <form method="post">
        <?php wp_nonce_field('campaign_transition_nonce'); ?>
        <input type="hidden" name="action" value="campaign_transition_action">

        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><label for="source_campaign">Source Campaign</label></th>
                    <td>
                        <select name="source_campaign" id="source_campaign" class="regular-text" required>
                            <option value="">Select Source Campaign</option>
                            <?php foreach ($available_campaigns as $campaign): ?>
                                <option value="<?php _e(esc_attr($campaign['campaign']), 'bema-crm'); ?>" 
                                    <?php selected(isset($_POST['source_campaign']) ? $_POST['source_campaign'] : '', $campaign['campaign']); ?>>
                                    <?php _e(esc_html($campaign['campaign']), 'bema-crm'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Select the campaign to transition subscribers from.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="destination_campaign">Destination Campaign</label></th>
                    <td>
                        <select name="destination_campaign" id="destination_campaign" class="regular-text" required>
                            <option value="">Select Destination Campaign</option>
                            <?php foreach ($available_campaigns as $campaign): ?>
                                <option value="<?php _e(esc_attr($campaign['campaign']), 'bema-crm'); ?>"
                                    <?php selected(isset($_POST['destination_campaign']) ? $_POST['destination_campaign'] : '', $campaign['campaign']); ?>>
                                    <?php _e(esc_html($campaign['campaign']), 'bema-crm'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Select the campaign to transition subscribers to.</p>
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="submit">
            <input type="submit" name="submit_transition_button" class="button-primary" 
                   value="<?php _e('Start Transition', 'bema-crm'); ?>" />
        </p>
    </form>

    <?php if (!empty($transition_history)): ?>
    <div class="transition-history" style="margin-top: 30px;">
        <h3><?php _e('Recent Transitions', 'bema-crm'); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Source Campaign', 'bema-crm'); ?></th>
                    <th><?php _e('Destination Campaign', 'bema-crm'); ?></th>
                    <th><?php _e('Status', 'bema-crm'); ?></th>
                    <th><?php _e('Subscribers Moved', 'bema-crm'); ?></th>
                    <th><?php _e('Date', 'bema-crm'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $transition_history ) ) : ?>
                <?php foreach ( $transition_history as $row ) : ?>
                    <tr>
                        <td><?php _e(esc_html( $row['source'] , 'bema-crm')); ?></td>
                        <td><?php _e(esc_html( $row['destination'] ), 'bema-crm'); ?></td>
                        <td><?php _e(esc_html( $row['status'] ), 'bema-crm'); ?></td>
                        <td><?php _e(esc_html( $row['subscribers'] ), 'bema-crm'); ?></td>
                        <td>
                            <?php 
                                try {
                                    $dateString = '2025-09-10 09:50:31';
                                    
                                    $date_time_obj = new DateTime($dateString);
                                    $readable_date_time = $date_time_obj->format('F j, Y, g:i a');
                                    _e($readable_date_time, 'bema-crm');

                                } catch (Exception $e) {
                                    _e('—', 'bema-crm');
                                    $admin->$logger('Error: ' . esc_html($e->getMessage()));
                                }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td class="text-center" colspan="5">No transition history found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
