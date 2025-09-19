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

// Initialize logger for plugin-wide use
if (!class_exists('Bema_CRM_Logger')) {
    require_once BEMA_PATH . 'includes/class-bema-crm-logger.php';
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
        $base_dir = BEMA_PATH . 'includes/';

        // If the class is not in our namespace, ignore it.
        if (strpos($class, $namespace) !== 0) {
            return;
        }

        // A map of core class names to their file paths (relative to BEMA_PATH).
        $class_file_map = [
            'Bema_CRM' => 'bema_crm.php',
            'Bema_CRM_Logger' => 'includes/class-bema-crm-logger.php',
            'Campaign_Manager' => 'em_sync/class.campaign_manager.php',
            'EM_Sync' => 'em_sync/class.em_sync.php',
            'EDD' => 'em_sync/class.edd.php',
            'Triggers' => 'em_sync/triggers/class-triggers.php',
            'Utils' => 'em_sync/utils/class-utils.php',
            'Sync_Manager' => 'em_sync/sync/class-sync-manager.php',
            'Transition_Manager' => 'em_sync/transition/class-transition-manager.php',
        ];

        // Remove the namespace prefix from the class name.
        $relative_class_name = str_replace($namespace, '', $class);

        // Check the explicit map first.
        if (isset($class_file_map[$relative_class_name])) {
            $file_path = BEMA_PATH . $class_file_map[$relative_class_name];
            if (file_exists($file_path)) {
                require_once $file_path;
                return;
            }
        }

        // Convert class name to file path with subdirectory and underscore handling.
        // e.g., 'Bema\Database\Group_Database_Manager' becomes 'database/class-group-database-manager.php'
        $parts = explode('\\', $relative_class_name);
        $last_part = array_pop($parts);

        // Convert underscores in the class name to hyphens for the filename.
        $file_name = 'class-' . strtolower(str_replace('_', '-', $last_part)) . '.php';

        // Build the subdirectory path from the remaining parts.
        $sub_dir = implode(DIRECTORY_SEPARATOR, array_map('strtolower', $parts));

        // Construct the full file path.
        $file_path = $base_dir . $sub_dir . DIRECTORY_SEPARATOR . $file_name;

        // Normalize the path and require the file if it exists.
        $normalized_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file_path);

        if (file_exists($normalized_path)) {
            require_once $normalized_path;
        }
    } catch (Exception $e) {
        Bema_CRM::get_logger()->error("Autoloader error: {$e->getMessage()}", ['context' => 'AUTOLOADER_ERROR']);
    }
});

Bema_CRM::get_logger()->info('Autoloader registered');

// Main plugin class
class Bema_CRM
{
    private static $instance = null;
    private static $static_logger = null;
    private $logger;
    private $sync_instance;
    private $transition_instance;
    private $utils;
    private $system_logger;
    private $transition_db_manager;
    private $transition_subscribers_db_manager;
    private $campaign_db_manager;
    private $group_db_manager;
    private $field_db_manager;
    private $subscriber_db_manager;
    private $campaign_group_subscribers_db_manager;
    private $sync_db_manager;

    private $admin_interface;
    private $settings;
    private $db_manager;
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

    /**
     * Get logger instance - works from anywhere in the code
     * @return Bema_CRM_Logger
     */
    public static function get_logger() {
        if (self::$static_logger === null) {
            self::$static_logger = Bema_CRM_Logger::create('bema-crm-main');
        }
        return self::$static_logger;
    }

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
                Bema_CRM::get_logger()->info('Plugin instance created successfully');
            } catch (Exception $e) {
                Bema_CRM::get_logger()->error('Failed to create plugin instance: ' . $e->getMessage());
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
            Bema_CRM::get_logger()->info('=== STARTING PLUGIN CONSTRUCTOR ===');

            // Verify critical paths exist
            Bema_CRM::get_logger()->debug('Verifying critical paths');
            $this->verify_critical_paths();
            Bema_CRM::get_logger()->debug('Critical paths verified successfully');

            // Initialize error handling
            Bema_CRM::get_logger()->debug('Initializing error handling');
            $this->init_error_handling();
            Bema_CRM::get_logger()->debug('Error handling initialized successfully');

            // Load dependencies
            Bema_CRM::get_logger()->debug('Loading dependencies');
            $this->load_dependencies();
            Bema_CRM::get_logger()->debug('Dependencies loaded successfully');

            // Initialize components
            Bema_CRM::get_logger()->debug('Initializing components');
            $this->init_components();
            Bema_CRM::get_logger()->debug('Components initialized successfully');

            // Add hooks
            Bema_CRM::get_logger()->debug('Adding WordPress hooks');
            $this->add_hooks();
            Bema_CRM::get_logger()->debug('WordPress hooks added successfully');

            // Initialize default tiers
            Bema_CRM::get_logger()->debug('Setting up default tiers');
            $this->setup_default_tiers();
            Bema_CRM::get_logger()->debug('Default tiers setup completed');

            $this->initialized = true;

            Bema_CRM::get_logger()->info('=== PLUGIN CONSTRUCTOR COMPLETED SUCCESSFULLY ===', [
                'components_initialized' => count($this->component_registry),
                'component_list' => array_keys($this->component_registry)
            ]);
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('=== PLUGIN CONSTRUCTOR FAILED ===', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->handle_initialization_error($e);
        }
    }

    private function setup_default_tiers(): void
    {
        try {
            $default_tiers = array(
                'Opt-In',
                'Wood',
                'Gold',
                'Silver',
                'Bronze',
                'Bronze Purchase',
                'Silver Purchase',
                'Gold Purchase',
            );

            update_option('bema_crm_tiers', $default_tiers);
            Bema_CRM::get_logger()->debug('Default tiers updated successfully', [
                'tier_count' => count($default_tiers)
            ]);
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Failed to setup default tiers', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Default tiers setup failed: " . $e->getMessage());
        }
    }

    /**
     * Get component initialization status
     */
    public function get_component_status(): array
    {
        return [
            'initialized' => $this->initialized,
            'component_count' => count($this->component_registry),
            'components' => array_keys($this->component_registry),
            'has_logger' => isset($this->logger),
            'has_settings' => isset($this->settings),
            'has_sync_instance' => isset($this->sync_instance),
            'has_admin_interface' => isset($this->admin_interface),
            'has_db_manager' => isset($this->db_manager)
        ];
    }

    /**
     * Get detailed initialization status for debugging
     */
    public function get_initialization_status(): array
    {
        $edd_active = false;
        if (function_exists('is_plugin_active')) {
            $edd_active = \is_plugin_active('easy-digital-downloads/easy-digital-downloads.php') ||
                \is_plugin_active('easy-digital-downloads-pro/easy-digital-downloads.php');
        }

        return [
            'plugin_initialized' => $this->initialized,
            'static_instance_exists' => self::$instance !== null,
            'component_registry_count' => count($this->component_registry),
            'registered_components' => array_keys($this->component_registry),
            'core_components' => [
                'logger' => isset($this->logger),
                'settings' => isset($this->settings),
                'utils' => isset($this->utils),
                'db_manager' => isset($this->db_manager),
            ],
            'database_managers' => [
                'sync_db_manager' => isset($this->sync_db_manager),
                'campaign_db_manager' => isset($this->campaign_db_manager),
                'subscriber_db_manager' => isset($this->subscriber_db_manager),
                'transition_db_manager' => isset($this->transition_db_manager),
            ],
            'sync_components' => [
                'sync_instance' => isset($this->sync_instance),
                'transition_instance' => isset($this->transition_instance),

            ],
            'admin_components' => [
                'admin_interface' => isset($this->admin_interface),
                'is_admin_context' => is_admin(),
            ],
            'environment' => [
                'edd_active' => $edd_active,
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => self::VERSION,
            ]
        ];
    }
    private function verify_critical_paths(): void
    {
        $critical_paths = [
            BEMA_PATH . 'includes',
            BEMA_PATH . 'includes/admin',
            BEMA_PATH . 'em_sync',
            BEMA_PATH . 'includes/exceptions',

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
            Bema_CRM::get_logger()->debug('Starting requirements check');

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
                Bema_CRM::get_logger()->debug('EDD Status Check:', ['context' => 'EDD_CHECK']);
                Bema_CRM::get_logger()->debug('EDD Status Details', [
                    'context' => 'EDD_CHECK',
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
                ]);

                // Check if EDD classes are available
                if ($edd_active) {
                    Bema_CRM::get_logger()->debug('EDD Class Check', [
                        'context' => 'EDD_CLASS_CHECK',
                        'edd_class_exists' => class_exists('Easy_Digital_Downloads'),
                        'edd_req_check_exists' => class_exists('EDD_Requirements_Check'),
                        'edd_payment_exists' => class_exists('EDD_Payment'),
                        'edd_customer_exists' => class_exists('EDD_Customer')
                    ]);
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
                    Bema_CRM::get_logger()->warning('EDD active but core class not found', ['context' => 'EDD_CHECK']);
                    add_action('admin_notices', function () {
                        printf(
                            '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                            __('There seems to be an issue with EDD core classes. Please try deactivating and reactivating Easy Digital Downloads Pro.', 'bema-crm')
                        );
                    });
                }
            }

            Bema_CRM::get_logger()->info('Requirements check completed successfully');
            return true;
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Requirements check failed: ' . $e->getMessage());
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
            Bema_CRM::get_logger()->info('=== STARTING DEPENDENCY LOADING ===');

            // Core files that must be loaded first
            $core_files = [
                'includes/class-bema-crm-logger.php',
                'includes/class-database-manager.php',
                'includes/class-database-migrations.php',

                'includes/class-settings.php',
                'includes/class-performance.php'
            ];

            Bema_CRM::get_logger()->debug('Loading core files', ['count' => count($core_files)]);
            foreach ($core_files as $file) {
                $filepath = BEMA_PATH . $file;
                if (!file_exists($filepath)) {
                    throw new Exception("Critical core file missing: {$file}");
                }
                require_once $filepath;
                Bema_CRM::get_logger()->debug("Loaded core file: {$file}");
            }
            Bema_CRM::get_logger()->info('Core files loaded successfully');

            // Load interfaces first
            $interface_files = [
                'includes/interfaces/interface-provider.php',
                'includes/interfaces/interface-lock-handler.php',
                'includes/interfaces/interface-health-monitor.php',
                'includes/interfaces/interface-stats-collector.php'
            ];

            Bema_CRM::get_logger()->debug('Loading interface files', ['count' => count($interface_files)]);
            foreach ($interface_files as $file) {
                $filepath = BEMA_PATH . $file;
                if (!file_exists($filepath)) {
                    throw new Exception("Required interface file missing: {$file}");
                }
                require_once $filepath;
                Bema_CRM::get_logger()->debug("Loaded interface file: {$file}");
            }
            Bema_CRM::get_logger()->info('Interface files loaded successfully');

            // Load remaining dependencies
            $dependencies = [
                'includes/exceptions/class-base-exception.php',
                'includes/exceptions/class-sync-exception.php',
                'includes/exceptions/class-api-exception.php',
                'includes/exceptions/class-database-exception.php',
                'includes/exceptions/class-retryable-exception.php',
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

            $loaded_count = 0;
            $skipped_count = 0;
            
            Bema_CRM::get_logger()->debug('Loading dependency files', ['count' => count($dependencies)]);
            foreach ($dependencies as $file) {
                $filepath = BEMA_PATH . $file;
                if (!file_exists($filepath)) {
                    Bema_CRM::get_logger()->debug("Skipping missing optional file: {$file}");
                    $skipped_count++;
                    continue;
                }
                require_once $filepath;
                Bema_CRM::get_logger()->debug("Loaded dependency file: {$file}");
                $loaded_count++;
            }
            
            Bema_CRM::get_logger()->info('Dependency files processed', [
                'loaded' => $loaded_count,
                'skipped' => $skipped_count,
                'total' => count($dependencies)
            ]);

            // Validate critical dependencies
            Bema_CRM::get_logger()->debug('Validating dependencies');
            $this->validate_dependencies();
            Bema_CRM::get_logger()->info('Dependencies validated successfully');

            Bema_CRM::get_logger()->info('=== DEPENDENCY LOADING COMPLETED ===');
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('=== DEPENDENCY LOADING FAILED ===', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new Exception("Critical initialization error: " . $e->getMessage());
        }
    }

    private function validate_dependencies(): void
    {
        Bema_CRM::get_logger()->debug('Starting dependency validation');
        
        $required_classes = [
            'Bema\\Bema_CRM_Logger',
            'Bema\\Database_Manager',
            'Bema\\EM_Sync',

            'Bema\\Bema_Settings',
            'Bema\\Triggers',
            'Bema\\Utils'
        ];

        $missing_classes = [];
        $found_classes = [];

        foreach ($required_classes as $class) {
            if (!class_exists($class)) {
                $missing_classes[] = $class;
                Bema_CRM::get_logger()->warning("Required class not found: {$class}");
            } else {
                $found_classes[] = $class;
                Bema_CRM::get_logger()->debug("Required class found: {$class}");
            }
        }

        if (!empty($missing_classes)) {
            Bema_CRM::get_logger()->error('Dependency validation failed', [
                'missing_classes' => $missing_classes,
                'found_classes' => $found_classes
            ]);
            throw new Exception("Required classes not found: " . implode(', ', $missing_classes));
        }

        Bema_CRM::get_logger()->info('Dependency validation completed successfully', [
            'validated_classes' => count($found_classes),
            'classes' => $found_classes
        ]);
    }

    private function init_components(): void
    {
        try {
            if ($this->initialized) {
                Bema_CRM::get_logger()->debug('Components already initialized, skipping');
                return;
            }

            Bema_CRM::get_logger()->info('=== STARTING COMPONENT INITIALIZATION ===');

            // Initialize logger first
            $this->init_core_logger();
            
            // Initialize utilities
            $this->init_utilities();
            
            // Initialize settings
            $this->init_settings();
            
            // Initialize database manager
            $this->init_database_manager();
            
            // Initialize database tables
            $this->init_database_tables();
            
            // Initialize EDD-dependent components
            $this->init_edd_components();
            
            // Initialize admin components
            if (is_admin()) {
                $this->init_admin_components_internal();
            }

            $this->initialized = true;
            Bema_CRM::get_logger()->info('=== COMPONENT INITIALIZATION COMPLETED SUCCESSFULLY ===', [
                'total_components' => count($this->component_registry),
                'components' => array_keys($this->component_registry)
            ]);
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('=== COMPONENT INITIALIZATION FAILED ===', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function init_core_logger(): void
    {
        try {
            Bema_CRM::get_logger()->debug('Initializing core logger component');
            
            if (!isset($this->logger)) {
                $this->logger = Bema_CRM_Logger::create('bema-crm-core');
                $this->component_registry['logger'] = $this->logger;
                Bema_CRM::get_logger()->info('Core logger initialized successfully');
            } else {
                Bema_CRM::get_logger()->debug('Core logger already exists');
            }
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Failed to initialize core logger', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Core logger initialization failed: " . $e->getMessage());
        }
    }

    private function init_utilities(): void
    {
        try {
            Bema_CRM::get_logger()->debug('Initializing utilities component');
            
            if (!isset($this->utils)) {
                $this->utils = new Utils;
                $this->component_registry['utils'] = $this->utils;
                Bema_CRM::get_logger()->info('Utilities initialized successfully');
            } else {
                Bema_CRM::get_logger()->debug('Utilities already exists');
            }
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Failed to initialize utilities', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Utilities initialization failed: " . $e->getMessage());
        }
    }

    private function init_settings(): void
    {
        try {
            Bema_CRM::get_logger()->debug('Initializing settings component');
            
            if (!isset($this->settings) && isset($this->logger)) {
                $this->settings = Bema_Settings::get_instance();
                $this->component_registry['settings'] = $this->settings;
                Bema_CRM::get_logger()->info('Settings initialized successfully');
            } else {
                Bema_CRM::get_logger()->debug('Settings already exists or logger not available');
            }
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Failed to initialize settings', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Settings initialization failed: " . $e->getMessage());
        }
    }

    private function init_database_manager(): void
    {
        try {
            Bema_CRM::get_logger()->debug('Initializing database manager component');
            
            if (!isset($this->db_manager) && isset($this->logger)) {
                global $wpdb;
                $this->db_manager = new Database_Manager($wpdb);
                $this->component_registry['db_manager'] = $this->db_manager;
                Bema_CRM::get_logger()->info('Database manager initialized successfully');
            } else {
                Bema_CRM::get_logger()->debug('Database manager already exists or logger not available');
            }
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Failed to initialize database manager', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Database manager initialization failed: " . $e->getMessage());
        }
    }

    private function init_database_tables(): void
    {
        try {
            Bema_CRM::get_logger()->debug('Initializing database table managers');
            
            $table_managers = [
                'transition_db_manager' => \Bema\Database\Transition_Database_Manager::class,
                'transition_subscribers_db_manager' => \Bema\Database\Transition_Subscribers_Database_Manager::class,
                'campaign_db_manager' => \Bema\Database\Campaign_Database_Manager::class,
                'group_db_manager' => \Bema\Database\Group_Database_Manager::class,
                'field_db_manager' => \Bema\Database\Field_Database_Manager::class,
                'subscriber_db_manager' => \Bema\Database\Subscribers_Database_Manager::class,
                'campaign_group_subscribers_db_manager' => \Bema\Database\Campaign_Group_Subscribers_Database_Manager::class,
                'sync_db_manager' => \Bema\Database\Sync_Database_Manager::class,
            ];

            foreach ($table_managers as $property => $class) {
                Bema_CRM::get_logger()->debug("Initializing {$property}");
                $this->$property = new $class();
                $this->component_registry[$property] = $this->$property;
            }
            
            Bema_CRM::get_logger()->info('Database table managers initialized successfully', [
                'managers_count' => count($table_managers)
            ]);
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Failed to initialize database table managers', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Database table managers initialization failed: " . $e->getMessage());
        }
    }

    private function init_edd_components(): void
    {
        try {
            Bema_CRM::get_logger()->debug('Checking EDD availability for component initialization');
            
            // Check if EDD or EDD Pro is active
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $edd_active = \is_plugin_active('easy-digital-downloads/easy-digital-downloads.php') ||
                \is_plugin_active('easy-digital-downloads-pro/easy-digital-downloads.php');

            if ($edd_active) {
                Bema_CRM::get_logger()->info('EDD detected - initializing sync components');
                if ($this->settings && $this->logger) {
                    $this->initialize_sync_components();
                    Bema_CRM::get_logger()->info('EDD-dependent components initialized successfully');
                } else {
                    Bema_CRM::get_logger()->warning('Cannot initialize EDD components - missing settings or logger');
                }
            } else {
                Bema_CRM::get_logger()->info('EDD not active - skipping sync component initialization');
            }
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Failed to initialize EDD components', [
                'error' => $e->getMessage()
            ]);
            // Don't throw here as EDD components are optional
        }
    }

    private function init_admin_components_internal(): void
    {
        try {
            Bema_CRM::get_logger()->debug('Initializing admin components (internal)');
            $this->initialize_admin_components();
            Bema_CRM::get_logger()->info('Admin components initialized successfully');
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Failed to initialize admin components', [
                'error' => $e->getMessage()
            ]);
            // Don't throw here as admin components are optional in some contexts
        }
    }

    private function initialize_sync_components(): void
    {
        try {
            Bema_CRM::get_logger()->info('Initializing sync components with detailed logging');

            // Enhanced EDD check
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $edd_base = 'easy-digital-downloads/easy-digital-downloads.php';
            $edd_pro = 'easy-digital-downloads-pro/easy-digital-downloads.php';
            $edd_active = \is_plugin_active($edd_base) || \is_plugin_active($edd_pro);

            Bema_CRM::get_logger()->debug('Sync initialization check', [
                'context' => 'SYNC_INIT_CHECK',
                'edd_active' => $edd_active ? 'yes' : 'no',
                'base_active' => \is_plugin_active($edd_base) ? 'yes' : 'no',
                'pro_active' => \is_plugin_active($edd_pro) ? 'yes' : 'no',
                'base_exists' => file_exists(WP_PLUGIN_DIR . '/' . $edd_base) ? 'yes' : 'no',
                'pro_exists' => file_exists(WP_PLUGIN_DIR . '/' . $edd_pro) ? 'yes' : 'no',
                'base_path' => WP_PLUGIN_DIR . '/' . $edd_base,
                'pro_path' => WP_PLUGIN_DIR . '/' . $edd_pro
            ]);

            if (!$edd_active) {
                Bema_CRM::get_logger()->info('EDD not active - skipping sync component initialization', ['context' => 'SYNC_INIT']);
                return;
            }

            $settings = get_option('bema_crm_settings');

            // Initialize providers with logging
            Bema_CRM::get_logger()->debug('Initializing MailerLite provider', ['context' => 'PROVIDER_INIT']);
            $mailerlite = new \Bema\Providers\MailerLite(
                $settings['api']['mailerlite_api_key'] ?? ''
            );
            Bema_CRM::get_logger()->debug('MailerLite provider initialized', ['context' => 'PROVIDER_INIT']);

            Bema_CRM::get_logger()->debug('Initializing EDD provider', ['context' => 'PROVIDER_INIT']);
            $edd = new \Bema\Providers\EDD(
                $settings['api']['edd_api_key'] ?? '',
                $settings['api']['edd_token'] ?? ''
            );
            Bema_CRM::get_logger()->debug('EDD provider initialized', ['context' => 'PROVIDER_INIT']);

            // Initialize sync instance with logging
            Bema_CRM::get_logger()->debug('Initializing EM_Sync instance', ['context' => 'SYNC_INIT']);

            // Create EM_Sync instance first
            $this->sync_instance = new \Bema\EM_Sync(
                $mailerlite,
                $edd,
                $this->settings
            );

            // Create Transition_Manager instance
            $this->transition_instance = new \Bema\Transition_Manager();
            $this->transition_instance->mailerLiteInstance = $mailerlite;
            $this->transition_instance->logger = Bema_CRM::get_logger();
            $this->transition_instance->campaign_database = $this->campaign_db_manager;
            $this->transition_instance->group_database = $this->group_db_manager;
            $this->transition_instance->transition_database = $this->transition_db_manager;
            $this->transition_instance->transition_subscribers_database = $this->transition_subscribers_db_manager;

            // Initialize handlers
            $lock_handler = new \Bema\Handlers\Default_Lock_Handler();
            $health_monitor = new \Bema\Handlers\Default_Health_Monitor();
            $stats_collector = new \Bema\Handlers\Default_Stats_Collector();

            Bema_CRM::get_logger()->debug('Sync components status', [
                'context' => 'SYNC_COMPONENTS_STATUS',
                'sync_instance_created' => isset($this->sync_instance) ? 'yes' : 'no',
            ]);
            Bema_CRM::get_logger()->info('Sync components initialized successfully');
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Sync initialization error', [
                'context' => 'SYNC_INIT_ERROR',
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString()
            ]);

            Bema_CRM::get_logger()->error('Sync initialization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function initialize_admin_components(): void
    {
        try {
            Bema_CRM::get_logger()->debug('Initializing admin components');

            if (!$this->settings || !$this->logger) {
                Bema_CRM::get_logger()->warning('Missing required settings or logger');
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
                $this->settings,                           // Required: Bema_Settings
                $has_edd ? $this->sync_instance : null,    // Optional: ?EM_Sync

            );

            $this->component_registry['admin'] = $this->admin_interface;
            Bema_CRM::get_logger()->info('Admin components initialized successfully');
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Admin initialization failed: ' . $e->getMessage());
            Bema_CRM::get_logger()->error('Failed to initialize admin components', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
        Bema_CRM::get_logger()->debug('Text domain loaded');
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

            Bema_CRM::get_logger()->debug('Dependencies check', [
                'context' => 'DEPENDENCIES_CHECK',
                'edd_base_active' => is_plugin_active($edd_base),
                'edd_pro_active' => is_plugin_active($edd_pro),
                'edd_active' => $edd_active
            ]);

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
            Bema_CRM::get_logger()->error('Plugin dependency check failed: ' . $e->getMessage(), ['context' => 'DEPENDENCIES_ERROR']);
        }
    }

    /**
     * Add hook methods
     */
    private function add_hooks(): void
    {
        Bema_CRM::get_logger()->debug('Adding hooks');

        try {
            // WordPress core hooks
            add_action('init', [$this, 'load_textdomain']);
            add_action('plugins_loaded', [$this, 'check_plugin_dependencies']);

            // Admin-specific hooks
            if (is_admin()) {
                add_action('admin_menu', [$this, 'initialize_admin_interface']);
                add_action('admin_notices', [$this, 'display_admin_notices']);
            }

            // Add notification handler
            require_once BEMA_PATH . 'includes/notification/class-bema-crm-notifier.php';

            // Add CRM Trigger
            $mailerlite = new \Bema\Providers\MailerLite(get_option('bema_crm_settings')['api']['mailerlite_api_key'] ?? '');
            $triggers = new Triggers($mailerlite, $this->sync_instance, $this->utils, $this->group_db_manager, $this->field_db_manager);
            $triggers->init();

            // Register sync cron Hook
            add_action('bema_crm_sync_cron_job', function () {
                // Perform the sync using manager factory pattern
                $sync_manager = $this->get_manager('sync');
                $sync_manager->sync_all_mailerlite_data();
            });

            // Register transition cron Hook
            add_action('bema_crm_transition_cron_job', function () {
                // Perform transitions using manager factory pattern
                $transition_manager = $this->get_manager('transition');
                $transition_manager->process_all_transitions();
            });

            Bema_CRM::get_logger()->debug('Hooks added successfully');
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Error adding hooks: ' . $e->getMessage());
            Bema_CRM::get_logger()->error('Failed to add hooks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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

                $this->admin_interface = new \Bema\Admin\Bema_Admin_Interface(
                    $this->settings,         // Required settings
                    $has_edd ? $this->sync_instance : null,    // Optional sync instance

                );

                $this->component_registry['admin'] = $this->admin_interface;
                Bema_CRM::get_logger()->debug('Admin interface initialized');
            } catch (Exception $e) {
                Bema_CRM::get_logger()->error('Failed to initialize admin interface: ' . $e->getMessage());
                if (isset($this->logger)) {
                    Bema_CRM::get_logger()->error('Admin interface initialization failed', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
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

        Bema_CRM::get_logger()->error($error_message, ['context' => 'ERROR']);

        Bema_CRM::get_logger()->error($error_message, [
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

        Bema_CRM::get_logger()->error($error_message, ['context' => 'EXCEPTION']);

        if ($this->logger) {
            Bema_CRM::get_logger()->error($error_message, [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        }
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

            Bema_CRM::get_logger()->info('Plugin cache cleared successfully', ['context' => 'CACHE_CLEAR']);
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Error clearing cache: ' . $e->getMessage(), ['context' => 'CACHE_ERROR']);
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

            Bema_CRM::get_logger()->critical($error_message, ['context' => 'FATAL']);

            if ($this->logger) {
                Bema_CRM::get_logger()->critical($error_message, [
                    'type' => $error['type'],
                    'file' => $error['file'],
                    'line' => $error['line']
                ]);
            }
        }
    }

    private function handle_initialization_error(Exception $e): void
    {
        $error_message = sprintf(
            'Plugin initialization failed: %s',
            $e->getMessage()
        );

        Bema_CRM::get_logger()->error($error_message, ['context' => 'INIT_ERROR']);

        if (isset($this->logger)) {
            Bema_CRM::get_logger()->critical($error_message, [
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
            Bema_CRM::get_logger()->info('Starting plugin activation');

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
            require_once BEMA_PATH . 'includes/class-bema-crm-logger.php';
            require_once BEMA_PATH . 'includes/class-database-migrations.php';

            $logger = Bema_CRM_Logger::create('plugin-activation');
            $migrations = new Database_Migrations();

            if (!$migrations->install()) {
                throw new Exception('Database installation failed');
            }

            // Add this line to handle upgrades
            $migrations->maybe_upgrade();

            // Initialize database optimizer
            global $wpdb;
            $db_manager = new Database_Manager($wpdb);
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

            

            // Create campaign table
            (new \Bema\Database\Campaign_Database_Manager())->create_table();
            // Create group Table
            (new \Bema\Database\Group_Database_Manager())->create_table();
            // Create field Table
            (new \Bema\Database\Field_Database_Manager())->create_table();
            // Create subscriber Table
            (new \Bema\Database\Subscribers_Database_Manager())->create_table();
            // Create campaign subscriber Table
            (new \Bema\Database\Campaign_Group_Subscribers_Database_Manager())->create_table();
            // Create sync Table
            (new \Bema\Database\Sync_Database_Manager())->create_table();
            // Create transition table
            (new \Bema\Database\Transition_Database_Manager())->create_table();
            // Create transition subscribers table
            (new \Bema\Database\Transition_Subscribers_Database_Manager())->create_table();

            // Clear rewrite rules
            flush_rewrite_rules();

            Bema_CRM::get_logger()->info('Plugin activation completed successfully');
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Activation failed: ' . $e->getMessage());
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
            Bema_CRM::get_logger()->info('Starting plugin deactivation');

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
                self::OPTION_TIERS,
                self::OPTION_TRANSITION_MATRIX
            ];

            foreach ($transients_to_delete as $transient) {
                delete_transient($transient);
            }

            foreach ($options_to_delete as $option) {
                delete_option($option);
            }

            // Clear sync status
            update_option('bema_sync_running', false);

            // Delete transition subscribers table
            (new \Bema\Database\Transition_Subscribers_Database_Manager())->delete_table();
            // Delete transition table
            (new \Bema\Database\Transition_Database_Manager())->delete_table();
            // Delete sync Table
            (new \Bema\Database\Sync_Database_Manager())->delete_table();
            // Delete campaign subscriber Table
            (new \Bema\Database\Campaign_Group_Subscribers_Database_Manager())->delete_table();
            // Delete subscriber Table
            (new \Bema\Database\Subscribers_Database_Manager())->delete_table();
            // Delete field Table
            (new \Bema\Database\Field_Database_Manager())->delete_table();
            // Delete group Table
            (new \Bema\Database\Group_Database_Manager())->delete_table();
            // Delete campaign table
            (new \Bema\Database\Campaign_Database_Manager())->delete_table();

            // Clear rewrite rules
            flush_rewrite_rules();

            Bema_CRM::get_logger()->info('Plugin deactivation completed successfully');
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Deactivation error: ' . $e->getMessage());
            error_log('Bema CRM deactivation error: ' . $e->getMessage());
        }
    }

    public static function uninstall(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        try {
            Bema_CRM::get_logger()->info('Starting plugin uninstallation');

            $instance = self::get_instance();
            if (!$instance) {
                throw new Exception('Failed to initialize plugin instance during uninstall');
            }

            // Clean up database tables
            $migrations = new Database_Migrations();
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

            Bema_CRM::get_logger()->info('Plugin uninstallation completed successfully');
        } catch (Exception $e) {
            Bema_CRM::get_logger()->error('Uninstallation error: ' . $e->getMessage());
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
        Bema_CRM::get_logger()->debug('Default settings initialized');
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
            'Silver Purchase',
            'Gold Purchase',
        );

        add_option('bema_crm_tiers', $default_tiers);
        Bema_CRM::get_logger()->debug('Default tier settings initialized');
    }

    private static function initialize_transition_settings(): void
    {
        $default_transition_matrix = [
            [
                'current_tier' => 'Gold Purchase',
                'next_tier' => 'Gold',
                'requires_purchase' => true
            ],
            [
                'current_tier' => 'Silver Purchase',
                'next_tier' => 'Silver',
                'requires_purchase' => true
            ],
            [
                'current_tier' => 'Bronze Purchase',
                'next_tier' => 'Opt-in',
                'requires_purchase' => true
            ]
        ];

        add_option('bema_crm_transition_matrix', $default_transition_matrix);
        Bema_CRM::get_logger()->debug('Default transition matrix settings initialized');
    }

    /**
     * Manager factory pattern to get manager instances
     */
    private function get_manager(string $type)
    {
        switch ($type) {
            case 'sync':
                return $this->sync_instance;
            case 'transition':
                return $this->transition_instance;
            default:
                throw new Exception("Unknown manager type: {$type}");
        }
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
                Bema_CRM::get_logger()->debug("Created protected directory: {$dir}");
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
            Bema_CRM::get_logger()->debug("Removed directory: {$dir}");
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

    private function __clone()
    {
    }

    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
}

// Initialize the plugin
add_action('plugins_loaded', function () {
    Bema_CRM::get_logger()->info('Initializing plugin through plugins_loaded hook');
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

        Bema_CRM::get_logger()->info('Plugin instance created successfully');
    } catch (Exception $e) {
        Bema_CRM::get_logger()->error('Error initializing plugin: ' . $e->getMessage());
        error_log('Bema CRM initialization error: ' . $e->getMessage());
    }
}, 5);

// Register activation and deactivation hooks
register_activation_hook(__FILE__, function () {
    Bema_CRM::get_logger()->info('Activation hook triggered');
    try {
        Bema_CRM::activate();
    } catch (Exception $e) {
        Bema_CRM::get_logger()->error('Activation error: ' . $e->getMessage());
        wp_die(
            esc_html($e->getMessage()),
            'Plugin Activation Error',
            ['back_link' => true]
        );
    }
});

register_deactivation_hook(__FILE__, function () {
    Bema_CRM::get_logger()->info('Deactivation hook triggered');
    try {
        Bema_CRM::deactivate();
    } catch (Exception $e) {
        Bema_CRM::get_logger()->error('Deactivation error: ' . $e->getMessage());
        error_log('Bema CRM deactivation error: ' . $e->getMessage());
    }
});

register_uninstall_hook(__FILE__, ['\Bema\Bema_CRM', 'uninstall']);

