<?php

namespace Bema;

if (!defined('ABSPATH')) {
    exit;
}

// Define constants first
define('BEMA_VERSION', '1.0.0');
define('BEMA_FILE', __FILE__);
define('BEMA_PATH', plugin_dir_path(BEMA_FILE));
define('BEMA_URL', plugin_dir_url(BEMA_FILE));
define('BEMA_BASENAME', plugin_basename(BEMA_FILE));
define('BEMA_AJAX_NONCE', 'bema_ajax_nonce');



use Exception;
use \Throwable;

// Debug function
if (!function_exists('Bema\\debug_to_file')) {
    function debug_to_file($data, $label = '')
    {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return;
        }

        $output = date('Y-m-d H:i:s') . " " . $label . ": ";
        if (is_array($data) || is_object($data)) {
            $output .= print_r($data, true);
        } else {
            $output .= $data;
        }

        $log_file = defined('WP_CONTENT_DIR') ?
            WP_CONTENT_DIR . '/debug.log' :
            dirname(__FILE__) . '/debug.log';

        error_log($output . "\n", 3, $log_file);
    }
}

/**
 * Plugin Name: Bema CRM
 * Plugin URI: https://www.wordpress.org/bema-crm
 * Description: Bema Website Customer Relationship Model
 * Version: 1.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: Bema Integrated Services
 * Author URI: https://bemamusic.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bema-crm
 * Domain Path: /languages
 */

// Register autoloader
spl_autoload_register(function ($class) {
    try {
        $namespace = 'Bema\\';

    // If the class is not in our namespace, we ignore it.
    if (strpos($class, $namespace) !== 0) {
        return;
    }

    // A map of core class names to their file paths (relative to BEMA_PATH).
    // This is the most robust way to ensure critical classes are found.
    $class_file_map = [
        'Bema_CRM'          => 'bema_crm.php',
        'BemaCRMLogger'     => 'includes/bema-crm-logger.php',
        'Campaign_Manager'  => 'em_sync/class.campaign_manager.php',
        'EM_Sync'           => 'em_sync/class.em_sync.php',
        'EDD'               => 'em_sync/class.edd.php',
        'Triggers'          => 'em_sync/triggers/class-triggers.php',
        'Utils'             => 'em_sync/utils/class-utils-trigger.php',
    ];

    // Remove the namespace prefix from the class name.
    $relative_class_name = str_replace($namespace, '', $class);

    // Check if the class exists in our map.
    if (isset($class_file_map[$relative_class_name])) {
        $file_path = BEMA_PATH . $class_file_map[$relative_class_name];

        // If the file exists, we require it.
        if (file_exists($file_path)) {
            require_once $file_path;
            return;
        }
    }

    // If the class is not in the map, we fall back to a more general naming convention search.
    $file_name = str_replace('_', '-', strtolower($relative_class_name));
    $file_path = BEMA_PATH . 'includes/class-' . $file_name . '.php';

    if (file_exists($file_path)) {
        require_once $file_path;
        return;
    }

    
    } catch (Exception $e) {
        debug_to_file("Autoloader error: {$e->getMessage()}", 'AUTOLOADER_ERROR');
    }
});

debug_to_file('Autoloader registered');

// Main plugin class
class Bema_CRM
{
    private static $instance = null;
    private $logger;
    private $sync_instance;
    private $utils;
    private $sync_scheduler;
    private $admin_interface;
    private $settings;
    private $db_manager;
    private $group_db_manager;
    private $field_db_manager;
    private $subscriber_db_manager;
    private $component_registry = [];
    private $initialized = false;
    private static $instance_creating = false;

    // Plugin configuration
    const VERSION = '1.0.0';
    const MIN_PHP_VERSION = '7.4';
    const MIN_WP_VERSION = '5.6';
    const REQUIRED_PLUGINS = [];
    const RECOMMENDED_PLUGINS = [
        'easy-digital-downloads/easy-digital-downloads.php' => 'Easy Digital Downloads',
        'easy-digital-downloads-pro/easy-digital-downloads.php' => 'Easy Digital Downloads Pro'
    ];

    const REQUIRED_TABLES = [
        'bemacrmmeta',
        'sync_logs',
        'subscribers'
    ];

    const PROTECTED_DIRS = [
        'logs',
        'cache',
        'temp'
    ];

    const OPTION_SETTINGS = 'bema_crm_settings';
    const OPTION_DB_VERSION = 'bema_crm_db_version';
    const OPTION_SYNC_STATUS = 'bema_sync_status';
    const OPTION_SYNC_QUEUE = 'bema_sync_queue';
    const OPTION_FAILED_JOBS = 'bema_sync_failed_jobs';
    const OPTION_TIERS = 'bema_crm_tiers';
    const OPTION_TRANSITION_MATRIX = 'bema_crm_transition_matrix';

    public static function get_instance(): ?self
    {
        if (self::$instance_creating) {
            return self::$instance;
        }

        if (null === self::$instance) {
            self::$instance_creating = true;
            try {
                if (!self::check_requirements()) {
                    return null;
                }
                self::$instance = new self();
                debug_to_file('Plugin instance created successfully');
            } catch (Exception $e) {
                debug_to_file('Failed to create plugin instance: ' . $e->getMessage());
                return null;
            } finally {
                self::$instance_creating = false;
            }
        }
        return self::$instance;
    }

    private function __construct()
    {
        try {
            debug_to_file("Starting constructor");

            // Verify critical paths exist
            $this->verify_critical_paths();

            $this->init_error_handling();
            debug_to_file("Error handling initialized");

            $this->load_dependencies();
            debug_to_file("Dependencies loaded");

            $this->init_components();
            debug_to_file("Components initialized");

            $this->add_hooks();
            debug_to_file("Hooks added");

            $this->initialized = true;

            debug_to_file("Initialization complete");
        } catch (Exception $e) {
            debug_to_file("Initialization error: " . $e->getMessage());
            $this->handle_initialization_error($e);
        }
    }
    private function verify_critical_paths(): void
    {
        $critical_paths = [
            BEMA_PATH . 'includes',
            BEMA_PATH . 'includes/admin',
            BEMA_PATH . 'em_sync',
            BEMA_PATH . 'includes/exceptions',
            BEMA_PATH . 'includes/validators',
            BEMA_PATH . 'includes/handlers',
            BEMA_PATH . 'includes/interfaces'
        ];

        foreach ($critical_paths as $path) {
            if (!is_dir($path)) {
                throw new Exception("Critical directory missing: {$path}");
            }
        }
    }

    private static function check_requirements(): bool
    {
        try {
            debug_to_file('Starting requirements check');

            // Check PHP version
            if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
                throw new Exception(sprintf(
                    __('Bema CRM requires PHP %s or higher. Your PHP version: %s', 'bema-crm'),
                    self::MIN_PHP_VERSION,
                    PHP_VERSION
                ));
            }

            // Check WordPress version
            global $wp_version;
            if (version_compare($wp_version, self::MIN_WP_VERSION, '<')) {
                throw new Exception(sprintf(
                    __('Bema CRM requires WordPress %s or higher. Your WordPress version: %s', 'bema-crm'),
                    self::MIN_WP_VERSION,
                    $wp_version
                ));
            }

            // Enhanced EDD check with normalized paths
            if (is_admin()) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';

                $edd_base = 'easy-digital-downloads/easy-digital-downloads.php';
                $edd_pro = 'easy-digital-downloads-pro/easy-digital-downloads.php';

                $base_path = wp_normalize_path(WP_PLUGIN_DIR . '/' . $edd_base);
                $pro_path = wp_normalize_path(WP_PLUGIN_DIR . '/' . $edd_pro);

                // Enhanced checks
                $base_exists = file_exists($base_path);
                $pro_exists = file_exists($pro_path);
                $base_active = is_plugin_active($edd_base);
                $pro_active = is_plugin_active($edd_pro);

                $edd_exists = $base_exists || $pro_exists;
                $edd_active = $base_active || $pro_active;

                // Detailed logging
                debug_to_file('EDD Status Check:', 'EDD_CHECK');
                debug_to_file([
                    'edd_exists' => $edd_exists,
                    'edd_active' => $edd_active,
                    'base_exists' => $base_exists,
                    'pro_exists' => $pro_exists,
                    'base_active' => $base_active,
                    'pro_active' => $pro_active,
                    'base_path' => $base_path,
                    'pro_path' => $pro_path,
                    'wp_plugin_dir' => WP_PLUGIN_DIR,
                    'is_admin' => is_admin() ? 'yes' : 'no'
                ], 'EDD_CHECK');

                // Check if EDD classes are available
                if ($edd_active) {
                    debug_to_file([
                        'edd_class_exists' => class_exists('Easy_Digital_Downloads'),
                        'edd_req_check_exists' => class_exists('EDD_Requirements_Check'),
                        'edd_payment_exists' => class_exists('EDD_Payment'),
                        'edd_customer_exists' => class_exists('EDD_Customer')
                    ], 'EDD_CLASS_CHECK');
                }

                // Only show messages if there's actually an issue
                if (!$edd_exists && !$edd_active) {
                    add_action('admin_notices', function () {
                        printf(
                            '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
                            sprintf(
                                __('To enable all features, please install Easy Digital Downloads Pro. %s provides enhanced functionality with EDD Pro.', 'bema-crm'),
                                'Bema CRM'
                            )
                        );
                    });
                } elseif ($edd_exists && !$edd_active) {
                    add_action('admin_notices', function () {
                        printf(
                            '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
                            sprintf(
                                __('To enable advanced features, please activate Easy Digital Downloads Pro in your %sPlugins%s section.', 'bema-crm'),
                                '<a href="' . admin_url('plugins.php') . '">',
                                '</a>'
                            )
                        );
                    });
                }

                // Only show this if there's a core functionality issue
                if ($edd_active && !class_exists('Easy_Digital_Downloads')) {
                    debug_to_file('EDD active but core class not found', 'EDD_CHECK');
                    add_action('admin_notices', function () {
                        printf(
                            '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                            __('There seems to be an issue with EDD core classes. Please try deactivating and reactivating Easy Digital Downloads Pro.', 'bema-crm')
                        );
                    });
                }
            }

            debug_to_file('Requirements check completed successfully');
            return true;
        } catch (Exception $e) {
            debug_to_file('Requirements check failed: ' . $e->getMessage());
            add_action('admin_notices', function () use ($e) {
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html($e->getMessage())
                );
            });
            return false;
        }
    }

    private function init_error_handling(): void
    {
        set_error_handler([$this, 'handle_error']);
        set_exception_handler([$this, 'handle_exception']);
        register_shutdown_function([$this, 'handle_shutdown']);
    }

    private function load_dependencies(): void
    {
        try {
            // Core files that must be loaded first
            $core_files = [
                'includes/class-batch-processor.php',
                'includes/class-bema-logger.php',
                'includes/class-database-manager.php',
                'includes/class-database-migrations.php',
                'includes/class-sync-scheduler.php',
                'includes/class-settings.php',
                'includes/class-performance.php'
            ];

            foreach ($core_files as $file) {
                $filepath = BEMA_PATH . $file;
                if (!file_exists($filepath)) {
                    throw new Exception("Critical core file missing: {$file}");
                }
                require_once $filepath;
            }

            // Load interfaces first
            $interface_files = [
                'includes/interfaces/interface-provider.php',
                'includes/interfaces/interface-validator.php',
                'includes/interfaces/interface-lock-handler.php',
                'includes/interfaces/interface-health-monitor.php',
                'includes/interfaces/interface-stats-collector.php'
            ];

            foreach ($interface_files as $file) {
                $filepath = BEMA_PATH . $file;
                if (!file_exists($filepath)) {
                    throw new Exception("Required interface file missing: {$file}");
                }
                require_once $filepath;
            }

            // Load remaining dependencies
            $dependencies = [
                'includes/exceptions/class-base-exception.php',
                'includes/exceptions/class-sync-exception.php',
                'includes/exceptions/class-api-exception.php',
                'includes/exceptions/class-database-exception.php',
                'includes/exceptions/class-validation-exception.php',
                'includes/exceptions/class-retryable-exception.php',
                'includes/validators/class-base-validator.php',
                'includes/validators/class-campaign-validator.php',
                'includes/validators/class-subscriber-validator.php',
                'includes/validators/class-tier-validator.php',
                'includes/handlers/class-default-lock-handler.php',
                'includes/handlers/class-default-health-monitor.php',
                'includes/handlers/class-default-stats-collector.php',
                'includes/admin/class-admin-interface.php',
                'post-types/class.bema-cpt.php',
                'em_sync/class.edd.php',
                'em_sync/class.mailerlite.php',
                'em_sync/class.em_sync.php',
                'em_sync/utils/class-utils.php',
                'em_sync/triggers/class-edd-triggers.php',
            ];

            foreach ($dependencies as $file) {
                $filepath = BEMA_PATH . $file;
                if (!file_exists($filepath)) {
                    continue;
                }
                require_once $filepath;
            }

            $this->validate_dependencies();
        } catch (Exception $e) {
            throw new Exception("Critical initialization error: " . $e->getMessage());
        }
    }

    private function validate_dependencies(): void
    {
        $required_classes = [
            'Bema\\BemaCRMLogger',
            'Bema\\Database_Manager',
            'Bema\\EM_Sync',
            'Bema\\Sync_Scheduler',
            'Bema\\Bema_Settings',
            'Bema\\Triggers',
            'Bema\\Utils'
        ];

        foreach ($required_classes as $class) {
            if (!class_exists($class)) {
                throw new Exception("Required class not found: {$class}");
            }
        }
    }

    private function init_components(): void
    {
        try {
            if ($this->initialized) {
                return;
            }

            
            // Initialize logger first
            if (!isset($this->logger)) {
                $this->logger = new BemaCRMLogger();
                $this->component_registry['logger'] = $this->logger;
            }

            // Initialize utils
            if (!isset($this->utils)) {
                $this->utils = new Utils;
                $this->component_registry['utils'] = $this->settings;
            }
            
            // Initialize settings with logger
            if (!isset($this->settings) && isset($this->logger)) {
                $this->settings = Bema_Settings::get_instance($this->logger);
                $this->component_registry['settings'] = $this->settings;
            }

            // Initialize database manager
            if (!isset($this->db_manager) && isset($this->logger)) {
                global $wpdb;
                $this->db_manager = new Database_Manager($wpdb, $this->logger);
                $this->component_registry['db_manager'] = $this->db_manager;
            }

            // Check if EDD or EDD Pro is active
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $edd_active = \is_plugin_active('easy-digital-downloads/easy-digital-downloads.php') ||
                \is_plugin_active('easy-digital-downloads-pro/easy-digital-downloads.php');

            if ($edd_active) {
                if ($this->settings && $this->logger) {
                    $this->initialize_sync_components();
                }
            } else {
                debug_to_file('EDD not active - skipping sync component initialization');
            }

            // Initialize admin components
            if (is_admin()) {
                $this->initialize_admin_components();
            }

            $this->initialized = true;
        } catch (Exception $e) {
            throw $e;
        }
    }

    private function initialize_sync_components(): void
    {
        try {
            debug_to_file('Initializing sync components with detailed logging');

            // Enhanced EDD check
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $edd_base = 'easy-digital-downloads/easy-digital-downloads.php';
            $edd_pro = 'easy-digital-downloads-pro/easy-digital-downloads.php';
            $edd_active = \is_plugin_active($edd_base) || \is_plugin_active($edd_pro);

            debug_to_file([
                'edd_active' => $edd_active ? 'yes' : 'no',
                'base_active' => \is_plugin_active($edd_base) ? 'yes' : 'no',
                'pro_active' => \is_plugin_active($edd_pro) ? 'yes' : 'no',
                'base_exists' => file_exists(WP_PLUGIN_DIR . '/' . $edd_base) ? 'yes' : 'no',
                'pro_exists' => file_exists(WP_PLUGIN_DIR . '/' . $edd_pro) ? 'yes' : 'no',
                'base_path' => WP_PLUGIN_DIR . '/' . $edd_base,
                'pro_path' => WP_PLUGIN_DIR . '/' . $edd_pro
            ], 'SYNC_INIT_CHECK');

            if (!$edd_active) {
                debug_to_file('EDD not active - skipping sync component initialization', 'SYNC_INIT');
                return;
            }

            $settings = get_option('bema_crm_settings');

            // Initialize providers with logging
            debug_to_file('Initializing MailerLite provider', 'PROVIDER_INIT');
            $mailerlite = new \Bema\Providers\MailerLite(
                $settings['api']['mailerlite_api_key'] ?? '',
                $this->logger
            );
            debug_to_file('MailerLite provider initialized', 'PROVIDER_INIT');

            debug_to_file('Initializing EDD provider', 'PROVIDER_INIT');
            $edd = new \Bema\Providers\EDD(
                $settings['api']['edd_api_key'] ?? '',
                $settings['api']['edd_token'] ?? '',
                $this->logger
            );
            debug_to_file('EDD provider initialized', 'PROVIDER_INIT');

            // Initialize sync instance with logging
            debug_to_file('Initializing EM_Sync instance', 'SYNC_INIT');

            // Create EM_Sync instance first
            $this->sync_instance = new \Bema\EM_Sync(
                $mailerlite,
                $edd,
                $this->logger,
                $this->settings
            );

            // create group db instance
            $this->group_db_manager = new \Bema\Group_Database_Manager();
            // create field db instance
            $this->field_db_manager = new \Bema\Field_Database_Manager();
            // create subscriber db instance
            $this->subscriber_db_manager = new \Bema\Subscribers_Database_Manager();


            // Initialize handlers
            $lock_handler = new \Bema\Handlers\Default_Lock_Handler();
            $health_monitor = new \Bema\Handlers\Default_Health_Monitor($this->logger);
            $stats_collector = new \Bema\Handlers\Default_Stats_Collector();

            // Create Sync_Scheduler instance
            debug_to_file('Initializing Sync Scheduler', 'SYNC_INIT');
            $this->sync_scheduler = \Bema\Sync_Scheduler::get_instance(
                $this->logger,
                $this->sync_instance,
                $lock_handler,
                $health_monitor,
                $stats_collector
            );

            // Update EM_Sync with the scheduler
            if ($this->sync_instance && method_exists($this->sync_instance, 'setSyncScheduler')) {
                $this->sync_instance->setSyncScheduler($this->sync_scheduler);
                debug_to_file('Sync scheduler set in EM_Sync', 'SYNC_INIT');
            }

            debug_to_file([
                'sync_instance_created' => isset($this->sync_instance) ? 'yes' : 'no',
                'sync_scheduler_created' => isset($this->sync_scheduler) ? 'yes' : 'no'
            ], 'SYNC_COMPONENTS_STATUS');

            debug_to_file('Sync components initialized successfully');
        } catch (Exception $e) {
            debug_to_file([
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString()
            ], 'SYNC_INIT_ERROR');

            $this->logger?->log('Sync initialization failed', 'error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function initialize_admin_components(): void
    {
        try {
            debug_to_file('Initializing admin components');

            if (!$this->settings || !$this->logger) {
                debug_to_file('Missing required settings or logger');
                return;
            }

            // Get EDD plugin status
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $has_edd = \is_plugin_active('easy-digital-downloads/easy-digital-downloads.php') ||
                \is_plugin_active('easy-digital-downloads-pro/easy-digital-downloads.php');

            // Update the order of parameters to match the constructor definition
            $this->admin_interface = new \Bema\Admin\Bema_Admin_Interface(
                $this->logger,                              // Required: BemaCRMLogger
                $this->settings,                           // Required: Bema_Settings
                $has_edd ? $this->sync_instance : null,    // Optional: ?EM_Sync
                $has_edd ? $this->sync_scheduler : null    // Optional: ?Sync_Scheduler
            );

            $this->component_registry['admin'] = $this->admin_interface;
            debug_to_file('Admin components initialized successfully');
        } catch (Exception $e) {
            debug_to_file('Admin initialization failed: ' . $e->getMessage());
            $this->logger->log('Failed to initialize admin components', 'error', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            'bema-crm',
            false,
            dirname(plugin_basename(BEMA_FILE)) . '/languages'
        );
        debug_to_file('Text domain loaded');
    }

    /**
     * Check plugin dependencies
     */
    public function check_plugin_dependencies(): void
    {
        try {
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $edd_base = 'easy-digital-downloads/easy-digital-downloads.php';
            $edd_pro = 'easy-digital-downloads-pro/easy-digital-downloads.php';

            // Check if either EDD or EDD Pro is active
            $edd_active = is_plugin_active($edd_base) || is_plugin_active($edd_pro);

            debug_to_file([
                'edd_base_active' => is_plugin_active($edd_base),
                'edd_pro_active' => is_plugin_active($edd_pro),
                'edd_active' => $edd_active
            ], 'DEPENDENCIES_CHECK');

            // Only show notice if neither is active
            if (!$edd_active) {
                add_action('admin_notices', function () {
                    printf(
                        '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                        sprintf(
                            __('For full functionality, %s requires either Easy Digital Downloads or Easy Digital Downloads Pro to be active.', 'bema-crm'),
                            'Bema CRM'
                        )
                    );
                });
            }
        } catch (Exception $e) {
            debug_to_file('Plugin dependency check failed: ' . $e->getMessage(), 'DEPENDENCIES_ERROR');
        }
    }

    /**
     * Add hook methods
     */
    private function add_hooks(): void
    {
        debug_to_file('Adding hooks');

        try {
            // WordPress core hooks
            add_action('init', [$this, 'load_textdomain']);
            add_action('plugins_loaded', [$this, 'check_plugin_dependencies']);

            // Admin-specific hooks
            if (is_admin()) {
                add_action('admin_menu', [$this, 'initialize_admin_interface']);
                add_action('admin_notices', [$this, 'display_admin_notices']);
            }

            // Add CRM Trigger
            $mailerlite = new \Bema\Providers\MailerLite(get_option('bema_crm_settings')['api']['mailerlite_api_key'] ?? '', $this->logger );
            $triggers = new Triggers($mailerlite, $this->sync_instance, $this->utils, $this->group_db_manager, $this->field_db_manager, $this->logger);
            $triggers->init();

            debug_to_file('Hooks added successfully');
        } catch (Exception $e) {
            debug_to_file('Error adding hooks: ' . $e->getMessage());
            $this->logger?->log('Failed to add hooks', 'error', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Display admin notices
     */
    public function display_admin_notices(): void
    {
        if (isset($this->admin_interface)) {
            $this->admin_interface->display_admin_notices();
        }
    }

    /**
     * Initialize admin interface
     */
    public function initialize_admin_interface(): void
    {
        if (is_admin() && !$this->admin_interface) {
            try {
                // Get EDD plugin status
                if (!function_exists('is_plugin_active')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }

                $has_edd = \is_plugin_active('easy-digital-downloads/easy-digital-downloads.php') ||
                    \is_plugin_active('easy-digital-downloads-pro/easy-digital-downloads.php');

                // Initialize admin interface with correct parameter order:
                // BemaCRMLogger, Bema_Settings, ?EM_Sync, ?Sync_Scheduler
                $this->admin_interface = new \Bema\Admin\Bema_Admin_Interface(
                    $this->logger,           // Required logger
                    $this->settings,         // Required settings
                    $has_edd ? $this->sync_instance : null,    // Optional sync instance
                    $has_edd ? $this->sync_scheduler : null    // Optional scheduler
                );

                $this->component_registry['admin'] = $this->admin_interface;
                debug_to_file('Admin interface initialized');
            } catch (Exception $e) {
                debug_to_file('Failed to initialize admin interface: ' . $e->getMessage());
                if (isset($this->logger)) {
                    $this->logger->log('Admin interface initialization failed', 'error', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    public function handle_error($errno, $errstr, $errfile, $errline): bool
    {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $error_message = sprintf(
            'PHP Error [%d]: %s in %s on line %d',
            $errno,
            $errstr,
            $errfile,
            $errline
        );

        debug_to_file($error_message, 'ERROR');

        $this->logger?->log($error_message, 'error', [
            'errno' => $errno,
            'file' => $errfile,
            'line' => $errline
        ]);

        return true;
    }

    public function handle_exception(Throwable $e): void
    {
        $error_message = sprintf(
            'Uncaught %s: %s in %s on line %d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        debug_to_file($error_message, 'EXCEPTION');

        $this->logger?->log($error_message, 'error', [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function clear_plugin_cache(): void
    {
        try {
            // Clear WordPress cache
            wp_cache_flush();

            // Clear specific transients
            $transients = [
                'bema_sync_status',
                'bema_last_sync',
                'bema_health_status',
                'edd_api_test_results',
                'bema_api_test_results',
                'bema_api_test_in_progress'
            ];

            foreach ($transients as $transient) {
                delete_transient($transient);
            }

            // Clear plugin specific options that might be cached
            wp_cache_delete('bema_sync_running', 'options');
            wp_cache_delete('bema_crm_settings', 'options');

            // Clear any EDD related caches
            wp_cache_delete('edd_api_test', 'edd_cache');
            wp_cache_delete('mailerlite_test_connection', 'mailerlite_cache');

            debug_to_file('Plugin cache cleared successfully', 'CACHE_CLEAR');
        } catch (Exception $e) {
            debug_to_file('Error clearing cache: ' . $e->getMessage(), 'CACHE_ERROR');
        }
    }

    public function handle_shutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR])) {
            $error_message = sprintf(
                'Fatal Error: %s in %s on line %d',
                $error['message'],
                $error['file'],
                $error['line']
            );

            debug_to_file($error_message, 'FATAL');

            $this->logger?->log($error_message, 'critical', [
                'type' => $error['type'],
                'file' => $error['file'],
                'line' => $error['line']
            ]);
        }
    }

    private function handle_initialization_error(Exception $e): void
    {
        $error_message = sprintf(
            'Plugin initialization failed: %s',
            $e->getMessage()
        );

        debug_to_file($error_message, 'INIT_ERROR');

        if (isset($this->logger)) {
            $this->logger->log($error_message, 'critical', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        add_action('admin_notices', function () use ($error_message) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html($error_message)
            );
        });
    }

    public static function activate(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        try {
            debug_to_file('Starting plugin activation');

            // Check PHP version
            if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
                throw new Exception(sprintf(
                    'PHP %s or higher is required. Current version is %s',
                    self::MIN_PHP_VERSION,
                    PHP_VERSION
                ));
            }

            // Check WordPress version
            global $wp_version;
            if (version_compare($wp_version, self::MIN_WP_VERSION, '<')) {
                throw new Exception(sprintf(
                    'WordPress %s or higher is required. Current version is %s',
                    self::MIN_WP_VERSION,
                    $wp_version
                ));
            }

            // Initialize database
            require_once BEMA_PATH . 'includes/class-bema-logger.php';
            require_once BEMA_PATH . 'includes/class-database-migrations.php';

            $logger = new BemaCRMLogger();
            $migrations = new Database_Migrations($logger);

            if (!$migrations->install()) {
                throw new Exception('Database installation failed');
            }

            // Add this line to handle upgrades
            $migrations->maybe_upgrade();

            // Initialize database optimizer
            global $wpdb;
            $db_manager = new Database_Manager($wpdb, $logger);
            $db_manager->optimize_tables();

            // Initialize default settings if not exists
            if (get_option(self::OPTION_SETTINGS, false) === false) {
                self::initialize_default_settings();
            }

            // Initialize option tier settings if not exists
            if (get_option(self::OPTION_TIERS, false) === false) {
                self::initialize_tier_settings();
            }

            // Initialize transition matrix settings if not exists
            if (get_option(self::OPTION_TRANSITION_MATRIX, false) === false) {
                self::initialize_transition_settings();
            }

            // Create required directories
            self::create_directories();

            // Clear cache at the end of activation
            $instance = self::get_instance();
            if ($instance) {
                $instance->clear_plugin_cache();
            }

            // create group Table
            $group_db_manager = new \Bema\Group_Database_Manager();
            $group_db_manager->create_table();

            // Clear rewrite rules
            flush_rewrite_rules();

            debug_to_file('Plugin activation completed successfully');
        } catch (Exception $e) {
            debug_to_file('Activation failed: ' . $e->getMessage());
            wp_die(
                esc_html($e->getMessage()),
                'Plugin Activation Error',
                ['back_link' => true]
            );
        }
    }

    public static function deactivate(): void
    {
        try {
            debug_to_file('Starting plugin deactivation');

            // Clear scheduled hooks
            $hooks_to_clear = [
                'bema_daily_sync',
                'bema_health_check',
                'bema_cleanup_logs'
            ];

            foreach ($hooks_to_clear as $hook) {
                wp_clear_scheduled_hook($hook);
            }

            // Clear transients
            $transients_to_delete = [
                'bema_sync_status',
                'bema_last_sync',
                'bema_health_status'
            ];

            // Clear Options
            $options_to_delete = [
                SELF::OPTION_TIERS,
                SELF::OPTION_TRANSITION_MATRIX
            ];

            foreach ($transients_to_delete as $transient) {
                delete_transient($transient);
            }

            foreach ($options_to_delete as $option) {
                delete_option($option);
            }

            // Clear sync status
            update_option('bema_sync_running', false);

            // Clear rewrite rules
            flush_rewrite_rules();

            debug_to_file('Plugin deactivation completed successfully');
        } catch (Exception $e) {
            debug_to_file('Deactivation error: ' . $e->getMessage());
            error_log('Bema CRM deactivation error: ' . $e->getMessage());
        }
    }

    public static function uninstall(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        try {
            debug_to_file('Starting plugin uninstallation');

            $instance = self::get_instance();
            if (!$instance) {
                throw new Exception('Failed to initialize plugin instance during uninstall');
            }

            // Clean up database tables
            $migrations = new Database_Migrations($instance->logger);
            $migrations->uninstall();

            // Remove all plugin options
            $options = [
                self::OPTION_SETTINGS,
                self::OPTION_DB_VERSION,
                self::OPTION_SYNC_STATUS,
                self::OPTION_SYNC_QUEUE,
                self::OPTION_FAILED_JOBS
            ];

            foreach ($options as $option) {
                delete_option($option);
            }

            // Remove plugin directories
            self::remove_plugin_directories();

            debug_to_file('Plugin uninstallation completed successfully');
        } catch (Exception $e) {
            debug_to_file('Uninstallation error: ' . $e->getMessage());
            error_log('Bema CRM uninstall error: ' . $e->getMessage());
        }
    }

    private static function initialize_default_settings(): void
    {
        $default_settings = [
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
                'max_file_size' => 10485760
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
                'cache_ttl' => 3600
            ]
        ];

        update_option(self::OPTION_SETTINGS, $default_settings);
        debug_to_file('Default settings initialized');
    }

    private static function initialize_tier_settings(): void
    {
        $default_tiers = array(
            'Opt-In',
            'Wood',
            'Gold',
            'Silver',
            'Bronze',
            'Bronze Purchase',
            'Sliver Purchase',
            'Gold Purchase',
        );

        add_option('bema_crm_tiers', $default_tiers);
        debug_to_file('Default tier settings initialized');
    }

    private static function initialize_transition_settings(): void
    {
        $default_transition_matrix = [
            [
                'current_tier'      => 'Gold Purchase',
                'next_tier'         => 'Gold',
                'requires_purchase' => true
            ],
            [
                'current_tier'      => 'Silver Purchase',
                'next_tier'         => 'Silver',
                'requires_purchase' => true
            ],
            [
                'current_tier'      => 'Bronze Purchase',
                'next_tier'         => 'Opt-in',
                'requires_purchase' => true
            ]
        ];

        add_option('bema_crm_transition_matrix', $default_transition_matrix);
        debug_to_file('Default transition matrix settings initialized');
    }
    

    private static function create_directories(): void
    {
        foreach (self::PROTECTED_DIRS as $dir) {
            $path = BEMA_PATH . $dir;
            if (!file_exists($path)) {
                if (!wp_mkdir_p($path)) {
                    throw new Exception("Failed to create directory: {$dir}");
                }
                file_put_contents($path . '/.htaccess', 'deny from all');
                file_put_contents($path . '/index.php', '<?php // Silence is golden');
                chmod($path, 0755);
                debug_to_file("Created protected directory: {$dir}");
            }
        }
    }

    private static function remove_directory_recursive(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object !== "." && $object !== "..") {
                    $path = $dir . "/" . $object;
                    is_dir($path) ? self::remove_directory_recursive($path) : unlink($path);
                }
            }
            rmdir($dir);
            debug_to_file("Removed directory: {$dir}");
        }
    }

    private static function remove_plugin_directories(): void
    {
        foreach (self::PROTECTED_DIRS as $dir) {
            $path = BEMA_PATH . $dir;
            if (is_dir($path)) {
                self::remove_directory_recursive($path);
            }
        }
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
}

// Initialize the plugin
add_action('plugins_loaded', function () {
    debug_to_file('Initializing plugin through plugins_loaded hook');
    try {
        // Initialize performance optimizations first
        if (is_admin()) {
            Performance::get_instance()->init();
        }

        if (!defined('BEMA_PATH') || !defined('BEMA_VERSION')) {
            throw new Exception('Required plugin constants are not defined');
        }

        $instance = Bema_CRM::get_instance();
        if (!$instance) {
            throw new Exception('Failed to create plugin instance');
        }

        debug_to_file('Plugin instance created successfully');
    } catch (Exception $e) {
        debug_to_file('Error initializing plugin: ' . $e->getMessage());
        error_log('Bema CRM initialization error: ' . $e->getMessage());
    }
}, 5);

// Register activation and deactivation hooks
register_activation_hook(__FILE__, function () {
    debug_to_file('Activation hook triggered');
    try {
        Bema_CRM::activate();
    } catch (Exception $e) {
        debug_to_file('Activation error: ' . $e->getMessage());
        wp_die(
            esc_html($e->getMessage()),
            'Plugin Activation Error',
            ['back_link' => true]
        );
    }
});

register_deactivation_hook(__FILE__, function () {
    debug_to_file('Deactivation hook triggered');
    try {
        Bema_CRM::deactivate();
    } catch (Exception $e) {
        debug_to_file('Deactivation error: ' . $e->getMessage());
        error_log('Bema CRM deactivation error: ' . $e->getMessage());
    }
});

register_uninstall_hook(__FILE__, ['\Bema\Bema_CRM', 'uninstall']);
