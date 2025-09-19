<?php

use Bema\Manager_Factory;

// Get database managers from main plugin instance

$campaign_database = new \Bema\Database\Campaign_Database_Manager();
$transition_manager = Manager_Factory::get_transition_manager();

$available_campaigns = $campaign_database->get_all_campaigns();

/**
 * Handles the form submission for campaign transition.
 */
function handle_campaign_transition($transition_manager)
{
    if (!isset($_POST['campaign_transition_nonce']) || !wp_verify_nonce($_POST['campaign_transition_nonce'], 'campaign_transition_nonce')) {
        wp_die('Invalid nonce specified', 'Error', ['response' => 403]);
    }

    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.', 'Error', ['response' => 403]);
    }

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
</div>
