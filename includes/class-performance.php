<?php

namespace Bema;

if (!defined('ABSPATH')) {
    exit;
}

class Performance
{
    private static $instance = null;
    private $cache = [];
    private $start_time;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init()
    {
        $this->start_time = microtime(true);

        // Core optimizations
        if (is_admin()) {
            add_filter('pre_http_request', [$this, 'block_external_requests'], 10, 3);
            add_action('admin_init', [$this, 'optimize_admin_load']);
            add_action('admin_footer', [$this, 'log_page_performance']);

            // Add sync-specific optimizations
            add_action('bema_before_sync', [$this, 'prepare_sync']);
            add_action('bema_after_sync', [$this, 'cleanup_sync']);

            // Database optimization
            add_action('init', [$this, 'optimize_queries'], 1);
        }
    }

    public function optimize_queries()
    {
        global $wpdb;
        $wpdb->query('SET SESSION sql_mode=""');
        $wpdb->query('SET SESSION transaction_isolation="READ-COMMITTED"');

        // Cache commonly used queries
        wp_cache_add_global_groups(['bema_sync', 'bema_stats']);
    }

    public function prepare_sync()
    {
        // Increase limits for sync operations
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        // Disable unnecessary WordPress features during sync
        wp_defer_term_counting(true);
        wp_defer_comment_counting(true);

        // Optimize database for writes
        global $wpdb;
        $wpdb->query('SET autocommit=0');
    }

    public function cleanup_sync()
    {
        // Restore WordPress features
        wp_defer_term_counting(false);
        wp_defer_comment_counting(false);

        // Reset database settings
        global $wpdb;
        $wpdb->query('SET autocommit=1');

        // Clear any transients or cache
        $this->clear_sync_cache();
    }

    public function block_external_requests($pre, $parsed_args, $url)
    {
        // Allow API requests to MailerLite and your own domain
        if (strpos($url, 'mailerlite.com') !== false || strpos($url, site_url()) !== false) {
            return false;
        }

        // Block non-essential external requests during page loads
        $blocked_domains = ['wordpress.org', 'api.wordpress.org'];
        foreach ($blocked_domains as $domain) {
            if (strpos($url, $domain) !== false) {
                return new \WP_Error('http_request_blocked', 'External request blocked during page load');
            }
        }

        return false;
    }

    public function optimize_admin_load()
    {
        // Remove unnecessary scripts
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');

        // Optimize heartbeat API
        $this->optimize_heartbeat();

        // Add page-specific optimizations
        $this->optimize_current_page();
    }

    private function optimize_heartbeat()
    {
        global $pagenow;

        // Disable heartbeat on non-edit pages
        if (!in_array($pagenow, ['post.php', 'post-new.php'])) {
            wp_deregister_script('heartbeat');
            return;
        }

        // Adjust heartbeat frequency on edit pages
        add_filter('heartbeat_settings', function ($settings) {
            $settings['interval'] = 60; // Once per minute
            return $settings;
        });
    }

    private function optimize_current_page()
    {
        $screen = get_current_screen();

        if ($screen && strpos($screen->id, 'bema') !== false) {
            // Optimize Bema CRM pages
            add_filter('admin_body_class', function ($classes) {
                return $classes . ' bema-optimized';
            });

            // Add page-specific script optimizations
            add_action('admin_enqueue_scripts', [$this, 'optimize_scripts'], 999);
        }
    }

    private function clear_sync_cache()
    {
        wp_cache_delete('bema_current_sync', 'bema_sync');
        wp_cache_delete('bema_sync_stats', 'bema_stats');
        delete_transient('bema_sync_running');
    }

    public function optimize_scripts()
    {
        global $wp_scripts;

        // Define critical scripts
        $critical_scripts = ['jquery', 'bema-admin', 'bema-sync'];

        // Defer non-critical scripts
        foreach ($wp_scripts->registered as $handle => $script) {
            if (!in_array($handle, $critical_scripts)) {
                $script->extra['defer'] = true;
            }
        }
    }

    public function log_page_performance()
    {
        $load_time = microtime(true) - $this->start_time;
        $memory_usage = memory_get_peak_usage(true);

        error_log(sprintf(
            'Bema CRM Page Load: %.2f seconds, Memory: %.2f MB',
            $load_time,
            $memory_usage / 1024 / 1024
        ));
    }
}
