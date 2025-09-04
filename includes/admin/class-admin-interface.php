<?php

namespace Bema\Admin;

use Exception;
use Bema\BemaCRMLogger;
use Bema\EM_Sync;
use Bema\Sync_Scheduler;
use Bema\Bema_Settings;
use function Bema\debug_to_file;
use Bema\Providers\EDD;

if (!defined('ABSPATH')) {
    exit;
}

class Bema_Admin_Interface
{
    private $logger;
    private $sync_instance;
    private $utils;
    private $sync_scheduler;
    private $settings;
    private $page_hooks = [];
    private $per_page = 20;
    private $wpdb;
    private $notifications = [];
    private $current_tab = '';
    private $max_retries = 3;
    private $campaign_manager;

    const MENU_SLUG = 'bema-sync-manager';
    const CAPABILITY = 'manage_options';
    const NONCE_ACTION = 'bema_admin_action';
    const AJAX_NONCE = 'bema_ajax_nonce';

    // Status constants
    const STATUS_SUCCESS = 'success';
    const STATUS_ERROR = 'error';
    const STATUS_WARNING = 'warning';
    const STATUS_INFO = 'info';

    public function __construct(
        BemaCRMLogger $logger,
        Bema_Settings $settings,
        ?EM_Sync $sync_instance = null,
        ?Sync_Scheduler $sync_scheduler = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->logger = $logger;
        $this->settings = $settings;
        $this->sync_instance = $sync_instance;
        $this->sync_scheduler = $sync_scheduler;
        $this->system_logger = new \Bema\Bema_CRM_Logger('system');
        $this->utils = new \Bema\Utils();
        $this->current_tab = $_GET['tab'] ?? 'general';

        // Initialize campaign manager if sync instance exists
        if ($this->sync_instance) {
            try {
                debug_to_file('Initializing campaign manager', 'ADMIN_INIT');

                $mailerlite_instance = $this->sync_instance->getMailerLiteInstance();

                if ($mailerlite_instance) {
                    $this->campaign_manager = new \Bema\Campaign_Manager(
                        $mailerlite_instance,
                        $this->logger
                    );
                    debug_to_file('Campaign manager initialized successfully', 'ADMIN_INIT');
                } else {
                    debug_to_file('MailerLite instance not available', 'ADMIN_INIT');
                }
            } catch (Exception $e) {
                debug_to_file('Failed to initialize campaign manager: ' . $e->getMessage(), 'ADMIN_ERROR');
            }
        }

        debug_to_file([
            'sync_instance_provided' => isset($sync_instance) ? 'yes' : 'no',
            'sync_scheduler_provided' => isset($sync_scheduler) ? 'yes' : 'no',
            'settings_provided' => isset($settings) ? 'yes' : 'no',
            'logger_provided' => isset($logger) ? 'yes' : 'no'
        ], 'ADMIN_INTERFACE_INIT');

        $this->init();
    }

    private function has_sync_capability(): bool
    {
        static $capability = null;

        // Return cached result if available
        if ($capability !== null) {
            return $capability;
        }

        try {
            // Ensure plugin.php is loaded
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

            // Check both EDD base and Pro versions
            $edd_base = 'easy-digital-downloads/easy-digital-downloads.php';
            $edd_pro = 'easy-digital-downloads-pro/easy-digital-downloads.php';

            // Check if either version is active
            $edd_active = is_plugin_active($edd_base) || is_plugin_active($edd_pro);

            debug_to_file([
                'edd_base_active' => is_plugin_active($edd_base),
                'edd_pro_active' => is_plugin_active($edd_pro),
                'edd_active' => $edd_active,
                'sync_instance' => isset($this->sync_instance),
                'sync_scheduler' => isset($this->sync_scheduler)
            ], 'SYNC_CAPABILITY_DETAILED_CHECK');

            // If EDD is not active, return false
            if (!$edd_active) {
                debug_to_file('EDD not active', 'SYNC_CHECK');
                $capability = false;
                return false;
            }

            // Check that our sync components are initialized
            $has_components = isset($this->sync_instance) && isset($this->sync_scheduler);
            if (!$has_components) {
                debug_to_file([
                    'sync_instance_exists' => isset($this->sync_instance),
                    'sync_scheduler_exists' => isset($this->sync_scheduler)
                ], 'SYNC_COMPONENTS_CHECK');
                $capability = false;
                return false;
            }

            // Check EDD core functionality
            if (!class_exists('\\Easy_Digital_Downloads')) {
                debug_to_file('Main EDD class not found', 'EDD_CLASS_CHECK');
                $capability = false;
                return false;
            }

            // All checks passed
            debug_to_file('Sync capability check passed', 'SYNC_CHECK');
            $capability = true;
            return true;
        } catch (Exception $e) {
            debug_to_file('Error checking sync capability: ' . $e->getMessage(), 'SYNC_ERROR');
            $capability = false;
            return false;
        }
    }

    public function get_sync_disabled_class(): string
    {
        $sync_disabled = !$this->has_sync_capability();

        debug_to_file([
            'sync_disabled' => $sync_disabled ? 'yes' : 'no',
            'has_sync_capability' => $this->has_sync_capability() ? 'yes' : 'no',
            'sync_instance_exists' => isset($this->sync_instance) ? 'yes' : 'no',
            'sync_scheduler_exists' => isset($this->sync_scheduler) ? 'yes' : 'no'
        ], 'SYNC_DISABLED_CLASS_CHECK');

        return $sync_disabled ? ' disabled' : '';
    }

    private function render_sync_disabled_notice(): void
    {
        $message = '';

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $edd_base_active = is_plugin_active('easy-digital-downloads/easy-digital-downloads.php');
        $edd_pro_active = is_plugin_active('easy-digital-downloads-pro/easy-digital-downloads.php');

        if (!$edd_base_active && !$edd_pro_active) {
            $message = __('Sync functionality is currently disabled because Easy Digital Downloads is not active. Please activate either EDD or EDD Pro.', 'bema-crm');
        } elseif (!isset($this->sync_instance) || !isset($this->sync_scheduler)) {
            $message = __('Sync functionality is currently disabled because sync components are not properly initialized. Please check your plugin settings.', 'bema-crm');
        }

?>
        <div class="wrap">
            <h1><?php _e('Sync Management', 'bema-crm'); ?></h1>
            <div class="notice notice-warning">
                <p><?php echo esc_html($message); ?></p>
            </div>
        </div>
<?php
    }

    private function init(): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        try {
            if (!current_user_can('manage_options')) {
                return;
            }

            // Cache capability check
            static $has_sync_capability = null;
            if ($has_sync_capability === null) {
                $has_sync_capability = $this->has_sync_capability();
            }

            add_action('admin_menu', [$this, 'add_menu_pages']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
            add_action('admin_notices', [$this, 'display_admin_notices']);
            add_action('admin_post_test_connection', [$this, 'handle_test_connection']);
            add_action('wp_ajax_bema_debug_log', [$this, 'handle_debug_log']);

            // Register AJAX handlers only if sync is available
            if ($has_sync_capability) {
                $this->register_ajax_handlers();
            }

            $this->current_tab = $_GET['tab'] ?? 'general';
            $initialized = true;
            
        } catch (Exception $e) {
            $this->logger->log('Admin interface initialization failed', 'error', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function get_settings()
    {
        return $this->settings->get_settings();
    }

    private function verify_dependencies(): bool
    {
        if (!$this->logger || !$this->sync_instance || !$this->sync_scheduler || !$this->settings) {
            $this->logger?->log('Missing required dependencies', 'error');
            return false;
        }
        return true;
    }

    public function register_ajax_handlers(): void
    {
        add_action('wp_ajax_bema_start_sync', [$this, 'handle_start_sync']);
        add_action('wp_ajax_bema_stop_sync', [$this, 'handle_stop_sync']);
        add_action('wp_ajax_bema_get_sync_status', [$this, 'handle_get_sync_status']);
        add_action('wp_ajax_bema_validate_groups', [$this, 'handle_validate_groups']);
        add_action('wp_ajax_bema_sync_groups', [$this, 'handle_sync_groups']);

        debug_to_file('AJAX handlers registered', 'AJAX_INIT');
    }

    public function add_menu_pages(): void
    {
        try {
            // Main menu - always show
            $this->page_hooks[] = add_menu_page(
                __('Bema CRM', 'bema-crm'),
                __('Bema CRM', 'bema-crm'),
                self::CAPABILITY,
                self::MENU_SLUG,
                [$this, 'render_sync_page'],
                'dashicons-database-view',
                30
            );

            // Get available submenus based on capabilities
            $submenus = $this->get_submenu_pages();
            foreach ($submenus as $slug => $menu) {
                $this->page_hooks[] = add_submenu_page(
                    self::MENU_SLUG,
                    $menu['title'],
                    $menu['menu_title'],
                    self::CAPABILITY,
                    $slug,
                    [$this, $menu['callback']]
                );
            }
        } catch (Exception $e) {
            $this->logger->log('Failed to add menu pages', 'error', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function get_submenu_pages(): array
    {
        $submenus = [];

        // Always include Database Management
        $submenus['bema-database'] = [
            'title' => __('Database Management', 'bema-crm'),
            'menu_title' => __('Database', 'bema-crm'),
            'callback' => 'render_database_page'
        ];

        // Add sync-related pages only if sync capability is available
        if ($this->has_sync_capability()) {
            $submenus['bema-synchronize'] = [
                'title' => __('Synchronize', 'bema-crm'),
                'menu_title' => __('Synchronize', 'bema-crm'),
                'callback' => 'render_synchronize_page'
            ];
        }

        if ($this->has_sync_capability()) {
            $submenus['bema-transitions'] = [
                'title' => __('Campaign Transitions', 'bema-crm'),
                'menu_title' => __('Transitions', 'bema-crm'),
                'callback' => 'render_transitions_page'
            ];
        }

        // Always include Settings
        $submenus['bema-settings'] = [
            'title' => __('Settings', 'bema-crm'),
            'menu_title' => __('Settings', 'bema-crm'),
            'callback' => 'render_settings_page'
        ];

        return $submenus;
    }

    // In class-admin-interface.php
    public function getTransitionStatus(): array
    {
        return [
            'pending' => $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}bema_transitions WHERE status = 'pending'"),
            'completed' => $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}bema_transitions WHERE status = 'completed'"),
            'failed' => $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}bema_transitions WHERE status = 'failed'")
        ];
    }

    public function enqueue_assets($hook): void
    {
        if (!in_array($hook, $this->page_hooks)) {
            return;
        }

        try {
            debug_to_file('Enqueuing assets for hook: ' . $hook, 'ASSETS');

            // Core styles
            wp_enqueue_style(
                'bema-admin-css',
                plugins_url('assets/css/admin.css', BEMA_FILE),
                [],
                BEMA_VERSION
            );

            wp_enqueue_script('jquery');

            // Core scripts
            wp_enqueue_script(
                'bema-admin-js',
                plugins_url('assets/js/admin.js', BEMA_FILE),
                ['jquery'],
                BEMA_VERSION,
                true
            );

            if ( isset($_GET['page']) && $_GET['page'] === 'bema-transitions' ) {
                wp_enqueue_style(
                    'bema-crm-tier-style',
                    plugins_url('assets/css/settings/admin-transitions-page.css', BEMA_FILE),
                    BEMA_VERSION,
                );
        
                wp_enqueue_script(
                    'bema-crm-tier-script',
                    plugins_url('assets/js/settings/admin-tier-table.js', BEMA_FILE),
                    BEMA_VERSION,
                    true
                );
        
                wp_enqueue_script(
                    'bema-crm-tier-transitions-script',
                    plugins_url('assets/js/settings/admin-tier-transitions-table.js', BEMA_FILE),
                    BEMA_VERSION,
                    true
                );

                // This creates a JavaScript object named 'bemaCrmData' that your script can use.
                $saved_tiers = get_option('bema_crm_tiers', []);
                wp_localize_script(
                    'bema-crm-tier-transitions-script',
                    'bemaCrmData', // The name of the global JavaScript object to create
                    array(
                        'tiers' => $saved_tiers, // Key/value pair for the data
                    )
                );
            }


            if ( isset($_GET['page']) && $_GET['page'] === 'bema-database' ) {
                wp_enqueue_style(
                    'bema-crm-database-style',
                    plugins_url('assets/css/database/admin-database-page.css', BEMA_FILE),
                    BEMA_VERSION,
                );
        
                wp_enqueue_script(
                    'bema-crm-database-script',
                    plugins_url('assets/js/database/admin-database-table.js', BEMA_FILE),
                    ['jquery'],
                    BEMA_VERSION,
                    true
                );

                // This creates a JavaScript object named 'bemaCrmData' that your script can use.
                wp_localize_script(
                    'bema-crm-database-script',
                    'bemaCrmDatabaseData', // The name of the global JavaScript object to create
                    [
                        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                        'nonce'   => wp_create_nonce( 'bema_crm_nonce' ),
                    ]
                );
            }


            // Module scripts for sync manager
            if (strpos($hook, 'bema-sync-manager') !== false) {
                wp_enqueue_script(
                    'bema-sync-js',
                    plugins_url('assets/js/modules/sync.js', BEMA_FILE),
                    ['jquery', 'bema-admin-js'],
                    BEMA_VERSION,
                    true
                );

                // In the enqueue_assets method, update the wp_localize_script call:
                wp_localize_script('bema-sync-js', 'bemaAdmin', [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('bema_admin_nonce'),
                    'strings' => [
                        'confirmStop' => __('Are you sure you want to stop the sync?', 'bema-crm'),
                        'confirmRetry' => __('Are you sure you want to retry failed jobs?', 'bema-crm'),
                        'noSelection' => __('Please select at least one campaign', 'bema-crm'),
                        'confirmGroupSync' => __('Are you sure you want to sync campaign groups? This may take a few minutes.', 'bema-crm')
                    ],
                    'debug' => [
                        'enabled' => true
                    ],
                    'validCampaigns' => $this->campaign_manager ? $this->campaign_manager->get_all_valid_campaigns() : []
                ]);
            }

            debug_to_file('Assets enqueued successfully', 'ASSETS');
        } catch (Exception $e) {
            debug_to_file('Failed to enqueue assets: ' . $e->getMessage(), 'ASSETS_ERROR');
        }
    }

    private function enqueue_module_assets(string $hook): void
    {
        $module_scripts = [
            'bema-database' => 'database.js',
            'bema-sync-logs' => 'logs.js',
            'bema-settings' => 'settings.js'
        ];

        foreach ($module_scripts as $page => $script) {
            if (strpos($hook, $page) !== false) {
                wp_enqueue_script(
                    "bema-{$page}-js",
                    BEMA_URL . "assets/js/modules/{$script}",
                    ['bema-admin-js'],
                    BEMA_VERSION,
                    true
                );
            }
        }
    }

    private function get_localization_data(): array
    {
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::AJAX_NONCE),
            'strings' => [
                'confirmBulkAction' => __('Are you sure you want to perform this action?', 'bema-crm'),
                'confirmStop' => __('Are you sure you want to stop the sync process?', 'bema-crm'),
                'confirmRetry' => __('Are you sure you want to retry failed jobs?', 'bema-crm'),
                'error' => __('An error occurred', 'bema-crm'),
                'noItemsSelected' => __('Please select at least one item', 'bema-crm')
            ],
            'currentTab' => $this->current_tab,
            'maxRetries' => 3,
            'refreshInterval' => 5000
        ];
    }

    // Page rendering methods
    public function render_sync_page(): void
    {
        try {
            $has_capability = $this->has_sync_capability();
            $this->logger->log('Rendering sync page:', 'debug', [
                'has_capability' => $has_capability,
                'sync_instance_exists' => isset($this->sync_instance),
                'sync_scheduler_exists' => isset($this->sync_scheduler)
            ]);

            if (!$has_capability) {
                $this->render_sync_disabled_notice();
                return;
            }

            // Get detailed sync status data
            $sync_status = $this->get_sync_status_data();

            // Set up sync status variables
            $current_status = $sync_status['status'] ?? 'idle';
            $processed = abs(intval($sync_status['processed'] ?? 0));
            $total = abs(intval($sync_status['total'] ?? 0));
            $progress = $total > 0 ? min(100, round(($processed / $total) * 100)) : 0;

            $failed_jobs = $this->get_failed_jobs();
            $campaigns = [];
            $max_retries = $this->max_retries;
            $admin = $this;

            require_once BEMA_PATH . 'includes/admin/views/sync-management.php';
        } catch (Exception $e) {
            $this->logger->log('Failed to render sync page', 'error', [
                'error' => $e->getMessage()
            ]);
            wp_die('Error loading sync page: ' . esc_html($e->getMessage()));
        }
    }

    public function render_synchronize_page(): void
    {
        try {
            $admin = $this;

            require_once BEMA_PATH . 'includes/admin/views/synchronize.php';
        } catch (Exception $e) {
            $this->add_admin_notice(
                sprintf(__('Error loading Synchronize page: %s', 'bema-crm'), $e->getMessage()),
                self::STATUS_ERROR
            );
            $this->logger->log('Failed to render logs page', 'error', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function render_database_page(): void
    {
        try {
            $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
            $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
            $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'subscribers';

            // Get data and set up view variables
            $offset = ($page - 1) * $this->get_per_page();
            $total_subscribers = $this->get_total_subscribers();
            $total_logs = $this->get_total_logs();
            $active_filters = $this->get_active_filters();
            $admin = $this; // Pass $this as $admin
            $campaigns = [];

            require_once BEMA_PATH . 'includes/admin/views/database-management.php';
        } catch (Exception $e) {
            $this->logger->log('Failed to render database page', 'error', [
                'error' => $e->getMessage()
            ]);
            wp_die('Error loading database page: ' . esc_html($e->getMessage()));
        }
    }

    public function render_settings_page(): void
    {
        try {
            // Set up required variables for the view
            $admin = $this; 
            $current_settings = $this->get_settings();
            $has_sync = $this->has_sync_capability();

            require_once BEMA_PATH . 'includes/admin/views/settings.php';
        } catch (Exception $e) {
            $this->logger->log('Failed to render settings page', 'error', [
                'error' => $e->getMessage()
            ]);
            wp_die('Error loading settings page: ' . esc_html($e->getMessage()));
        }
    }

    // AJAX handlers
    public function handle_start_sync(): void
    {
        try {
            debug_to_file('Starting sync handler', 'SYNC_HANDLER');
            check_ajax_referer('bema_admin_nonce', 'nonce');

            $campaigns_json = isset($_POST['campaigns']) ? stripslashes($_POST['campaigns']) : '';
            $campaigns = json_decode($campaigns_json, true);

            debug_to_file([
                'raw_campaigns_json' => $campaigns_json,
                'decoded_campaigns' => $campaigns
            ], 'SYNC_HANDLER');

            if (empty($campaigns)) {
                throw new Exception(__('No campaigns selected', 'bema-crm'));
            }

            $valid_campaigns = [];
            foreach ($campaigns as $campaign) {
                if (!isset($campaign['name'])) {
                    continue;
                }

                // Verify campaign is valid
                if (!$this->campaign_manager || !$this->campaign_manager->is_valid_campaign($campaign['name'])) {
                    debug_to_file([
                        'error' => 'Invalid campaign',
                        'campaign' => $campaign['name']
                    ], 'SYNC_VALIDATION');
                    continue;
                }

                // Verify campaign has groups
                $groups = $this->campaign_manager->get_campaign_groups($campaign['name']);
                if (!$groups) {
                    debug_to_file([
                        'error' => 'No groups found for campaign',
                        'campaign' => $campaign['name']
                    ], 'SYNC_VALIDATION');
                    continue;
                }

                $valid_campaigns[] = $campaign;
            }

            if (empty($valid_campaigns)) {
                throw new Exception(__('No valid campaigns with groups found', 'bema-crm'));
            }

            // Schedule sync with validated campaigns
            if (!$this->sync_scheduler) {
                throw new Exception(__('Sync scheduler not initialized', 'bema-crm'));
            }

            $result = $this->sync_scheduler->schedule_sync('custom', $valid_campaigns);

            if ($result) {
                wp_send_json_success([
                    'message' => __('Sync started successfully', 'bema-crm')
                ]);
            } else {
                throw new Exception(__('Failed to schedule sync', 'bema-crm'));
            }
        } catch (Exception $e) {
            debug_to_file([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'SYNC_HANDLER_ERROR');

            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function handle_get_sync_status(): void
    {
        try {
            check_ajax_referer('bema_admin_nonce', 'nonce');

            if (!$this->sync_instance) {
                throw new Exception('Sync instance not initialized');
            }

            $status = $this->sync_instance->getCurrentProgress();

            // Add additional status data
            $status['memory_usage'] = size_format(memory_get_usage(true));
            $status['peak_memory'] = size_format(memory_get_peak_usage(true));

            debug_to_file([
                'status' => $status
            ], 'GET_SYNC_STATUS');

            wp_send_json_success($status);
        } catch (Exception $e) {
            debug_to_file([
                'error' => $e->getMessage()
            ], 'GET_SYNC_STATUS_ERROR');

            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function handle_stop_sync(): void
    {
        try {
            check_ajax_referer('bema_admin_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                throw new Exception(__('Unauthorized access', 'bema-crm'));
            }

            if (!$this->sync_instance) {
                throw new Exception('Sync instance not initialized');
            }

            debug_to_file('Attempting to stop sync through AJAX handler', 'STOP_SYNC');

            // Get current sync status before stopping
            $current_status = $this->sync_instance->getCurrentProgress();

            // Force stop the sync
            $stop_result = $this->sync_instance->stopSync();

            if (!$stop_result) {
                throw new Exception(__('Failed to stop sync process', 'bema-crm'));
            }

            // Clean up sync state
            $this->cleanup_sync_state();

            // Prepare response data
            $response_data = array(
                'message' => __('Sync stopped successfully', 'bema-crm'),
                'status' => 'stopped',
                'processed' => $current_status['processed'] ?? 0,
                'total' => $current_status['total'] ?? 0,
                'memory_usage' => size_format(memory_get_usage(true)),
                'end_time' => current_time('mysql')
            );

            // Send proper JSON response
            wp_send_json_success($response_data);
        } catch (Exception $e) {
            debug_to_file([
                'stop_sync_failed' => true,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'STOP_SYNC_ERROR');

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'status' => 'error'
            ));
        }
    }

    public function handle_validate_groups(): void
    {
        try {
            $this->verify_ajax_nonce();

            if (!$this->campaign_manager) {
                throw new Exception(__('Campaign manager not initialized', 'bema-crm'));
            }

            // Get all required groups for all campaigns
            $required_groups = [];
            $valid_campaigns = $this->campaign_manager->get_all_valid_campaigns();

            foreach ($valid_campaigns as $campaign) {
                $campaign_groups = $this->campaign_manager->get_campaign_groups($campaign);
                if ($campaign_groups) {
                    $required_groups = array_merge($required_groups, array_values($campaign_groups));
                }
            }

            $required_groups = array_unique($required_groups);

            // Validate groups exist in MailerLite
            $validation_result = $this->campaign_manager->validate_mailerlite_groups($required_groups);

            if (!$validation_result['valid']) {
                throw new Exception(sprintf(
                    __('Missing required groups: %s', 'bema-crm'),
                    implode(', ', $validation_result['missing_groups'])
                ));
            }

            wp_send_json_success([
                'message' => __('All required groups exist in MailerLite.', 'bema-crm')
            ]);
        } catch (Exception $e) {
            wp_send_json_error([
                'error' => $e->getMessage()
            ]);
        }
    }

    public function handle_sync_groups(): void
    {
        try {
            $this->verify_ajax_nonce();

            if (!$this->sync_instance) {
                throw new Exception(__('Sync instance not initialized', 'bema-crm'));
            }

            // Get campaign groups and start sync
            $result = $this->sync_instance->syncGroups();

            wp_send_json_success([
                'message' => __('Groups synchronized successfully.', 'bema-crm'),
                'result' => $result
            ]);
        } catch (Exception $e) {
            wp_send_json_error([
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clean up sync state after stopping
     */
    private function cleanup_sync_state(): void
    {
        try {
            // Clear any running sync flags
            delete_transient('bema_sync_lock');
            delete_option('bema_sync_stop_flag');

            // Clear any scheduled sync events
            wp_clear_scheduled_hook('bema_crm_hourly_sync');
            wp_clear_scheduled_hook('bema_crm_daily_sync');
            wp_clear_scheduled_hook('bema_crm_custom_sync');

            // Update sync status
            update_option('bema_sync_status', [
                'status' => 'stopped',
                'end_time' => time(),
                'processed' => $this->sync_instance ? $this->sync_instance->getCurrentProgress()['processed'] : 0,
                'total' => $this->sync_instance ? $this->sync_instance->getCurrentProgress()['total'] : 0
            ], false);

            debug_to_file('Sync state cleaned up successfully', 'SYNC_CLEANUP');
        } catch (Exception $e) {
            debug_to_file([
                'cleanup_failed' => true,
                'error' => $e->getMessage()
            ], 'SYNC_CLEANUP_ERROR');

            if ($this->logger) {
                $this->logger->log('Failed to cleanup sync state', 'error', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    // Database query methods with error handling
    private function get_subscribers(int $offset = 0, string $search = '', array $filters = []): array
    {
        try {
            $query = "SELECT * FROM {$this->wpdb->prefix}bemacrmmeta WHERE 1=1";
            $params = [];

            if (!empty($search)) {
                $query .= " AND (subscriber LIKE %s OR first_name LIKE %s OR last_name LIKE %s)";
                $search_term = '%' . $this->wpdb->esc_like($search) . '%';
                $params = array_merge($params, [$search_term, $search_term, $search_term]);
            }

            if (!empty($filters['tier'])) {
                $query .= " AND tier = %s";
                $params[] = $filters['tier'];
            }

            $query .= " ORDER BY date_added DESC LIMIT %d OFFSET %d";
            $params = array_merge($params, [$this->per_page, $offset]);

            return $this->wpdb->get_results($this->wpdb->prepare($query, $params), ARRAY_A);
        } catch (Exception $e) {
            $this->logger->log('Database query failed', 'error', [
                'query' => 'get_subscribers',
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    private function get_sync_logs(int $offset = 0, array $filters = []): array
    {
        try {
            $query = "SELECT * FROM {$this->wpdb->prefix}sync_logs WHERE 1=1";
            $params = [];

            if (!empty($filters['status'])) {
                $query .= " AND status = %s";
                $params[] = $filters['status'];
            }

            if (!empty($filters['date_range'])) {
                $date_condition = $this->get_date_range_condition($filters['date_range']);
                $query .= " AND created_at {$date_condition}";
            }

            $query .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
            $params = array_merge($params, [$this->per_page, $offset]);

            return $this->wpdb->get_results($this->wpdb->prepare($query, $params), ARRAY_A);
        } catch (Exception $e) {
            $this->logger->log('Database query failed', 'error', [
                'query' => 'get_sync_logs',
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    private function get_date_range_condition(string $range): string
    {
        switch ($range) {
            case 'today':
                return ">= DATE(NOW())";
            case 'week':
                return ">= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            case 'month':
                return ">= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            default:
                return ">= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        }
    }

    // Modify render_transitions_page() in class-admin-interface.php:

    public function render_transitions_page(): void
    {
        if (!$this->has_sync_capability()) {
            $this->render_sync_disabled_notice();
            return;
        }

        // Prepare data for the view
        $view_data = [
            'campaigns' => $this->campaign_manager->get_all_valid_campaigns(),
            'campaign_connections' => $this->get_campaign_connections(),
            'edd_instance' => $this->sync_instance->getMailerLiteInstance(),
            'admin' => $this
        ];

        // Extract data to make it available in view scope
        extract($view_data);

        require_once BEMA_PATH . 'includes/admin/views/campaign-transitions.php';
    }

    private function get_campaign_connections(): array
    {
        $connections = [];
        $campaigns = $this->campaign_manager->get_all_valid_campaigns();

        foreach ($campaigns as $source_campaign) {
            $next_campaign = $this->get_next_campaign($source_campaign);
            if ($next_campaign) {
                $connections[] = [
                    'source' => $source_campaign,
                    'destination' => $next_campaign,
                    'valid' => $this->validate_campaign_connection($source_campaign, $next_campaign)
                ];
            }
        }
        return $connections;
    }

    private function get_next_campaign(string $campaign): ?string
    {
        // Extract year and identifier from campaign code
        preg_match('/(\d{4})_(.+)/', $campaign, $matches);
        if (count($matches) !== 3) return null;

        $year = (int)$matches[1];
        $base = $matches[2];

        // Return next campaign if it exists
        $next_campaign = ($year + 1) . '_' . $base;
        return $this->campaign_manager->is_valid_campaign($next_campaign) ? $next_campaign : null;
    }

    private function validate_campaign_connection(string $source, string $destination): bool
    {
        if (
            !$this->campaign_manager->is_valid_campaign($source) ||
            !$this->campaign_manager->is_valid_campaign($destination)
        ) {
            return false;
        }

        // Verify groups exist in both campaigns
        $source_groups = $this->campaign_manager->get_campaign_groups($source);
        $dest_groups = $this->campaign_manager->get_campaign_groups($destination);

        return !empty($source_groups) && !empty($dest_groups);
    }

    public function get_campaign_manager()
    {
        if (!$this->campaign_manager) {
            debug_to_file('Campaign manager not initialized', 'ADMIN_ERROR');
            return null;
        }
        return $this->campaign_manager;
    }

    private function get_sync_status_data(): array
    {
        try {
            if (!$this->sync_instance) {
                return [];
            }

            $status = $this->sync_instance->getCurrentProgress();

            // Add detailed campaign progress
            if (isset($status['current_campaign'])) {
                $campaign_progress = [];
                if (isset($status['campaign_progress'][$status['current_campaign']])) {
                    $campaign_progress = [
                        'campaign_number' => $status['campaign_number'] ?? 1,
                        'total_campaigns' => $status['total_campaigns'] ?? 1,
                        'current_group' => $status['current_group'] ?? '',
                        'current_page' => $status['current_page'] ?? 1,
                        'total_pages_processed' => $status['total_pages_processed'] ?? 0,
                        'group_progress' => $status['campaign_progress'][$status['current_campaign']] ?? []
                    ];
                }
                $status['campaign_details'] = $campaign_progress;
            }

            // Add performance metrics
            $status['performance'] = [
                'memory_usage' => $status['memory_usage'] ?? '0 MB',
                'peak_memory' => $status['peak_memory'] ?? '0 MB',
                'start_time' => $status['start_time'] ?? 0,
                'duration' => isset($status['start_time']) ?
                    time() - $status['start_time'] : 0
            ];

            return $status;
        } catch (Exception $e) {
            $this->logger->log('Failed to get sync status data', 'error', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    // Utility methods
    private function verify_ajax_nonce(): void
    {
        if (!check_ajax_referer(self::AJAX_NONCE, 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Invalid security token', 'bema-crm')
            ]);
        }
    }

    private function add_admin_notice(string $message, string $type = self::STATUS_INFO): void
    {
        $this->notifications[] = [
            'message' => $message,
            'type' => $type
        ];
    }

    public function display_admin_notices(): void
    {
        foreach ($this->notifications as $notice) {
            printf(
                '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr($notice['type']),
                esc_html($notice['message'])
            );
        }
    }

    public function get_per_page(): int
    {
        return $this->per_page;
    }

    private function get_database_data(string $tab, int $page, string $search): array
    {
        $offset = ($page - 1) * $this->per_page;
        $filters = $this->get_active_filters();

        switch ($tab) {
            case 'subscribers':
                return [
                    'items' => $this->get_subscribers($offset, $search, $filters),
                    'total' => $this->get_total_subscribers($search, $filters)
                ];
            case 'sync-logs':
                return [
                    'items' => $this->get_sync_logs($offset, $filters),
                    'total' => $this->get_total_logs($filters)
                ];
            default:
                return ['items' => [], 'total' => 0];
        }
    }

    private function get_active_filters(): array
    {
        $filters = [];

        if (!empty($_GET['tier'])) {
            $filters['tier'] = sanitize_text_field($_GET['tier']);
        }

        if (!empty($_GET['status'])) {
            $filters['status'] = sanitize_text_field($_GET['status']);
        }

        if (!empty($_GET['date_range'])) {
            $filters['date_range'] = sanitize_text_field($_GET['date_range']);
        }

        return $filters;
    }

    private function get_total_subscribers(string $search = '', array $filters = []): int
    {
        try {
            $query = "SELECT COUNT(*) FROM {$this->wpdb->prefix}bemacrmmeta WHERE 1=1";
            $params = [];

            if (!empty($search)) {
                $query .= " AND (subscriber LIKE %s OR first_name LIKE %s OR last_name LIKE %s)";
                $search_term = '%' . $this->wpdb->esc_like($search) . '%';
                $params = array_merge($params, [$search_term, $search_term, $search_term]);
            }

            if (!empty($filters['tier'])) {
                $query .= " AND tier = %s";
                $params[] = $filters['tier'];
            }

            return (int) $this->wpdb->get_var($this->wpdb->prepare($query, $params));
        } catch (Exception $e) {
            $this->logger->log('Failed to get total subscribers count', 'error', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    private function get_total_logs(array $filters = []): int
    {
        try {
            $query = "SELECT COUNT(*) FROM {$this->wpdb->prefix}sync_logs WHERE 1=1";
            $params = [];

            if (!empty($filters['status'])) {
                $query .= " AND status = %s";
                $params[] = $filters['status'];
            }

            if (!empty($filters['date_range'])) {
                $date_condition = $this->get_date_range_condition($filters['date_range']);
                $query .= " AND created_at {$date_condition}";
            }

            return (int) $this->wpdb->get_var($this->wpdb->prepare($query, $params));
        } catch (Exception $e) {
            $this->logger->log('Failed to get total logs count', 'error', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    public function handle_debug_log(): void
    {
        check_ajax_referer('bema_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        $data = $_POST['data'] ?? '';
        $label = sanitize_text_field($_POST['label'] ?? '');

        debug_to_file($data, $label);
        wp_send_json_success();
    }

    private function get_failed_jobs(): array
    {
        try {
            return $this->sync_instance ? get_option('bema_sync_failed_jobs', []) : [];
        } catch (Exception $e) {
            $this->logger->log('Failed to get failed jobs', 'error', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    private function handle_sync_actions(): void
    {
        try {
            $action = sanitize_text_field($_POST['sync_action'] ?? '');
            switch ($action) {
                case 'start':
                    $this->sync_scheduler->schedule_sync('manual', []);
                    $this->add_admin_notice(__('Sync started successfully', 'bema-crm'), self::STATUS_SUCCESS);
                    break;
                case 'stop':
                    $this->sync_scheduler->cancel_sync();
                    $this->add_admin_notice(__('Sync stopped successfully', 'bema-crm'), self::STATUS_SUCCESS);
                    break;
                default:
                    throw new Exception(__('Invalid sync action', 'bema-crm'));
            }
        } catch (Exception $e) {
            $this->add_admin_notice($e->getMessage(), self::STATUS_ERROR);
        }
    }

    private function get_log_filters(): array
    {
        return [
            'status' => sanitize_text_field($_GET['filter_status'] ?? ''),
            'date_range' => sanitize_text_field($_GET['filter_date'] ?? ''),
            'search' => sanitize_text_field($_GET['search'] ?? '')
        ];
    }

    private function get_filtered_logs(array $filters, int $page): array
    {
        $offset = ($page - 1) * $this->per_page;
        return $this->get_sync_logs($offset, $filters);
    }

    /**
     * Check if sync-related section should be displayed
     */
    private function show_sync_section(): bool
    {
        static $show_sync = null;
        if ($show_sync === null) {
            $show_sync = $this->has_sync_capability();
            $this->logger->log('Show sync section check:', 'debug', [
                'show_sync' => $show_sync,
                'has_capability' => $this->has_sync_capability(),
                'sync_instance_exists' => isset($this->sync_instance),
                'sync_scheduler_exists' => isset($this->sync_scheduler)
            ]);
        }
        return $show_sync;
    }

    /**
     * AJAX handler for getting subscriber details
     */
    public function handle_get_subscriber_details(): void
    {
        try {
            $this->verify_ajax_nonce();

            if (!current_user_can(self::CAPABILITY)) {
                throw new Exception(__('Insufficient permissions', 'bema-crm'));
            }

            $subscriber_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            if (!$subscriber_id) {
                throw new Exception(__('Invalid subscriber ID', 'bema-crm'));
            }

            // Get subscriber details from database with better error handling
            $subscriber = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->wpdb->prefix}bemacrmmeta WHERE bema_id = %d",
                    $subscriber_id
                ),
                ARRAY_A
            );

            if (!$subscriber) {
                throw new Exception(__('Subscriber not found', 'bema-crm'));
            }

            // Initialize additional data array
            $additional_data = [
                'mailerlite_status' => [
                    'status' => 'unknown',
                    'groups' => [],
                    'last_updated' => null
                ],
                'purchase_history' => [
                    'purchases' => [],
                    'total_spent' => 0,
                    'last_purchase' => null
                ],
                'sync_status' => [
                    'last_sync' => null,
                    'sync_errors' => []
                ]
            ];

            // Get additional data if sync instance is available
            if ($this->sync_instance) {
                try {
                    // Get MailerLite status
                    $ml_status = $this->sync_instance->getSubscriberStatus($subscriber['subscriber']);
                    if ($ml_status) {
                        $additional_data['mailerlite_status'] = $ml_status;
                    }

                    // Get EDD purchase history if sync capability is available
                    if ($this->has_sync_capability()) {
                        $purchase_history = $this->sync_instance->getEDDPurchaseHistory($subscriber['subscriber']);
                        if ($purchase_history) {
                            $additional_data['purchase_history'] = $purchase_history;
                        }

                        // Get campaign specific data
                        if (!empty($subscriber['campaign'])) {
                            $product_id = $this->sync_instance->getEDDProductForCampaign($subscriber['campaign']);
                            if ($product_id) {
                                $edd_instance = $this->sync_instance->getEDDInstance();
                                if ($edd_instance) {
                                    $additional_data['campaign_data'] = [
                                        'product_id' => $product_id,
                                        'has_purchased' => $edd_instance->hasUserPurchasedProduct(
                                            $subscriber['id'],
                                            $product_id
                                        )
                                    ];
                                }
                            }
                        }
                    }

                    // Log the data retrieval
                    debug_to_file([
                        'subscriber_details_retrieved' => true,
                        'email' => $subscriber['subscriber'],
                        'has_mailerlite_data' => !empty($ml_status),
                        'has_purchase_data' => !empty($purchase_history)
                    ], 'SUBSCRIBER_DETAILS');
                } catch (Exception $e) {
                    // Log the error but don't throw it - we still want to return the basic subscriber data
                    $this->logger->log('Error fetching additional subscriber data', 'warning', [
                        'error' => $e->getMessage(),
                        'subscriber_id' => $subscriber_id,
                        'email' => $subscriber['subscriber']
                    ]);

                    $additional_data['sync_status']['sync_errors'][] = $e->getMessage();
                }
            }

            // Format the response data
            $response_data = [
                'subscriber' => array_merge($subscriber, [
                    'formatted_date' => date_i18n(
                        get_option('date_format') . ' ' . get_option('time_format'),
                        strtotime($subscriber['date_added'])
                    )
                ]),
                'additional_data' => $additional_data
            ];

            wp_send_json_success($response_data);
        } catch (Exception $e) {
            $this->logger->log('Failed to get subscriber details', 'error', [
                'error' => $e->getMessage(),
                'subscriber_id' => $subscriber_id ?? 'none',
                'trace' => $e->getTraceAsString()
            ]);

            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }

    /**
     * Handle settings update with sync dependency check
     */
    public function handle_settings_update(): void
    {
        try {
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'bema-crm'));
            }

            $settings = $_POST['bema_crm_settings'] ?? [];

            // If EDD is not active, preserve existing sync settings
            if (!$this->show_sync_section()) {
                $current_settings = $this->settings->get_settings();
                $settings['sync'] = $current_settings['sync'] ?? [];
                $settings['api'] = $current_settings['api'] ?? [];
            }

            // Sanitize and update settings
            $sanitized_settings = $this->sanitize_settings($settings);
            $this->settings->update_settings($sanitized_settings);

            $this->add_admin_notice(
                __('Settings updated successfully', 'bema-crm'),
                self::STATUS_SUCCESS
            );
        } catch (Exception $e) {
            $this->add_admin_notice($e->getMessage(), self::STATUS_ERROR);
            $this->logger->log('Settings update failed', 'error', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Sanitize settings array
     */
    private function sanitize_settings(array $settings): array
    {
        $sanitized = [];

        // API settings
        $sanitized['api'] = [
            'mailerlite_api_key' => sanitize_text_field($settings['api']['mailerlite_api_key'] ?? ''),
            'edd_api_key' => sanitize_text_field($settings['api']['edd_api_key'] ?? ''),
            'edd_token' => sanitize_text_field($settings['api']['edd_token'] ?? ''),
            'timeout' => absint($settings['api']['timeout'] ?? 30)
        ];

        // Sync settings
        $sanitized['sync'] = [
            'batch_size' => absint($settings['sync']['batch_size'] ?? 1000),
            'retry_attempts' => absint($settings['sync']['retry_attempts'] ?? 3),
            'memory_limit' => sanitize_text_field($settings['sync']['memory_limit'] ?? '256M')
        ];

        // Add more settings sections as needed

        return $sanitized;
    }

    private function test_api_connections(): array
    {
        $results = [
            'status' => 'success',
            'messages' => [],
            'mailerlite' => false,
            'edd' => false
        ];

        try {
            $settings = $this->settings->get_settings();

            // Clear previous test results
            wp_cache_delete('edd_api_test', 'edd_cache');
            delete_transient('edd_test_connection_result');
            delete_transient('bema_api_test_in_progress');

            // Test MailerLite
            if (!empty($settings['api']['mailerlite_api_key'])) {
                $mailerlite = new \Bema\Providers\MailerLite(
                    $settings['api']['mailerlite_api_key'],
                    $this->logger
                );

                $ml_result = $mailerlite->test_connection();
                $results['mailerlite'] = $ml_result;

                if ($ml_result) {
                    $results['messages'][] = __('MailerLite API connection successful.', 'bema-crm');
                } else {
                    if (!is_ssl()) {
                        $results['messages'][] = __('MailerLite API connection failed. Note: Your site is using HTTP. MailerLite requires HTTPS for API connections.', 'bema-crm');
                    } else {
                        $results['messages'][] = __('MailerLite API connection failed. Please verify your API key.', 'bema-crm');
                    }
                }
            } else {
                $results['messages'][] = __('MailerLite API key is missing.', 'bema-crm');
            }

            // Test EDD with proper validation
            if ($this->has_sync_capability()) {
                if (empty($settings['api']['edd_api_key']) || empty($settings['api']['edd_token'])) {
                    $results['messages'][] = __('EDD API credentials are missing.', 'bema-crm');
                } else {
                    $edd = new \Bema\Providers\EDD(
                        $settings['api']['edd_api_key'],
                        $settings['api']['edd_token'],
                        $this->logger
                    );

                    $edd_result = $edd->test_connection();
                    $results['edd'] = $edd_result;

                    if ($edd_result) {
                        $results['messages'][] = __('EDD API connection successful.', 'bema-crm');
                    } else {
                        $results['messages'][] = __('EDD API connection failed. Please verify your API credentials.', 'bema-crm');
                    }
                }
            }

            $results['status'] = ($results['mailerlite'] || $results['edd']) ? 'success' : 'error';

            // Debug logging
            debug_to_file('API connection test results:', 'API_TEST');
            debug_to_file($results, 'API_TEST');
        } catch (Exception $e) {
            $this->logger->log('API test error', 'error', [
                'error' => $e->getMessage()
            ]);
            $results['status'] = 'error';
            $results['messages'][] = $e->getMessage();
        }

        return $results;
    }

    private function cleanup_test_state(): void
    {
        // Clear transients
        delete_transient('bema_api_test_results');
        delete_transient('edd_test_connection_result');

        // Clear caches
        wp_cache_delete('mailerlite_test_connection', 'mailerlite_cache');
        wp_cache_delete('edd_api_test', 'edd_cache');

        // Clear any stored connection states
        update_option('bema_last_connection_test', null);
    }

    public function handle_test_connection(): void
    {
        try {
            check_admin_referer('bema_test_connection');

            // Check if a test is already in progress
            if (get_transient('bema_api_test_in_progress')) {
                throw new Exception(__('A test is already in progress. Please wait a few seconds and try again.', 'bema-crm'));
            }

            // Set test in progress flag with 30 second timeout
            set_transient('bema_api_test_in_progress', true, 30);

            // Clean up before testing
            $this->cleanup_test_state();

            if (!defined('DOING_API_TEST')) {
                define('DOING_API_TEST', true);
            }

            debug_to_file('Starting API connection test', 'API_TEST');
            $settings = $this->settings->get_settings();
            debug_to_file([
                'mailerlite_key_exists' => !empty($settings['api']['mailerlite_api_key']),
                'edd_key_exists' => !empty($settings['api']['edd_api_key'])
            ], 'API_TEST');

            $results = $this->test_api_connections();
            debug_to_file('API test completed', 'API_TEST');
            debug_to_file($results, 'API_TEST');

            // Store results
            set_transient('bema_api_test_results', [
                'status' => $results['status'],
                'messages' => $results['messages'],
                'timestamp' => time()
            ], 15);

            // Clear the in-progress flag
            delete_transient('bema_api_test_in_progress');

            wp_safe_redirect(add_query_arg([
                'page' => 'bema-settings',
                'test_status' => $results['status'],
                'test_time' => time()
            ], admin_url('admin.php')));
            exit;
        } catch (Exception $e) {
            // Clean up on error
            delete_transient('bema_api_test_in_progress');

            debug_to_file('Test connection handler error: ' . $e->getMessage(), 'API_TEST');

            set_transient('bema_api_test_results', [
                'status' => 'error',
                'messages' => [__('Error testing API connections: ', 'bema-crm') . $e->getMessage()]
            ], 15);

            wp_safe_redirect(add_query_arg([
                'page' => 'bema-settings',
                'test_status' => 'error',
                'test_time' => time()
            ], admin_url('admin.php')));
            exit;
        }
    }
}
