<?php

namespace Bema;

use Exception;
use Bema\Providers\EDD;
use Bema\Providers\MailerLite;

if (!defined('ABSPATH')) {
    exit;
}

class Bema_Settings
{
    private $options_key = 'bema_crm_settings';
    private $logger;
    private $default_settings;
    private $settings_cache = null;
    private $last_error = null;
    private $is_getting_settings = false;
    private static $instance = null;

    const SETTINGS_VERSION = '1.0.0';
    const CACHE_TTL = 3600; // 1 hour

    // tiers option name and group
    private $tiers_option_group = 'bema_crm_tiers_group';
    private $tiers_option_name  = 'bema_crm_tiers';

    // tier transition matrix option name and group
    private $tier_transition_matrix_group = 'bema_crm_transition_matrix_group';
    private $tier_transition_matrix_name = 'bema_crm_transition_matrix';

    public static function get_instance(Bema_CRM_Logger $logger): self
    {
        if (null === self::$instance) {
            self::$instance = new self($logger);
        }
        return self::$instance;
    }

    private function __construct(Bema_CRM_Logger $logger)
    {
        try {
            $this->logger = $logger;
            $this->init_default_settings();

            // Initialize settings immediately but only once
            if (!defined('BEMA_SETTINGS_INITIALIZED')) {
                define('BEMA_SETTINGS_INITIALIZED', true);
                $this->ensure_settings_initialized();
            }

            // Add hooks after initialization
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_init', [$this, 'handle_settings_actions']);
            add_action('admin_init', [$this, 'tier_settings']);
            add_action('admin_init', [$this, 'transition_matrix_settings']);
            add_action('admin_notices', [$this, 'display_settings_notices']);
            
        } catch (Exception $e) {
            error_log('Bema Settings initialization error: ' . $e->getMessage());
        }
    }

    public function ensure_settings_initialized(): void
    {
        try {
            debug_to_file('Ensuring settings are initialized');
            if (get_option($this->options_key, false) === false) {
                debug_to_file('Initializing default settings');
                update_option($this->options_key, $this->default_settings);
            }
        } catch (Exception $e) {
            debug_to_file('Error ensuring settings: ' . $e->getMessage());
        }
    }

    private function init_default_settings(): void
    {
        $this->default_settings = [
            'api' => [
                'mailerlite_api_key' => '',
                'edd_api_key' => '',
                'edd_token' => '',
                'api_timeout' => 30,
                'verify_ssl' => true
            ],
            'sync' => [
                'batch_size' => 1000,
                'retry_attempts' => 3,
                'retry_delay' => 300,
                'memory_limit' => '256M',
                'execution_timeout' => 3600,
                'lock_timeout' => 900
            ],
            'notifications' => [
                'email_notifications' => true,
                'notification_email' => get_option('admin_email'),
                'error_threshold' => 10,
                'notification_frequency' => 3600
            ],
            'logging' => [
                'enabled' => true,
                'level' => 'info',
                'retention_days' => 30,
                'max_file_size' => 10485760 // 10MB
            ],
            'campaign' => [
                'max_campaigns_per_year' => 12,
                'min_campaign_interval' => 30,
                'required_groups' => [
                    'opt-in',
                    'gold',
                    'gold_purchased',
                    'silver',
                    'silver_purchased',
                    'bronze',
                    'bronze_purchased',
                    'wood'
                ]
            ],
            'advanced' => [
                'debug_mode' => false,
                'parallel_processing' => false,
                'max_parallel_jobs' => 2,
                'cache_ttl' => self::CACHE_TTL
            ]
        ];
    }

    public function register_settings(): void
    {
        register_setting(
            'bema_crm_settings',
            $this->options_key,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => $this->default_settings
            ]
        );

        $this->add_settings_sections();
    }

    private function add_settings_sections(): void
    {
        // API Settings Section
        add_settings_section(
            'bema_api_settings',
            __('API Settings', 'bema-crm'),
            [$this, 'render_api_section'],
            'bema-crm-settings'
        );

        // Sync Settings Section
        add_settings_section(
            'bema_sync_settings',
            __('Sync Settings', 'bema-crm'),
            [$this, 'render_sync_section'],
            'bema-crm-settings'
        );

        // Notification Settings Section
        add_settings_section(
            'bema_notification_settings',
            __('Notification Settings', 'bema-crm'),
            [$this, 'render_notification_section'],
            'bema-crm-settings'
        );

        // Logging Settings Section
        add_settings_section(
            'bema_logging_settings',
            __('Logging Settings', 'bema-crm'),
            [$this, 'render_logging_section'],
            'bema-crm-settings'
        );

        // Campaign Settings Section
        add_settings_section(
            'bema_campaign_settings',
            __('Campaign Settings', 'bema-crm'),
            [$this, 'render_campaign_section'],
            'bema-crm-settings'
        );

        // Advanced Settings Section
        add_settings_section(
            'bema_advanced_settings',
            __('Advanced Settings', 'bema-crm'),
            [$this, 'render_advanced_section'],
            'bema-crm-settings'
        );

        $this->add_settings_fields();
    }

    private function add_settings_fields(): void
    {
        // API Settings Fields
        $this->add_api_settings_fields();

        // Sync Settings Fields
        $this->add_sync_settings_fields();

        // Notification Settings Fields
        $this->add_notification_settings_fields();

        // Logging Settings Fields
        $this->add_logging_settings_fields();

        // Campaign Settings Fields
        $this->add_campaign_settings_fields();

        // Advanced Settings Fields
        $this->add_advanced_settings_fields();
    }

    private function add_api_settings_fields(): void
    {
        $api_fields = [
            'mailerlite_api_key' => [
                'label' => __('MailerLite API Key', 'bema-crm'),
                'type' => 'password'
            ],
            'edd_api_key' => [
                'label' => __('EDD API Key', 'bema-crm'),
                'type' => 'password'
            ],
            'edd_token' => [
                'label' => __('EDD Token', 'bema-crm'),
                'type' => 'password'
            ],
            'api_timeout' => [
                'label' => __('API Timeout (seconds)', 'bema-crm'),
                'type' => 'number',
                'min' => 10,
                'max' => 120
            ]
        ];

        foreach ($api_fields as $key => $field) {
            add_settings_field(
                "api[$key]",
                $field['label'],
                [$this, 'render_field'],
                'bema-crm-settings',
                'bema_api_settings',
                [
                    'key' => "api[$key]",
                    'type' => $field['type'],
                    'min' => $field['min'] ?? null,
                    'max' => $field['max'] ?? null
                ]
            );
        }
    }

    private function add_sync_settings_fields(): void
    {
        $sync_fields = [
            'batch_size' => [
                'label' => __('Batch Size', 'bema-crm'),
                'type' => 'number',
                'min' => 100,
                'max' => 10000,
                'description' => __('Number of records to process in each batch', 'bema-crm')
            ],
            'retry_attempts' => [
                'label' => __('Retry Attempts', 'bema-crm'),
                'type' => 'number',
                'min' => 1,
                'max' => 10,
                'description' => __('Number of times to retry failed operations', 'bema-crm')
            ],
            'retry_delay' => [
                'label' => __('Retry Delay (seconds)', 'bema-crm'),
                'type' => 'number',
                'min' => 60,
                'max' => 3600,
                'description' => __('Delay between retry attempts', 'bema-crm')
            ],
            'memory_limit' => [
                'label' => __('Memory Limit', 'bema-crm'),
                'type' => 'text',
                'pattern' => '^[0-9]+[MG]$',
                'description' => __('Memory limit for sync operations (e.g., 256M)', 'bema-crm')
            ],
            'execution_timeout' => [
                'label' => __('Execution Timeout (seconds)', 'bema-crm'),
                'type' => 'number',
                'min' => 300,
                'max' => 7200,
                'description' => __('Maximum execution time for sync operations', 'bema-crm')
            ]
        ];

        foreach ($sync_fields as $key => $field) {
            add_settings_field(
                "sync[$key]",
                $field['label'],
                [$this, 'render_field'],
                'bema-crm-settings',
                'bema_sync_settings',
                [
                    'key' => "sync[$key]",
                    'type' => $field['type'],
                    'description' => $field['description'],
                    'min' => $field['min'] ?? null,
                    'max' => $field['max'] ?? null,
                    'pattern' => $field['pattern'] ?? null
                ]
            );
        }
    }

    public function tier_settings() {
        register_setting(
            $this->tiers_option_group,
            $this->tiers_option_name,
            array(
                'type'              => 'array',
                'sanitize_callback' => array($this, 'sanitize_tiers'),
                'default'           => [],
            )
        );
    }

    
    public function transition_matrix_settings() {
        register_setting(
            $this->tier_transition_matrix_group,
            $this->tier_transition_matrix_name,
            array(
                'sanitize_callback' => array($this,'sanitize_transition_matrix'),
                'type'              => 'array'          ,
                'description'       => 'Defines the tier transition rules for Bema CRM.'
            )
        );
    }

    private function add_notification_settings_fields(): void
    {
        $notification_fields = [
            'email_notifications' => [
                'label' => __('Enable Email Notifications', 'bema-crm'),
                'type' => 'checkbox'
            ],
            'notification_email' => [
                'label' => __('Notification Email', 'bema-crm'),
                'type' => 'email'
            ],
            'error_threshold' => [
                'label' => __('Error Threshold', 'bema-crm'),
                'type' => 'number',
                'min' => 1,
                'max' => 100,
                'description' => __('Number of errors before notification', 'bema-crm')
            ]
        ];

        foreach ($notification_fields as $key => $field) {
            add_settings_field(
                "notifications[$key]",
                $field['label'],
                [$this, 'render_field'],
                'bema-crm-settings',
                'bema_notification_settings',
                [
                    'key' => "notifications[$key]",
                    'type' => $field['type'],
                    'description' => $field['description'] ?? '',
                    'min' => $field['min'] ?? null,
                    'max' => $field['max'] ?? null
                ]
            );
        }
    }

    private function add_logging_settings_fields(): void
    {
        $logging_fields = [
            'enabled' => [
                'label' => __('Enable Logging', 'bema-crm'),
                'type' => 'checkbox'
            ],
            'level' => [
                'label' => __('Log Level', 'bema-crm'),
                'type' => 'select',
                'options' => [
                    'debug' => 'Debug',
                    'info' => 'Info',
                    'warning' => 'Warning',
                    'error' => 'Error'
                ]
            ],
            'retention_days' => [
                'label' => __('Log Retention (days)', 'bema-crm'),
                'type' => 'number',
                'min' => 1,
                'max' => 365
            ],
            'max_file_size' => [
                'label' => __('Max Log File Size (MB)', 'bema-crm'),
                'type' => 'number',
                'min' => 1,
                'max' => 100
            ]
        ];

        foreach ($logging_fields as $key => $field) {
            add_settings_field(
                "logging[$key]",
                $field['label'],
                [$this, 'render_field'],
                'bema-crm-settings',
                'bema_logging_settings',
                [
                    'key' => "logging[$key]",
                    'type' => $field['type'],
                    'options' => $field['options'] ?? null,
                    'min' => $field['min'] ?? null,
                    'max' => $field['max'] ?? null
                ]
            );
        }
    }

    private function add_campaign_settings_fields(): void
    {
        $campaign_fields = [
            'max_campaigns_per_year' => [
                'label' => __('Max Campaigns Per Year', 'bema-crm'),
                'type' => 'number',
                'min' => 1,
                'max' => 52
            ],
            'min_campaign_interval' => [
                'label' => __('Min Campaign Interval (days)', 'bema-crm'),
                'type' => 'number',
                'min' => 1,
                'max' => 90
            ]
        ];

        foreach ($campaign_fields as $key => $field) {
            add_settings_field(
                "campaign[$key]",
                $field['label'],
                [$this, 'render_field'],
                'bema-crm-settings',
                'bema_campaign_settings',
                [
                    'key' => "campaign[$key]",
                    'type' => $field['type'],
                    'min' => $field['min'],
                    'max' => $field['max']
                ]
            );
        }
    }

    private function add_advanced_settings_fields(): void
    {
        $advanced_fields = [
            'debug_mode' => [
                'label' => __('Debug Mode', 'bema-crm'),
                'type' => 'checkbox',
                'description' => __('Enable detailed debugging information', 'bema-crm')
            ],
            'parallel_processing' => [
                'label' => __('Enable Parallel Processing', 'bema-crm'),
                'type' => 'checkbox',
                'description' => __('Process multiple batches simultaneously', 'bema-crm')
            ],
            'max_parallel_jobs' => [
                'label' => __('Max Parallel Jobs', 'bema-crm'),
                'type' => 'number',
                'min' => 1,
                'max' => 5,
                'description' => __('Maximum number of concurrent sync jobs', 'bema-crm')
            ]
        ];

        foreach ($advanced_fields as $key => $field) {
            add_settings_field(
                "advanced[$key]",
                $field['label'],
                [$this, 'render_field'],
                'bema-crm-settings',
                'bema_advanced_settings',
                [
                    'key' => "advanced[$key]",
                    'type' => $field['type'],
                    'description' => $field['description'],
                    'min' => $field['min'] ?? null,
                    'max' => $field['max'] ?? null
                ]
            );
        }
    }

    public function render_field($args): void
    {
        $options = $this->get_settings();
        $value = $this->get_setting_value($args['key'], $options);
        $name = $this->options_key . '[' . $args['key'] . ']';

        switch ($args['type']) {
            case 'password':
                printf(
                    '<input type="password" id="%s" name="%s" value="%s" class="regular-text">',
                    esc_attr($args['key']),
                    esc_attr($name),
                    esc_attr($value)
                );
                break;

            case 'number':
                printf(
                    '<input type="number" id="%s" name="%s" value="%s" class="small-text" min="%d" max="%d">',
                    esc_attr($args['key']),
                    esc_attr($name),
                    esc_attr($value),
                    esc_attr($args['min']),
                    esc_attr($args['max'])
                );
                break;

            case 'checkbox':
                printf(
                    '<input type="checkbox" id="%s" name="%s" value="1" %s>',
                    esc_attr($args['key']),
                    esc_attr($name),
                    checked($value, 1, false)
                );
                break;

            case 'select':
                echo '<select id="' . esc_attr($args['key']) . '" name="' . esc_attr($name) . '">';
                foreach ($args['options'] as $option_value => $option_label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($option_value),
                        selected($value, $option_value, false),
                        esc_html($option_label)
                    );
                }
                echo '</select>';
                break;

            default:
                printf(
                    '<input type="text" id="%s" name="%s" value="%s" class="regular-text" %s>',
                    esc_attr($args['key']),
                    esc_attr($name),
                    esc_attr($value),
                    $args['pattern'] ? 'pattern="' . esc_attr($args['pattern']) . '"' : ''
                );
        }

        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function sanitize_settings($input): array
    {
        try {
            if (!is_array($input)) {
                throw new Exception('Invalid settings format');
            }

            $sanitized = wp_parse_args($input, $this->default_settings);
            $this->validate_settings($sanitized);

            $this->logger->info('Settings updated successfully');

            return $sanitized;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            $this->logger->error('Settings validation failed', ['error' => $e->getMessage()]);
            add_settings_error(
                'bema_settings',
                'invalid_settings',
                $e->getMessage()
            );
            return $this->get_settings();
        }
    }

    /**
     * Sanitizes the settings data before saving to the database.
     * This is a critical security function.
     *
     * @return array The sanitized array.
     */
    public function sanitize_tiers() {
        $names = $_POST['bema_crm_tiers_names'] ?? [];
    
        $sanitized = [];
    
        foreach ($names as $name) {
            $tier_name = sanitize_text_field($name);
    
            if (!empty($tier_name)) {
                $sanitized[] = $tier_name;
            }
        }
    
        return $sanitized;
    }

    /**
     * Sanitizes the Bema CRM transition matrix data.
     *
     * Ensures that each entry in the transition matrix array has the correct
     * structure and that its data types are valid and safe.
     *
     * @param array $input The raw input data from the settings form.
     * @return array The sanitized data.
     */
    function sanitize_transition_matrix( $input ) {
        $sanitized_data = [];

        if ( ! is_array( $input ) || empty( $input ) ) {
            return $sanitized_data; // Return empty array if input is not an array or is empty
        }

    foreach ( $input as $entry ) {
        // Ensure each entry is an array
        if ( ! is_array( $entry ) ) {
            continue;
        }

        $current_tier      = isset( $entry['current_tier'] ) ? sanitize_text_field( $entry['current_tier'] ) : '';
        $next_tier         = isset( $entry['next_tier'] ) ? sanitize_text_field( $entry['next_tier'] ) : '';
        $requires_purchase = isset( $entry['requires_purchase'] ) ? filter_var( $entry['requires_purchase'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) : null;

        // Validate required fields and their types
        if (
            ! empty( $current_tier ) &&
            ! empty( $next_tier ) &&
            is_bool( $requires_purchase ) // Ensure it's a boolean (not null from FILTER_NULL_ON_FAILURE)
        ) {
            $sanitized_data[] = [
                'current_tier'      => $current_tier,
                'next_tier'         => $next_tier,
                'requires_purchase' => $requires_purchase,
            ];
        } else {
            // Optionally, log invalid entries for debugging
            error_log( 'Bema CRM: Invalid transition matrix entry skipped: ' . print_r( $entry, true ) );
        }
    }

    return $sanitized_data;
}

    private function validate_settings(array $settings): void
    {
        // Initialize error messages array
        $errors = [];

        // API Settings Validation
        if (isset($settings['api'])) {
            if (empty($settings['api']['mailerlite_api_key'])) {
                debug_to_file('Warning: MailerLite API key is empty');
                // Don't throw exception, just log warning
            }

            if (empty($settings['api']['edd_api_key']) || empty($settings['api']['edd_token'])) {
                debug_to_file('Warning: EDD credentials are incomplete');
                // Don't throw exception, just log warning
            }
        }

        // Sync Settings Validation
        if (isset($settings['sync']['batch_size'])) {
            if ($settings['sync']['batch_size'] < 100 || $settings['sync']['batch_size'] > 10000) {
                $settings['sync']['batch_size'] = 1000; // Set default instead of throwing error
                debug_to_file('Warning: Invalid batch size, setting to default 1000');
            }
        }

        // If we want to collect all validation errors
        if (!empty($errors)) {
            debug_to_file('Settings validation errors: ' . print_r($errors, true));
            // Instead of throwing exception, just log errors
            $this->logger->warning('Settings validation warnings', [
                'errors' => $errors
            ]);
        }
    }

    public function handle_settings_actions(): void
    {
        if (!isset($_POST['bema_settings_action']) || !current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('bema_settings_action', 'bema_settings_nonce');

        switch ($_POST['bema_settings_action']) {
            case 'clear_cache':
                $this->clear_cache();
                break;
            case 'reset_settings':
                $this->reset_settings();
                break;
            case 'test_connection':
                $this->test_api_connection();
                break;
        }
    }

    private function get_setting_value(string $key, array $options): string
    {
        $parts = explode('[', str_replace(']', '', $key));
        $value = $options;

        foreach ($parts as $part) {
            if (!isset($value[$part])) {
                return '';
            }
            $value = $value[$part];
        }

        return (string)$value;
    }

    public function get_settings(): array
    {
        static $settings_loaded = false;

        if ($this->is_getting_settings) {
            return $this->default_settings;
        }

        try {
            $this->is_getting_settings = true;

            if ($this->settings_cache === null || !$settings_loaded) {
                $saved_settings = get_option($this->options_key, false);

                if ($saved_settings === false) {
                    $this->settings_cache = $this->default_settings;
                } else {
                    $this->settings_cache = wp_parse_args($saved_settings, $this->default_settings);
                }
                $settings_loaded = true;
            }

            $this->is_getting_settings = false;
            return $this->settings_cache;
        } catch (Exception $e) {
            $this->is_getting_settings = false;
            error_log('Error getting settings: ' . $e->getMessage());
            return $this->default_settings;
        }
    }

    public function get_setting(string $key, $default = '')
    {
        $settings = $this->get_settings();
        return $settings[$key] ?? $default;
    }

    private function clear_cache(): void
    {
        wp_cache_flush();
        $this->settings_cache = null;
        add_settings_error(
            'bema_settings',
            'cache_cleared',
            __('Cache cleared successfully', 'bema-crm'),
            'updated'
        );
    }

    private function reset_settings(): void
    {
        update_option($this->options_key, $this->default_settings);
        $this->settings_cache = null;
        add_settings_error(
            'bema_settings',
            'settings_reset',
            __('Settings reset to defaults', 'bema-crm'),
            'updated'
        );
    }

    private function test_api_connection(): void
    {
        $settings = $this->get_settings();
        $result = [
            'mailerlite' => false,
            'edd' => false
        ];

        try {
            // Test MailerLite connection
            $mailerLite = new MailerLite($settings['api']['mailerlite_api_key'], $this->logger);
            $result['mailerlite'] = $mailerLite->validateConnection();

            // Test EDD connection
            $edd = new EDD(
                $settings['api']['edd_api_key'],
                $settings['api']['edd_token'],
                $this->logger
            );
            $result['edd'] = $edd->validateConnection();

            $message = $this->get_connection_test_message($result);
            $type = $result['mailerlite'] && $result['edd'] ? 'updated' : 'error';

            add_settings_error(
                'bema_settings',
                'connection_test',
                $message,
                $type
            );
        } catch (Exception $e) {
            add_settings_error(
                'bema_settings',
                'connection_test_failed',
                __('Connection test failed: ', 'bema-crm') . $e->getMessage(),
                'error'
            );
        }
    }

    private function get_connection_test_message(array $result): string
    {
        $messages = [];

        if ($result['mailerlite']) {
            $messages[] = __('MailerLite connection successful', 'bema-crm');
        } else {
            $messages[] = __('MailerLite connection failed', 'bema-crm');
        }

        if ($result['edd']) {
            $messages[] = __('EDD connection successful', 'bema-crm');
        } else {
            $messages[] = __('EDD connection failed', 'bema-crm');
        }

        return implode('. ', $messages);
    }

    public function display_settings_notices(): void
    {
        settings_errors('bema_settings');
    }

    public function update_settings(array $new_settings): void
    {
        try {
            // Merge with defaults to ensure all required settings exist
            $sanitized_settings = wp_parse_args(
                $this->sanitize_settings($new_settings),
                $this->default_settings
            );

            // Validate the settings
            $this->validate_settings($sanitized_settings);

            // Update the settings
            update_option($this->options_key, $sanitized_settings);

            // Clear settings cache
            $this->settings_cache = null;

            $this->logger->info('Settings updated successfully', [
                'updated_fields' => array_keys($new_settings)
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to update settings', [
                'error' => $e->getMessage(),
                'settings' => $new_settings
            ]);
            throw new Exception(
                sprintf(__('Failed to update settings: %s', 'bema-crm'), $e->getMessage())
            );
        }
    }
}
