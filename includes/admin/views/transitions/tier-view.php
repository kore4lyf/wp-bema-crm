<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) exit;

// Fetch saved tiers from DB.
$tiers = get_option('bema_crm_tiers', []);
$tiers = is_array($tiers) ? $tiers : [];
?>

<div class="wrap">
    <div class="settings-section bema-crm-lite-section">
        <h2>Tier Configuration</h2>

        <form method="post" action="options.php">
            <?php
                settings_fields('bema_crm_tiers_group');
                do_settings_sections('bema_crm_tiers');
            ?>

            <table class="widefat fixed striped tier-table wp-list-table">
                <thead>
                    <tr>
                        <th><b>Tier Name</b></th>
                        <th><b>Actions</b></th>
                    </tr>
                </thead>
                <tbody id="tier-rows">
                    <?php foreach ($tiers as $name): ?>
                        <tr>
                            <td>
                                <span class="tier-name-text"><?php echo esc_html($name); ?></span>
                                <input type="text" name="bema_crm_tiers_names[]" value="<?php echo esc_attr($name); ?>" class="regular-text tier-name" style="display:none;" />
                            </td>
                            <td>
                                <button type="button" class="button edit-tier">Edit</button>
                                <button type="button" class="button save-tier" style="display:none;">Save</button>
                                <button type="button" class="button button-secondary danger-button remove-tier" style="display:none;">Remove</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <button type="button" class="button" id="add-tier">Add Tier</button>
            </p>

            <?php submit_button('Save Tiers'); ?>
        </form>
    </div>
</div>