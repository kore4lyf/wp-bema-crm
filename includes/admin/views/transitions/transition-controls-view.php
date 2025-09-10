<?php

use Bema\Database\Campaign_Database_Manager;
use Bema\Database\Transition_Database_Manager;
use Bema\Database\Transition_Subscribers_Database_Manager;

$campaign_database = new Campaign_Database_Manager();
$transition_database = new Transition_Database_Manager();
$transition_subscribers_database = new Transition_Subscribers_Database_Manager();

$available_campaigns = $campaign_database->get_all_campaigns();

$transition_history = $transition_database->get_all_records();

/**
 * Handles the form submission for campaign transition.
 */
function handle_campaign_transition($sync_instance)
{

    // Retrieve the values from the select options
    $source_campaign = isset($_POST['source_campaign']) ? sanitize_text_field($_POST['source_campaign']) : '';
    $destination_campaign = isset($_POST['destination_campaign']) ? sanitize_text_field($_POST['destination_campaign']) : '';

    $sync_instance->transition_campaigns($source_campaign, $destination_campaign);
}

// Check if the form has been submitted by checking for the submit button's name.
if (isset($_POST['submit_transition_button'])) {
    handle_campaign_transition($admin->sync_instance);
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
                    <th scope="row"><label for="source_campaign">Source</label></th>
                    <td>
                        <select name="source_campaign" id="source_campaign" class="regular-text">
                            <option value="">Campaign</option>
                            <?php foreach ($available_campaigns as $campaign): ?>
                                <option value="<?php echo esc_attr($campaign['campaign']); ?>">
                                    <?php echo esc_html($campaign['campaign']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="destination_campaign">Destination</label></th>
                    <td>
                        <select name="destination_campaign" id="destination_campaign" class="regular-text">
                            <option value="">Campaign</option>
                            <?php foreach ($available_campaigns as $campaign): ?>
                                <option value="<?php echo esc_attr($campaign['campaign']); ?>">
                                    <?php echo esc_html($campaign['campaign']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button('Start Transition', 'primary', 'submit_transition_button'); ?>
    </form>
</div>

<hr />

<div>
    <h2>Transition History</h2>
    <p>A record of all past campaign transitions.</p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col" class="manage-column">Source</th>
                <th scope="col" class="manage-column">Destination</th>
                <th scope="col" class="manage-column">Subscribers</th>
                <th scope="col" class="manage-column">Status</th>
                <th scope="col" class="manage-column">Date</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($transition_history)): ?>
                <?php foreach ($transition_history as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row['source']); ?></td>
                        <td><?php echo esc_html($row['destination']); ?></td>
                        <td><?php echo esc_html($row['subscribers']); ?></td>
                        <td><?php echo esc_html($row['status']); ?></td>
                        <td><?php
                        try {
                            $dateString = esc_html($row['transition_date']);
                            $dateTime = new DateTime($dateString);
                            echo $dateTime->format('F j, Y g:i A');
                        } catch (Exception $e) {
                            $logger->error('ERROR converting date: ' . $e->getMessage(), $row['transition_date']);
                            echo '—';
                        }
                        ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td class="text-center" colspan="5">No transition history found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>


<style>
    .text-center {
        text-align: center;
    }
</style>