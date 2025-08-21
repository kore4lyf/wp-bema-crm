<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) exit;

// Fetch saved data from the database
$saved_tiers = get_option('bema_crm_tiers', []);
$saved_tiers = is_array($saved_tiers) ? $saved_tiers : [];

$transition_matrix = get_option('bema_crm_transition_matrix', []);
$transition_matrix = is_array($transition_matrix) ? $transition_matrix : [];
?>

<div class="wrap bema-crm-tm-wrap">
    <div class="settings-section bema-crm-lite-section">
        <h2>Transition Matrix</h2>

        <form method="post" action="options.php" class="bema-crm-tm-form">
            <?php
                settings_fields('bema_crm_transition_matrix_group');
                do_settings_sections('bema_crm_transition_matrix');
            ?>

            <table class="widefat fixed striped bema-crm-tm-table wp-list-table">
                <thead>
                    <tr>
                        <th class="bema-crm-tm-column-current-tier"><b>Current Tier</b></th>
                        <th class="bema-crm-tm-column-next-tier"><b>Next Campaign Tier</b></th>
                        <th class="bema-crm-tm-column-purchase-required"><b>Purchase Required</b></th>
                        <th class="bema-crm-tm-column-actions"><b>Actions</b></th>
                    </tr>
                </thead>
                <tbody class="bema-crm-tm-rows">
                    <?php
                    if (!empty($transition_matrix)):
                        foreach ($transition_matrix as $index => $row):
                    ?>
                        <tr class="bema-crm-tm-row">
                            <td class="bema-crm-tm-current-tier">
                                <span class="bema-crm-tm-current-tier-text">
                                    <?php echo esc_html($row['current_tier']); ?>
                                </span>
                                <select name="bema_crm_transition_matrix[<?php echo esc_attr($index); ?>][current_tier]" class="bema-crm-tm-current-tier-select" style="display:none;">
                                    <?php foreach ($saved_tiers as $tier_name): ?>
                                        <option value="<?php echo esc_attr($tier_name); ?>" <?php selected($tier_name, $row['current_tier']); ?>>
                                            <?php echo esc_html($tier_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="bema-crm-tm-next-tier">
                                <span class="bema-crm-tm-next-tier-text">
                                    <?php echo esc_html($row['next_tier']); ?>
                                </span>
                                <select name="bema_crm_transition_matrix[<?php echo esc_attr($index); ?>][next_tier]" class="bema-crm-tm-next-tier-select" style="display:none;">
                                    <?php foreach ($saved_tiers as $tier_name): ?>
                                        <option value="<?php echo esc_attr($tier_name); ?>" <?php selected($tier_name, $row['next_tier']); ?>>
                                            <?php echo esc_html($tier_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="bema-crm-tm-purchase-required">
                                <span class="bema-crm-tm-purchase-required-text">
                                    <?php echo $row['requires_purchase'] ? '✓ Yes' : '✗ No'; ?>
                                </span>
                                <input type="hidden" name="bema_crm_transition_matrix[<?php echo esc_attr($index); ?>][requires_purchase]" value="<?php echo esc_attr($row['requires_purchase'] ? '1' : '0'); ?>" class="bema-crm-tm-purchase-required-hidden" />
                                <input type="checkbox" class="bema-crm-tm-purchase-required-checkbox" <?php checked($row['requires_purchase']); ?> style="display:none;" />
                            </td>
                            <td class="bema-crm-tm-action-buttons">
                                <button type="button" class="button bema-crm-tm-edit-button">Edit</button>
                                <button type="button" class="button bema-crm-tm-save-button" style="display:none;">Save</button>
                                <button type="button" class="button button-secondary danger-button bema-crm-tm-remove-button" style="display:none;">Remove</button>
                            </td>
                        </tr>
                    <?php
                        endforeach;
                    endif;
                    ?>
                </tbody>
            </table>

            <p>
                <button type="button" class="button" id="bema-crm-tm-add-row-button">Add Transition Rule</button>
            </p>

            <?php submit_button('Save Transition Matrix'); ?>
        </form>
    </div>
</div>