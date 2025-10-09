<?php
/**
 * Main Bema CRM Class
 *
 * @package Bema_CRM
 * @since 1.0.0
 */

namespace Bema;

use Bema\Bema_Settings;
use Bema\EM_Sync;
use Bema\Admin\Bema_Admin_Interface;
use Bema\Bema_CRM_Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Bema_CRM
{
    /**
     * Instance of this class.
     *
     * @since 1.0.0
     * @var Bema_CRM
     */
    protected static $instance = null;

    /**
     * Logger instance
     *
     * @since 1.0.0
     * @var Bema_CRM_Logger
     */
    protected $logger = null;

    /**
     * Settings instance
     *
     * @since 1.0.0
     * @var Bema_Settings
     */
    protected $settings = null;

    /**
     * Admin interface instance
     *
     * @since 1.0.0
     * @var Bema_Admin_Interface
     */
    protected $admin_interface = null;

    /**
     * EM Sync instance
     *
     * @since 1.0.0
     * @var EM_Sync
     */
    protected $em_sync = null;

    /**
     * Initialize the plugin.
     *
     * @since 1.0.0
     */
    private function __construct()
    {
        // Initialize logger
        $this->logger = Bema_CRM_Logger::create('bema-crm');
        
        try {
            $this->logger->info('Bema CRM initialization started');
            
            // Initialize settings
            $this->settings = Bema_Settings::get_instance($this->logger);
            
            // Initialize EM Sync if dependencies are available
            if (class_exists('Bema\Providers\EDD') && class_exists('Bema\Providers\MailerLite')) {
                // Get API credentials from settings
                $settings = $this->settings->get_settings();
                $edd_api_key = $settings['api']['edd_api_key'] ?? '';
                $edd_token = $settings['api']['edd_token'] ?? '';
                $mailerlite_api_key = $settings['api']['mailerlite_api_key'] ?? '';
                
                $mailerLite = new \Bema\Providers\MailerLite($mailerlite_api_key, $this->logger);
                $edd = new \Bema\Providers\EDD($edd_api_key, $edd_token, $this->logger);
                $this->em_sync = new EM_Sync($mailerLite, $edd, $this->settings, $this->logger);
            }
            
            // Hook into WordPress
            \add_action('init', [$this, 'init'], 10);
            \add_action('admin_init', [$this, 'admin_init'], 10);
            
            $this->logger->info('Bema CRM initialization completed');
        } catch (\Exception $e) {
            $this->logger->error('Bema CRM initialization failed: ' . $e->getMessage());
            \error_log('Bema CRM initialization error: ' . $e->getMessage());
        }
    }

    /**
     * Return an instance of this class.
     *
     * @since 1.0.0
     * @return Bema_CRM A single instance of this class.
     */
    public static function get_instance()
    {
        // If the single instance hasn't been set, set it now.
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize the plugin on WordPress init hook.
     *
     * @since 1.0.0
     */
    public function init()
    {
        try {
            // Register settings
            $this->settings->register_settings();
            
            // Initialize admin interface
            if (\is_admin()) {
                $this->init_admin();
            }
            
            $this->logger->info('Bema CRM initialized on WordPress init hook');
        } catch (\Exception $e) {
            $this->logger->error('Bema CRM init hook failed: ' . $e->getMessage());
        }
    }

    /**
     * Initialize admin components.
     *
     * @since 1.0.0
     */
    public function init_admin()
    {
        try {
            // Initialize admin interface
            $this->admin_interface = new Bema_Admin_Interface($this->settings, $this->em_sync);
            
            $this->logger->info('Bema CRM admin interface initialized');
        } catch (\Exception $e) {
            $this->logger->error('Bema CRM admin initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * Get the admin interface instance.
     *
     * @since 1.0.0
     * @return Bema_Admin_Interface|null
     */
    public function get_admin_interface()
    {
        return $this->admin_interface;
    }

    /**
     * Get the settings instance.
     *
     * @since 1.0.0
     * @return Bema_Settings|null
     */
    public function get_settings()
    {
        return $this->settings;
    }

    /**
     * Get the EM Sync instance.
     *
     * @since 1.0.0
     * @return EM_Sync|null
     */
    public function get_em_sync()
    {
        return $this->em_sync;
    }

    /**
     * Get the logger instance.
     *
     * @since 1.0.0
     * @return Bema_CRM_Logger|null
     */
    public function get_logger()
    {
        return $this->logger;
    }

    /**
     * Plugin activation hook.
     *
     * @since 1.0.0
     */
    public static function activate()
    {
        try {
            // Initialize logger for activation
            $logger = Bema_CRM_Logger::create('activation');
            $logger->info('Bema CRM activation started');
            
            // Initialize settings
            $settings = Bema_Settings::get_instance($logger);
            $settings->register_settings();
            
            // Flush rewrite rules
            \flush_rewrite_rules();
            
            $logger->info('Bema CRM activation completed successfully');
        } catch (\Exception $e) {
            \error_log('Bema CRM activation error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Plugin deactivation hook.
     *
     * @since 1.0.0
     */
    public static function deactivate()
    {
        try {
            // Initialize logger for deactivation
            $logger = Bema_CRM_Logger::create('deactivation');
            $logger->info('Bema CRM deactivation started');
            
            // Clear any scheduled cron jobs
            \wp_clear_scheduled_hook('bema_crm_sync_cron_job');
            
            // Flush rewrite rules
            \flush_rewrite_rules();
            
            $logger->info('Bema CRM deactivation completed successfully');
        } catch (\Exception $e) {
            \error_log('Bema CRM deactivation error: ' . $e->getMessage());
        }
    }

    /**
     * Plugin uninstall hook.
     *
     * @since 1.0.0
     */
    public static function uninstall()
    {
        try {
            // Initialize logger for uninstall
            $logger = Bema_CRM_Logger::create('uninstall');
            $logger->info('Bema CRM uninstall started');
            
            // Remove all options
            \delete_option('bema_crm_settings');
            \delete_option('bema_crm_tiers');
            \delete_option('bema_crm_transition_matrix');
            
            // Remove any transients
            \delete_transient('bema_api_test_results');
            
            // Clear any scheduled cron jobs
            \wp_clear_scheduled_hook('bema_crm_sync_cron_job');
            
            $logger->info('Bema CRM uninstall completed successfully');
        } catch (\Exception $e) {
            \error_log('Bema CRM uninstall error: ' . $e->getMessage());
        }
    }
}