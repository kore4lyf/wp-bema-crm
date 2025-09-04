<?php
namespace Bema;
/**
 * Bema_CRM_Logger class.
 *
 * A simple, file-based logger for WordPress. This class handles logging messages
 * to a dedicated file within the WordPress uploads directory, with features
 * for log rotation, cleanup, and security.
 *
 * @since 1.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}

class Bema_CRM_Logger
{
    /**
     * Log level constants, following the PSR-3 standard.
     * @var string
     */
    const EMERGENCY = 'emergency';
    const ALERT = 'alert';
    const CRITICAL = 'critical';
    const ERROR = 'error';
    const WARNING = 'warning';
    const NOTICE = 'notice';
    const INFO = 'info';
    const DEBUG = 'debug';

    /**
     * A unique identifier for the log file and directory.
     * @var string
     */
    private $identifier;

    /**
     * The full path to the log directory.
     * @var string
     */
    private $log_dir;

    /**
     * The full path to the primary log file.
     * @var string
     */
    private $log_file;

    /**
     * Logger configuration settings.
     * @var array
     */
    private $config;

    /**
     * Default configuration options for the logger.
     * @var array
     */
    private $default_config = [
        'max_file_size_mb' => 5,
        'max_file_age_days' => 90,
    ];

    /**
     * Bema_CRM_Logger constructor.
     *
     * @param string $identifier A unique string to identify this logger instance.
     * @param array  $config     Optional array of configuration settings.
     */
    public function __construct($identifier, $config = [])
    {
        // Sanitize the identifier to ensure it's a valid filename.
        $this->identifier = function_exists('sanitize_title')
            ? sanitize_title($identifier)
            : preg_replace('/[^a-z0-9_\-]/i', '', strtolower($identifier));

        // Merge user-provided config with defaults.
        $this->config = array_merge($this->default_config, $config);

        // Define log directory and file paths.
        $this->log_dir = WP_CONTENT_DIR . '/uploads/bema-crm/' . $this->identifier;
        $this->log_file = $this->log_dir . '/' . $this->identifier . '.log';

        // Ensure the log directory exists and is secure.
        $this->ensure_log_directory();

        // Schedule log cleanup to run periodically.
        add_action('wp_loaded', [$this, 'schedule_log_cleanup']);
    }

    /**
     * Ensures the log directory exists and creates a .htaccess file to protect it.
     *
     * @return bool True if the directory exists or was created successfully, false otherwise.
     */
    private function ensure_log_directory()
    {
        if (!wp_mkdir_p($this->log_dir)) {
            return false;
        }

        $htaccess_path = $this->log_dir . '/.htaccess';
        // Create a .htaccess file to deny direct access to log files.
        if (!file_exists($htaccess_path)) {
            file_put_contents($htaccess_path, "deny from all\n");
        }

        return true;
    }

    /**
     * Writes a log message to the log file.
     *
     * @param string $level   The log level (e.g., 'error', 'info').
     * @param string $message The message to log.
     * @param array  $context An optional array of additional data.
     * @return void
     */
    private function log($level, $message, array $context = [])
    {
        // Rotate the log file if it exceeds the maximum size.
        $this->rotate_file_if_needed();

        // Format the log entry string.
        $formatted_message = $this->format_message($level, $message, $context);

        // Create the file if it doesn't exist.
        if (!file_exists($this->log_file)) {
            touch($this->log_file);
        }

        // Append the message to the log file.
        error_log($formatted_message, 3, $this->log_file);
    }

    /**
     * Formats the log message into a consistent string.
     *
     * @param string $level   The log level.
     * @param string $message The log message.
     * @param array  $context The context data.
     * @return string The formatted log entry.
     */
    private function format_message($level, $message, $context)
    {
        // Get the current time in WordPress's configured timezone.
        $timestamp = current_time('mysql');
        // Encode context array to JSON for logging.
        $context_str = empty($context) ? '' : ' ' . wp_json_encode($context);

        return sprintf(
            "[%s] [%s] %s: %s%s\n",
            $timestamp,
            strtoupper($level),
            $this->identifier,
            $message,
            $context_str
        );
    }

    /**
     * Logs an emergency message.
     * @param string $message The message to log.
     * @param array  $context Optional context data.
     */
    public function emergency($message, array $context = [])
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    /**
     * Logs an alert message.
     * @param string $message The message to log.
     * @param array  $context Optional context data.
     */
    public function alert($message, array $context = [])
    {
        $this->log(self::ALERT, $message, $context);
    }

    /**
     * Logs a critical message.
     * @param string $message The message to log.
     * @param array  $context Optional context data.
     */
    public function critical($message, array $context = [])
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    /**
     * Logs an error message.
     * @param string $message The message to log.
     * @param array  $context Optional context data.
     */
    public function error($message, array $context = [])
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * Logs a warning message.
     * @param string $message The message to log.
     * @param array  $context Optional context data.
     */
    public function warning($message, array $context = [])
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * Logs a notice message.
     * @param string $message The message to log.
     * @param array  $context Optional context data.
     */
    public function notice($message, array $context = [])
    {
        $this->log(self::NOTICE, $message, $context);
    }

    /**
     * Logs an informational message.
     * @param string $message The message to log.
     * @param array  $context Optional context data.
     */
    public function info($message, array $context = [])
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * Logs a debug message.
     * @param string $message The message to log.
     * @param array  $context Optional context data.
     */
    public function debug($message, array $context = [])
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * Schedules a log cleanup routine to run once an hour.
     * This prevents the cleanup from running on every page load.
     * @return void
     */
    public function schedule_log_cleanup()
    {
        $transient_key = $this->identifier . '_log_cleanup_transient';

        // Use a transient to ensure the cleanup function runs at most once per hour.
        if (!get_transient($transient_key)) {
            $this->cleanup_old_log_files();
            set_transient($transient_key, true, HOUR_IN_SECONDS);
        }
    }

    /**
     * Rotates the current log file if it exceeds the configured maximum size.
     * The current log file is renamed with a timestamp.
     * @return void
     */
    private function rotate_file_if_needed()
    {
        if (!file_exists($this->log_file)) {
            return;
        }

        // Calculate the file size in megabytes.
        $file_size_mb = filesize($this->log_file) / 1024 / 1024;

        if ($file_size_mb > $this->config['max_file_size_mb']) {
            $backup_file = $this->log_dir . '/' . $this->identifier . '_' . date('Y-m-d_H-i-s') . '.log';
            // Rename the current log file to a backup file.
            rename($this->log_file, $backup_file);
        }
    }

    /**
     * Deletes log files that are older than the configured maximum age.
     * @return void
     */
    private function cleanup_old_log_files()
    {
        if (!is_dir($this->log_dir)) {
            return;
        }

        // Find all log files in the directory.
        $files = glob($this->log_dir . '/*.log');
        // Calculate the timestamp for the cutoff date.
        $cutoff_time = strtotime('-' . $this->config['max_file_age_days'] . ' days');

        foreach ($files as $file) {
            // Delete the file if it's a file and its modification time is older than the cutoff.
            if (is_file($file) && filemtime($file) < $cutoff_time) {
                unlink($file);
            }
        }
    }

    /**
     * Retrieves the contents of the main log file.
     *
     * @return string|false The log file content or false if the file doesn't exist.
     */
    public function get_logs()
    {
        return file_exists($this->log_file) ? file_get_contents($this->log_file) : false;
    }

    /**
     * Clears the main log file by deleting and re-creating it.
     *
     * @return bool True on success, false on failure.
     */
    public function clear_logs()
    {
        if (file_exists($this->log_file)) {
            // Attempt to delete the log file.
            if (!unlink($this->log_file)) {
                return false;
            }
        }
        // Re-create the log file.
        return touch($this->log_file);
    }

    /**
     * Deletes the log directory and all associated files (including .htaccess).
     *
     * @return bool True if successfully deleted, false otherwise.
     */
    public function delete_logs()
    {
        if (!is_dir($this->log_dir)) {
            return false;
        }

        $success = true;

        // Match all files, including hidden ones (.htaccess, .gitignore, etc.)
        $files = array_merge(
            glob($this->log_dir . '/*', GLOB_NOSORT) ?: [],
            glob($this->log_dir . '/.*', GLOB_NOSORT) ?: [] // catches .htaccess
        );

        foreach ($files as $file) {
            if (is_file($file)) {
                if (!@unlink($file)) {
                    $success = false;
                }
            }
        }

        // Try to remove the directory itself
        if (!@rmdir($this->log_dir)) {
            $success = false;
        }

        return $success;
    }

}