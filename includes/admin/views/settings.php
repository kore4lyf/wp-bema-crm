<?php

namespace Bema\Admin\Views;

use function Bema\debug_to_file;
use Bema\BemaCRMLogger;

$logger = new BemaCRMLogger();

if (!defined('ABSPATH')) {
    exit;
}

if (!isset($admin) || !($admin instanceof \Bema\Admin\Bema_Admin_Interface)) {
    wp_die('Invalid admin interface initialization');
}

// Get current settings and sync capability
$current_settings = $admin->get_settings();
$has_sync = $admin->has_sync_capability();
$sync_disabled = !$has_sync;

// Debug logging to verify values
debug_to_file([
    'has_sync' => $has_sync ? 'yes' : 'no',
    'sync_disabled' => $sync_disabled ? 'yes' : 'no',
    'current_settings' => !empty($current_settings) ? 'present' : 'empty'
], 'SETTINGS_PAGE_LOAD');

// Display warning only if EDD is not active
if ($sync_disabled) {
    printf(
        '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
        esc_html__('To use all features, please ensure Easy Digital Downloads Pro is properly configured.', 'bema-crm')
    );
}

// Display API test results if they exist
$test_results = get_transient('bema_api_test_results');
if ($test_results && is_array($test_results['messages'])) {
    foreach ($test_results['messages'] as $message) {
        $notice_class = (strpos($message, 'successful') !== false) ? 'notice-success' : 'notice-error';
?>
        <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
<?php
    }
    delete_transient('bema_api_test_results');
}
?>

<div class="wrap bema-settings">
    <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php
    $sync_disabled = !$has_sync;
    if ($sync_disabled): ?>
        <div class="notice notice-warning">
            <p><?php _e('Some settings are disabled because Easy Digital Downloads is not active or not properly configured.', 'bema-crm'); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="options.php" id="bema-settings-form" class="settings-form">
        <?php
            settings_fields('bema_crm_settings');
        ?>

        <div class="settings-container">
            <!-- API Configuration Section -->
            <div class="settings-section<?php echo $sync_disabled ? ' disabled' : ''; ?>">
                <h2><?php _e('API Configuration', 'bema-crm'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="mailerlite_api_key"><?php _e('MailerLite API Key', 'bema-crm'); ?></label>
                        </th>
                        <td>
                            <input type="password"
                                id="mailerlite_api_key"
                                name="bema_crm_settings[api][mailerlite_api_key]"
                                value="<?php echo esc_attr($current_settings['api']['mailerlite_api_key'] ?? ''); ?>"
                                class="regular-text">
                            <p class="description">
                                <?php _e('Enter your MailerLite API key to enable integration.', 'bema-crm'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="edd_api_key"><?php _e('EDD API Key', 'bema-crm'); ?></label>
                        </th>
                        <td>
                            <input type="password"
                                id="edd_api_key"
                                name="bema_crm_settings[api][edd_api_key]"
                                value="<?php echo esc_attr($current_settings['api']['edd_api_key'] ?? ''); ?>"
                                class="regular-text">
                            <p class="description">
                                <?php _e('Enter your Easy Digital Downloads API key.', 'bema-crm'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="edd_token"><?php _e('EDD Token', 'bema-crm'); ?></label>
                        </th>
                        <td>
                            <input type="password"
                                id="edd_token"
                                name="bema_crm_settings[api][edd_token]"
                                value="<?php echo esc_attr($current_settings['api']['edd_token'] ?? ''); ?>"
                                class="regular-text">
                            <p class="description">
                                <?php _e('Enter your Easy Digital Downloads API Token.', 'bema-crm'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="api_timeout"><?php _e('API Timeout', 'bema-crm'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                id="api_timeout"
                                name="bema_crm_settings[api][timeout]"
                                value="<?php echo esc_attr($current_settings['api']['timeout'] ?? 30); ?>"
                                min="10"
                                max="120"
                                class="small-text">
                            <span class="description"><?php _e('seconds', 'bema-crm'); ?></span>
                            <p class="description">
                                <?php _e('Maximum time to wait for API responses.', 'bema-crm'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Sync Settings Section -->
            <div class="settings-section<?php echo $sync_disabled ? ' disabled' : ''; ?>">
                <h2><?php _e('Sync Settings', 'bema-crm'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="batch_size"><?php _e('Batch Size', 'bema-crm'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                id="batch_size"
                                name="bema_crm_settings[sync][batch_size]"
                                value="<?php echo esc_attr($current_settings['sync']['batch_size'] ?? 1000); ?>"
                                min="100"
                                max="10000"
                                step="100"
                                class="small-text">
                            <p class="description">
                                <?php _e('Number of records to process in each batch.', 'bema-crm'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="retry_attempts"><?php _e('Retry Attempts', 'bema-crm'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                id="retry_attempts"
                                name="bema_crm_settings[sync][retry_attempts]"
                                value="<?php echo esc_attr($current_settings['sync']['retry_attempts'] ?? 3); ?>"
                                min="1"
                                max="10"
                                class="small-text">
                            <p class="description">
                                <?php _e('Number of times to retry failed operations.', 'bema-crm'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="memory_limit"><?php _e('Memory Limit', 'bema-crm'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                id="memory_limit"
                                name="bema_crm_settings[sync][memory_limit]"
                                value="<?php echo esc_attr($current_settings['sync']['memory_limit'] ?? '256M'); ?>"
                                pattern="^\d+[MG]$"
                                class="regular-text">
                            <p class="description">
                                <?php _e('Maximum memory limit for sync operations (e.g., 256M).', 'bema-crm'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Notifications Section -->
            <div class="settings-section<?php echo $sync_disabled ? ' disabled' : ''; ?>">
                <h2><?php _e('Notifications', 'bema-crm'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <?php _e('Email Notifications', 'bema-crm'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                    name="bema_crm_settings[notifications][enabled]"
                                    value="1"
                                    <?php checked($current_settings['notifications']['enabled'] ?? false); ?>>
                                <?php _e('Enable email notifications', 'bema-crm'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="notification_email"><?php _e('Notification Email', 'bema-crm'); ?></label>
                        </th>
                        <td>
                            <input type="email"
                                id="notification_email"
                                name="bema_crm_settings[notifications][email]"
                                value="<?php echo esc_attr($current_settings['notifications']['email'] ?? get_option('admin_email')); ?>"
                                class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="error_threshold"><?php _e('Error Threshold', 'bema-crm'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                id="error_threshold"
                                name="bema_crm_settings[notifications][error_threshold]"
                                value="<?php echo esc_attr($current_settings['notifications']['error_threshold'] ?? 10); ?>"
                                min="1"
                                max="100"
                                class="small-text">
                            <p class="description">
                                <?php _e('Number of errors before sending notification.', 'bema-crm'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Logging Section -->
            <div class="settings-section<?php echo $sync_disabled ? ' disabled' : ''; ?>">
                <h2><?php _e('Logging', 'bema-crm'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="log_level"><?php _e('Log Level', 'bema-crm'); ?></label>
                        </th>
                        <td>
                            <select id="log_level" name="bema_crm_settings[logging][level]">
                                <?php
                                $log_levels = [
                                    'debug' => __('Debug', 'bema-crm'),
                                    'info' => __('Info', 'bema-crm'),
                                    'warning' => __('Warning', 'bema-crm'),
                                    'error' => __('Error', 'bema-crm')
                                ];

                                foreach ($log_levels as $value => $label):
                                    $selected = ($current_settings['logging']['level'] ?? 'info') === $value ? 'selected' : '';
                                ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php echo $selected; ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="retention_days"><?php _e('Log Retention', 'bema-crm'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                id="retention_days"
                                name="bema_crm_settings[logging][retention_days]"
                                value="<?php echo esc_attr($current_settings['logging']['retention_days'] ?? 30); ?>"
                                min="1"
                                max="365"
                                class="small-text">
                            <span class="description"><?php _e('days', 'bema-crm'); ?></span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Advanced Settings -->
            <div class="settings-section<?php echo $sync_disabled ? ' disabled' : ''; ?>">
                <h2><?php _e('Advanced Settings', 'bema-crm'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <?php _e('Debug Mode', 'bema-crm'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                    name="bema_crm_settings[advanced][debug_mode]"
                                    value="1"
                                    <?php checked($current_settings['advanced']['debug_mode'] ?? false); ?>>
                                <?php _e('Enable debug mode', 'bema-crm'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Enables detailed debugging information in logs.', 'bema-crm'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <?php submit_button(); ?>
    </form>

    <!-- Additional Actions -->
    <div class="settings-section<?php echo $sync_disabled ? ' disabled' : ''; ?>">
        <h2><?php _e('Additional Actions', 'bema-crm'); ?></h2>

        <div class="action-buttons">
            <!-- Test Connection -->
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="inline-form">
                <input type="hidden" name="action" value="test_connection">
                <?php wp_nonce_field('bema_test_connection'); ?>
                <button type="submit" class="button">
                    <?php _e('Test API Connection', 'bema-crm'); ?>
                </button>
            </form>

            <!-- Clear Cache -->
            <form method="post" class="inline-form">
                <?php wp_nonce_field('bema_clear_cache'); ?>
                <input type="hidden" name="action" value="clear_cache">
                <button type="submit" class="button">
                    <?php _e('Clear Cache', 'bema-crm'); ?>
                </button>
            </form>

            <!-- Reset Settings -->
            <form method="post" class="inline-form" id="reset-settings-form">
                <?php wp_nonce_field('bema_reset_settings'); ?>
                <input type="hidden" name="action" value="reset_settings">
                <button type="submit" class="button">
                    <?php _e('Reset to Defaults', 'bema-crm'); ?>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    jQuery(document).ready(function($) {
        // Form validation
        $('#bema-settings-form').on('submit', function(e) {
            const memoryLimit = $('#memory_limit').val();
            if (!memoryLimit.match(/^\d+[MG]$/)) {
                e.preventDefault();
                alert('<?php _e('Invalid memory limit format. Please use format like 256M or 1G.', 'bema-crm'); ?>');
                return false;
            }
        });

        // Confirm reset
        $('#reset-settings-form').on('submit', function(e) {
            if (!confirm('<?php _e('Are you sure you want to reset all settings to default values? This cannot be undone.', 'bema-crm'); ?>')) {
                e.preventDefault();
                return false;
            }
        });

        // Toggle password visibility
        $('.toggle-password').on('click', function() {
            const input = $(this).prev('input');
            if (input.attr('type') === 'password') {
                input.attr('type', 'text');
                $(this).text('<?php _e('Hide', 'bema-crm'); ?>');
            } else {
                input.attr('type', 'password');
                $(this).text('<?php _e('Show', 'bema-crm'); ?>');
            }
        });

        // Show warning when leaving page with unsaved changes
        let formChanged = false;
        $('#bema-settings-form :input').on('change', function() {
            formChanged = true;
        });

        $(window).on('beforeunload', function() {
            if (formChanged) {
                return '<?php _e('You have unsaved changes. Are you sure you want to leave?', 'bema-crm'); ?>';
            }
        });

        $('#bema-settings-form').on('submit', function() {
            formChanged = false;
        });
    });
</script>

<style>
    h1.wp-heading-inline {
        margin-bottom: 24px;
    }

    .settings-section.disabled {
        opacity: 0.5;
        pointer-events: none;
    }

    .settings-section.disabled input,
    .settings-section.disabled select {
        background: #f5f5f5;
    }

    .settings-container {
        max-width: 1200px;
    }

    .settings-section {
        background: #fff;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid #ccd0d4;
        box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
    }

    .inline-form {
        display: inline-block;
        margin-right: 10px;
    }

    .action-buttons {
        margin-top: 20px;
    }

    .form-table td {
        vertical-align: top;
    }

    .description {
        margin-top: 5px;
        color: #666;
    }
</style>