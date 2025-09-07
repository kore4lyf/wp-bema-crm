<?php

namespace Bema;

use RuntimeException;
use Exception;

/**
 * BemaCRM Logger Class
 * 
 * Handles all logging operations for the BemaCRM plugin
 * 
 * @package BemaCRM
 * @subpackage Includes
 */


if (!defined('ABSPATH')) {
    exit;
}

class BemaCRMLogger
{
    private $log_path;
    private $log_levels = ['info', 'error', 'warning', 'debug', 'critical'];
    private $max_log_size = 10 * 1024 * 1024; // 10MB
    private $max_log_files = 50;
    private $notification_email;
    private $error_threshold = 10; // Number of errors before notification
    private $error_count = [];
    private $log_rotation_interval = 'daily'; // daily, weekly, monthly
    private $enable_stack_traces = true;

    // Constants
    const ERROR_NOTIFICATION_INTERVAL = 3600; // 1 hour
    const MAX_CONTEXT_DEPTH = 3;
    const LOG_FILE_PERMISSIONS = 0640;
    const LOG_DIR_PERMISSIONS = 0750;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Get WordPress content directory instead of using BEMA_PATH
        $this->log_path = trailingslashit(WP_CONTENT_DIR) . 'bema-logs/';
        $this->notification_email = get_option('bema_admin_email');

        try {
            $this->initializeLogDirectory();
            $this->cleanOldLogs();

            // Initialize error count tracking
            if (!wp_cache_get('bema_error_count')) {
                wp_cache_set('bema_error_count', [], '', 3600);
            }
        } catch (Exception $e) {
            error_log('BemaCRMLogger initialization error: ' . $e->getMessage());
        }
    }

    /**
     * Initialize log directory with proper permissions
     */
    private function initializeLogDirectory(): void
    {
        if (!file_exists($this->log_path)) {
            if (!wp_mkdir_p($this->log_path)) {
                throw new RuntimeException("Failed to create log directory: {$this->log_path}");
            }
            chmod($this->log_path, self::LOG_DIR_PERMISSIONS);
            file_put_contents($this->log_path . '.htaccess', 'deny from all');
            chmod($this->log_path . '.htaccess', self::LOG_FILE_PERMISSIONS);
        }

        // Ensure directory is writable
        if (!is_writable($this->log_path)) {
            throw new RuntimeException("Log directory is not writable: {$this->log_path}");
        }
    }

    /**
     * Log a message with enhanced context handling and error tracking
     */
    public function log($message, $level = 'info', $context = []): void
    {
        try {
            // Validate inputs
            if (empty($message)) {
                return;
            }

            if (!$this->isValidLogLevel($level)) {
                $level = 'info';
            }

            // Ensure message is a string
            if (!is_string($message)) {
                $message = print_r($message, true);
            }

            // Ensure context is an array
            if (!is_array($context)) {
                $context = ['original_context' => $context];
            }

            $timestamp = current_time('mysql');
            $backtrace = '';

            // Add stack trace for errors
            if ($this->enable_stack_traces && in_array($level, ['error', 'critical'])) {
                $backtrace = $this->getFormattedBacktrace();
            }

            // Sanitize and limit context depth
            $sanitized_context = $this->sanitizeContext($context);

            // Get current user safely
            $current_user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
            $username = ($current_user && !empty($current_user->user_login)) ? $current_user->user_login : 'system';

            $formatted_message = sprintf(
                "[%s] [%s] [%s]: %s %s%s\n",
                $timestamp,
                strtoupper($level),
                $username,
                $message,
                $sanitized_context ? json_encode($sanitized_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '',
                $backtrace
            );

            $log_file = $this->getLogFilePath();

            // Ensure directory exists
            $log_dir = dirname($log_file);
            if (!is_dir($log_dir)) {
                wp_mkdir_p($log_dir);
            }

            // Ensure atomic writes with error handling
            $bytes_written = file_put_contents($log_file, $formatted_message, FILE_APPEND | LOCK_EX);
            if ($bytes_written === false) {
                // Fallback to error_log if file write fails
                error_log("BemaCRM Log [{$level}]: {$message}");
                return;
            }

            // Set file permissions safely
            if (file_exists($log_file)) {
                @chmod($log_file, self::LOG_FILE_PERMISSIONS);
            }

            // Track errors and handle notifications
            if (in_array($level, ['error', 'critical'])) {
                $this->trackError($message, $context);
            }

            // Rotate log if needed
            $this->rotateLogIfNeeded($log_file);

        } catch (Exception $e) {
            // Fallback logging to prevent infinite loops
            error_log("BemaCRM Logger Error: " . $e->getMessage());
            error_log("Original Log Message [{$level}]: {$message}");
        }
    }

    /**
     * Get formatted backtrace
     */
    private function getFormattedBacktrace(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        $formatted = "\nStack Trace:\n";

        foreach (array_slice($trace, 1) as $i => $t) {
            $formatted .= sprintf(
                "#%d %s(%d): %s%s%s()\n",
                $i,
                $t['file'] ?? '[internal function]',
                $t['line'] ?? 0,
                $t['class'] ?? '',
                $t['type'] ?? '',
                $t['function'] ?? ''
            );
        }

        return $formatted;
    }

    /**
     * Track error frequency and trigger notifications
     */
    private function trackError($message, $context): void
    {
        $error_count = wp_cache_get('bema_error_count') ?: [];
        $current_hour = date('Y-m-d H');

        if (!isset($error_count[$current_hour])) {
            $error_count = [$current_hour => 1];
        } else {
            $error_count[$current_hour]++;
        }

        wp_cache_set('bema_error_count', $error_count, '', 3600);

        if ($error_count[$current_hour] >= $this->error_threshold) {
            $this->notifyAdmin($message, $context, $error_count[$current_hour]);
            // Reset count after notification
            $error_count[$current_hour] = 0;
            wp_cache_set('bema_error_count', $error_count, '', 3600);
        }
    }

    /**
     * Get log file path based on rotation settings
     */
    private function getLogFilePath(): string
    {
        $date_format = 'Y-m-d';
        switch ($this->log_rotation_interval) {
            case 'weekly':
                $date_format = 'Y-\W\k-W';
                break;
            case 'monthly':
                $date_format = 'Y-m';
                break;
        }
        return $this->log_path . 'sync_' . date($date_format) . '.log';
    }

    /**
     * Rotate log file if needed with improved error handling
     */
    private function rotateLogIfNeeded($log_file): void
    {
        if (!file_exists($log_file) || filesize($log_file) <= $this->max_log_size) {
            return;
        }

        try {
            $archive_name = $this->log_path . 'archive_' . date('Y-m-d_H-i-s') . '.log';
            if (!rename($log_file, $archive_name)) {
                throw new RuntimeException("Failed to rotate log file");
            }
            $this->compressLog($archive_name);
            $this->cleanOldLogs();
        } catch (Exception $e) {
            error_log("Log rotation failed: " . $e->getMessage());
        }
    }

    /**
     * Compress log file with improved error handling
     */
    private function compressLog($file_path): void
    {
        if (!file_exists($file_path)) {
            return;
        }

        $gz_file = $file_path . '.gz';
        try {
            if (!$fp_out = gzopen($gz_file, 'wb9')) {
                throw new RuntimeException("Failed to open gzip file for writing");
            }

            if (!$fp_in = fopen($file_path, 'rb')) {
                gzclose($fp_out);
                throw new RuntimeException("Failed to open source file for reading");
            }

            while (!feof($fp_in)) {
                gzwrite($fp_out, fread($fp_in, 1024 * 512));
            }

            fclose($fp_in);
            gzclose($fp_out);
            unlink($file_path);
        } catch (Exception $e) {
            if (isset($fp_in)) fclose($fp_in);
            if (isset($fp_out)) gzclose($fp_out);
            throw $e;
        }
    }

    /**
     * Enhanced admin notification with rate limiting
     */
    private function notifyAdmin($message, $context, $error_count): void
    {
        if (
            !$this->notification_email ||
            !$this->shouldSendNotification()
        ) {
            return;
        }

        $subject = sprintf(
            'Bema CRM Sync Error Alert - %d errors in the last hour',
            $error_count
        );

        $body = sprintf(
            "Multiple errors have occurred during synchronization:\n\n" .
                "Latest Error: %s\n\n" .
                "Context: %s\n\n" .
                "Total Errors this hour: %d\n\n" .
                "Log Location: %s",
            $message,
            json_encode($context, JSON_PRETTY_PRINT),
            $error_count,
            $this->getLogFilePath()
        );

        wp_mail($this->notification_email, $subject, $body);
        wp_cache_set('last_error_notification', time(), '', self::ERROR_NOTIFICATION_INTERVAL);
    }

    /**
     * Check if we should send a notification (rate limiting)
     */
    private function shouldSendNotification(): bool
    {
        $last_notification = wp_cache_get('last_error_notification');
        return !$last_notification ||
            (time() - $last_notification) >= self::ERROR_NOTIFICATION_INTERVAL;
    }

    /**
     * Sanitize and limit context depth
     */
    private function sanitizeContext($context, $depth = 0): array
    {
        if ($depth >= self::MAX_CONTEXT_DEPTH) {
            return ['truncated' => true];
        }

        $sanitized = [];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value, $depth + 1);
            } else {
                $sanitized[$key] = $this->sanitizeValue($value);
            }
        }
        return $sanitized;
    }

    /**
     * Sanitize individual values
     */
    private function sanitizeValue($value)
    {
        if (is_string($value)) {
            return substr(sanitize_text_field($value), 0, 1000);
        }
        if (is_numeric($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_null($value)) {
            return 'null';
        }
        if (is_array($value) || is_object($value)) {
            return '[complex_data_type]';
        }
        return (string) $value;
    }

    /**
     * Validate log level
     */
    private function isValidLogLevel(string $level): bool
    {
        return in_array($level, $this->log_levels);
    }

    /**
     * Get memory usage information
     */
    private function getMemoryInfo(): array
    {
        return [
            'current_usage' => memory_get_usage(true),
            'peak_usage' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit')
        ];
    }

    /**
     * Clean old logs with improved management
     */
    public function cleanOldLogs(int $days = 30): int
    {
        $count = 0;
        $cutoff = strtotime("-{$days} days");
        $files = glob($this->log_path . '*.{log,gz}', GLOB_BRACE);

        if ($files === false) {
            throw new RuntimeException("Failed to list log files");
        }

        // Sort files by modification time
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        // Keep newest files within limit
        foreach (array_slice($files, $this->max_log_files) as $file) {
            if (is_file($file) && (filemtime($file) < $cutoff || count($files) > $this->max_log_files)) {
                if (unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Get log path
     */
    public function getLogPath(): string
    {
        return $this->log_path;
    }

    /**
     * Set log rotation interval
     */
    public function setLogRotationInterval(string $interval): void
    {
        if (in_array($interval, ['daily', 'weekly', 'monthly'])) {
            $this->log_rotation_interval = $interval;
        }
    }

    /**
     * Set error threshold for notifications
     */
    public function setErrorThreshold(int $threshold): void
    {
        $this->error_threshold = max(1, $threshold);
    }
}
