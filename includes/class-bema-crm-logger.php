<?php
namespace Bema;
/**
 * Bema_CRM_Logger class.
 *
 * A simple, file-based logger for WordPress with environment-specific logging.
 * Handles logging messages to a dedicated file within the WordPress uploads directory,
 * with features for log rotation, cleanup, and security.
 *
 * @since 1.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}

class Bema_CRM_Logger
{
    // ========================================
    // CONSTANTS
    // ========================================
    
    /**
     * Log level constants, following the PSR-3 standard.
     */
    const EMERGENCY = 'emergency';
    const ALERT = 'alert';
    const CRITICAL = 'critical';
    const ERROR = 'error';
    const WARNING = 'warning';
    const NOTICE = 'notice';
    const INFO = 'info';
    const DEBUG = 'debug';

    // ========================================
    // STATIC PROPERTIES & FACTORY
    // ========================================
    
    /**
     * Default logger configuration
     */
    private static $default_config = [
        'max_file_size_mb' => 10,
        'max_file_age_days' => 30,
        'log_level' => 'warning'
    ];

    /**
     * Create a standardized logger instance
     * 
     * @param string $identifier Logger identifier
     * @param array $config Optional configuration overrides
     * @return self
     */
    public static function create(string $identifier, array $config = []): self
    {
        $merged_config = array_merge(self::$default_config, $config);
        
        // Set log level based on environment
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $merged_config['log_level'] = self::DEBUG;
        }
        
        return new self($identifier, self::validate_config($merged_config));
    }

    /**
     * Validate logger configuration
     * 
     * @param array $config
     * @return array Validated configuration
     */
    private static function validate_config(array $config): array
    {
        $validated = [];
        
        // Validate max file size
        if (isset($config['max_file_size_mb'])) {
            $validated['max_file_size_mb'] = max(1, min(100, (int)$config['max_file_size_mb']));
        }
        
        // Validate max file age
        if (isset($config['max_file_age_days'])) {
            $validated['max_file_age_days'] = max(1, min(365, (int)$config['max_file_age_days']));
        }
        
        // Validate log level
        if (isset($config['log_level'])) {
            $valid_levels = [
                self::EMERGENCY, self::ALERT, self::CRITICAL, self::ERROR,
                self::WARNING, self::NOTICE, self::INFO, self::DEBUG
            ];
            
            if (in_array($config['log_level'], $valid_levels)) {
                $validated['log_level'] = $config['log_level'];
            }
        }
        
        return array_merge(self::$default_config, $validated);
    }

    // ========================================
    // INSTANCE PROPERTIES
    // ========================================
    
    /**
     * A unique identifier for the log file and directory.
     */
    private $identifier;

    /**
     * The full path to the log directory.
     */
    private $log_dir;

    /**
     * The full path to the primary log file.
     */
    private $log_file;

    /**
     * Logger configuration settings.
     */
    private $config;

    /**
     * Current correlation ID for tracking related log entries.
     */
    private $correlation_id;

    /**
     * Performance timer start times.
     */
    private $timers = [];

    // ========================================
    // CONSTRUCTOR & INITIALIZATION
    // ========================================
    
    /**
     * Bema_CRM_Logger constructor.
     *
     * @param string $identifier A unique identifier for the log file.
     * @param array  $config     Optional array of configuration settings.
     */
    public function __construct($identifier, $config = [])
    {
        // Sanitize the identifier to ensure it's a valid filename.
        $this->identifier = function_exists('sanitize_title')
            ? sanitize_title($identifier)
            : preg_replace('/[^a-z0-9_\-]/i', '', strtolower($identifier));

        // Use provided config directly (create() method handles validation and defaults)
        $this->config = !empty($config) ? $config : self::$default_config;

        // Define log directory and file paths.
        $this->log_dir = WP_CONTENT_DIR . '/uploads/bema-crm-logger/' . $this->identifier;
        $this->log_file = $this->log_dir . '/' . $this->identifier . '.log';

        // Generate correlation ID for this logger instance
        $this->correlation_id = uniqid('Bema_CRM_', true);

        // Ensure the log directory exists and is secure.
        $this->ensure_log_directory();

        // Schedule log cleanup to run periodically.
        add_action('wp_loaded', [$this, 'schedule_log_cleanup']);
        
        // Register the cron action hook immediately (not in wp_loaded)
        add_action('bema_crm_log_cleanup_' . $this->identifier, [$this, 'cleanup_old_logs']);
    }

    // ========================================
    // PSR-3 LOGGING METHODS
    // ========================================
    
    public function emergency($message, array $context = [])
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = [])
    {
        $this->log(self::ALERT, $message, $context);
    }

    public function critical($message, array $context = [])
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    public function error($message, array $context = [])
    {
        $this->log(self::ERROR, $message, $context);
    }

    public function warning($message, array $context = [])
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function notice($message, array $context = [])
    {
        $this->log(self::NOTICE, $message, $context);
    }

    public function info($message, array $context = [])
    {
        $this->log(self::INFO, $message, $context);
    }

    public function debug($message, array $context = [])
    {
        $this->log(self::DEBUG, $message, $context);
    }

    // ========================================
    // CORE LOGGING LOGIC
    // ========================================
    
    /**
     * Main logging method with built-in correlation support
     */
    public function log($level, $message, array $context = [])
    {
        // Check if this log level should be recorded based on configuration.
        if (!$this->should_log($level)) {
            return;
        }

        // Add correlation ID if set
        if ($this->correlation_id) {
            $context['correlation_id'] = $this->correlation_id;
        }

        // Format the log message.
        $formatted_message = $this->format_message($level, $message, $context);

        // Write the message to the log file.
        $this->write_to_file($formatted_message);

        // Perform log rotation if the file is too large.
        $this->rotate_logs_if_needed();
    }

    // ========================================
    // CORRELATION & PERFORMANCE TRACKING
    // ========================================
    
    /**
     * Set correlation ID for tracking related log entries
     */
    public function setCorrelationId(string $correlation_id): void
    {
        $this->correlation_id = $correlation_id;
    }

    /**
     * Generate and set a new correlation ID
     */
    public function generateCorrelationId(): string
    {
        $this->correlation_id = uniqid('crm_', true);
        return $this->correlation_id;
    }

    /**
     * Get current correlation ID
     */
    public function getCorrelationId(): ?string
    {
        return $this->correlation_id;
    }

    /**
     * Get current logger identifier
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * Update logger identifier and file paths
     */
    public function setIdentifier(string $identifier): void
    {
        $this->identifier = function_exists('sanitize_title')
            ? sanitize_title($identifier)
            : preg_replace('/[^a-z0-9_\-]/i', '', strtolower($identifier));

        $this->log_dir = WP_CONTENT_DIR . '/uploads/bema-crm/' . $this->identifier;
        $this->log_file = $this->log_dir . '/' . $this->identifier . '.log';
        
        $this->ensure_log_directory();
    }

    /**
     * Start a performance timer
     */
    public function startTimer(string $name): void
    {
        $this->timers[$name] = microtime(true);
    }

    /**
     * End a performance timer and log the duration
     */
    public function endTimer(string $name, string $message = '', array $context = []): void
    {
        if (!isset($this->timers[$name])) {
            $this->warning("Timer '{$name}' was not started");
            return;
        }

        $duration = microtime(true) - $this->timers[$name];
        unset($this->timers[$name]);

        $context['duration_ms'] = round($duration * 1000, 2);
        $context['timer_name'] = $name;

        $logMessage = $message ?: "Timer '{$name}' completed";
        $this->info($logMessage, $context);
    }

    public function logDataValidation(string $dataType, array $missingFields, array $context = []): void
    {
        if (empty($missingFields)) {
            return;
        }

        $this->warning("Missing required data for {$dataType}", array_merge($context, [
            'missing_fields' => $missingFields,
            'validation_type' => 'data_integrity'
        ]));
    }

    public function logApiCall(string $service, string $endpoint, array $context = []): void
    {
        $this->debug("API call to {$service}: {$endpoint}", array_merge($context, [
            'api_service' => $service,
            'api_endpoint' => $endpoint
        ]));
    }

    // ========================================
    // FILE MANAGEMENT
    // ========================================
    
    /**
     * Get log file contents
     */
    public function get_logs(): string
    {
        if (!file_exists($this->log_file)) {
            return '';
        }
        return file_get_contents($this->log_file);
    }

    /**
     * Clear all log files
     */
    public function clear_logs(): bool
    {
        $files = glob($this->log_dir . '/*.log*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        return true;
    }

    /**
     * Delete all log files and directory
     */
    public function delete_logs(): bool
    {
        $this->clear_logs();
        if (is_dir($this->log_dir)) {
            rmdir($this->log_dir);
        }
        return true;
    }

    // ========================================
    // PRIVATE HELPER METHODS
    // ========================================
    
    /**
     * Check if a log level should be recorded
     */
    private function should_log($level): bool
    {
        $levels = [
            self::DEBUG => 0,
            self::INFO => 1,
            self::NOTICE => 2,
            self::WARNING => 3,
            self::ERROR => 4,
            self::CRITICAL => 5,
            self::ALERT => 6,
            self::EMERGENCY => 7,
        ];

        $current_level = $levels[$this->config['log_level']] ?? 3;
        $message_level = $levels[$level] ?? 0;

        return $message_level >= $current_level;
    }

    /**
     * Format a log message
     */
    private function format_message($level, $message, array $context = []): string
    {
        $timestamp = current_time('Y-m-d H:i:s');
        $level_upper = strtoupper($level);
        
        $message_str = is_array($message) ? json_encode($message) : (string)$message;
        $formatted = "[{$timestamp}] {$level_upper}: {$message_str}";
        
        if (!empty($context)) {
            $formatted .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        
        return $formatted . PHP_EOL;
    }

    /**
     * Write message to log file
     */
    private function write_to_file($message): void
    {
        file_put_contents($this->log_file, $message, FILE_APPEND | LOCK_EX);
    }

    /**
     * Ensure log directory exists and is secure
     */
    private function ensure_log_directory(): void
    {
        if (!is_dir($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }

        $htaccess_file = $this->log_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Deny from all\n");
        }
    }

    /**
     * Rotate logs if file is too large
     */
    private function rotate_logs_if_needed(): void
    {
        if (!file_exists($this->log_file)) {
            return;
        }

        $max_size = $this->config['max_file_size_mb'] * 1024 * 1024;
        if (filesize($this->log_file) > $max_size) {
            $backup_file = $this->log_file . '.' . date('Y-m-d-H-i-s');
            rename($this->log_file, $backup_file);
        }
    }

    /**
     * Schedule log cleanup
     */
    public function schedule_log_cleanup(): void
    {
        if (!wp_next_scheduled('bema_crm_log_cleanup_' . $this->identifier)) {
            wp_schedule_event(time(), 'daily', 'bema_crm_log_cleanup_' . $this->identifier);
        }
        
        // Action hook is now registered in constructor, not here
    }

    /**
     * Clean up old log files
     */
    public function cleanup_old_logs(): void
    {
        $max_age = $this->config['max_file_age_days'] * 24 * 60 * 60;
        $files = glob($this->log_dir . '/*.log.*');
        
        foreach ($files as $file) {
            if (is_file($file) && (time() - filemtime($file)) > $max_age) {
                unlink($file);
            }
        }
    }
}
