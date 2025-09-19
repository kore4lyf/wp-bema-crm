<?php

namespace Bema;

use Exception;
use RuntimeException;
use WP_Object_Cache;
use Bema\Providers\EDD;
use Bema\Providers\MailerLite;
use BemaExceptions\Sync_Exception;
use BemaExceptions\API_Exception;

use Bema\SyncBatchProcessor;
use Bema\Campaign_Manager;
use Bema\Bema_Settings;
use Bema\Bema_CRM_Logger;
use Bema\Database\Subscribers_Database_Manager;
use Bema\Database\Campaign_group_Subscribers_Database_Manager;
use Bema\Database\Sync_Database_Manager;
use Bema\Database\Field_Database_Manager;
use Bema\Database\Group_Database_Manager;
use Bema\Database\Campaign_Database_Manager;
use Bema\Database\Transition_Database_Manager;
use Bema\Database\Transition_Subscribers_Database_Manager;
use Bema\Database_Manager;
use Bema\Sync_Manager;
use Bema\Transition_Manager;
use Bema\Utils;

if (!defined('ABSPATH')) {
    exit;
}

class RetryableException extends Exception
{
    private $isRetryable = true;
    private $retryCount = 0;
    private $maxRetries = 3;

    public function isRetryable(): bool
    {
        return $this->isRetryable && $this->retryCount < $this->maxRetries;
    }

    public function incrementRetryCount(): void
    {
        $this->retryCount++;
    }
}

class EM_Sync
{
    private $mailerLiteInstance;
    private $eddInstance;
    private $dbManager;
    private $logger;
    private $campaign_manager;
    private $cache;
    private $settings;

    private $utils;
    private $campaign_database;
    private $subscribers_database;
    private $sync_database;
    private $field_database;
    private $group_database;
    private $campaign_group_subscribers_database;
    private $transition_database;
    private $transition_subscribers_database;
    private $sync_manager;
    private $transition_manager;

    // Performance settings
    private $batchSize = 1000;
    private $maxRetries = 3;
    private $maxMemoryLimit = '256M';
    private $maxProcessingTime = 300; // 5 minutes
    private $memoryThreshold = 0.8; // 80% memory usage threshold
    private $syncStatus = [];
    private $errorQueue = [];

    // Constants
    const CACHE_TTL = 3600; // 1 hour
    const QUEUE_KEY = 'bema_sync_queue';
    const STATUS_KEY = 'bema_sync_status';
    const ERROR_LOG_KEY = 'bema_sync_errors';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_LOW = 'low';
    const MAX_ERRORS_PER_BATCH = 100;
    const TRANSACTION_TIMEOUT = 30; // seconds
    const MAX_PAGES_PER_RUN = 10;
    const SUBSCRIBERS_PER_PAGE = 100;
    const PROGRESS_STATE_KEY = 'bema_sync_progress_state';

    public function __construct(
        MailerLite $mailerLiteInstance,
        EDD $eddInstance,
        ?Bema_Settings $settings = null,
        ?Bema_CRM_Logger $logger = null
    ) {
        try {
            // Initialize logger with proper configuration first
            $logger_config = $this->getLoggerConfig();
            $this->logger = $logger ?? Bema_CRM_Logger::create('em-sync', $logger_config);
            $this->logger->info('Starting EM_Sync construction');

            // Set error handling
            set_error_handler([$this, 'errorHandler']);
            $this->logger->debug('Error handler set');

            // Initialize database manager
            global $wpdb;
            $this->dbManager = new Database_Manager($wpdb);
            $this->logger->debug('Database manager initialized');

            // Store settings instance
            $this->settings = $settings;
            $this->logger->debug('Settings instance stored');

            // Load API credentials
            $this->loadApiCredentials();

            // Store provider instances
            $this->mailerLiteInstance = $mailerLiteInstance;
            $this->logger->debug('MailerLite instance stored');

            $this->eddInstance = $eddInstance;
            $this->logger->debug('EDD instance stored');

            $this->utils = new Utils();
            $this->logger->debug('Utils instance stored');

            $this->campaign_database = new Campaign_Database_Manager();
            $this->logger->debug('Campaign Database instance stored');

            $this->subscribers_database = new Subscribers_Database_Manager();
            $this->logger->debug('Subscribers Database instance stored');

            $this->sync_database = new Sync_Database_Manager();
            $this->logger->debug('SYNC Database instance stored');

            $this->field_database = new Field_Database_Manager();
            $this->logger->debug('Field Database instance stored');

            $this->group_database = new Group_Database_Manager();
            $this->logger->debug('Group Database instance stored');

            $this->campaign_group_subscribers_database = new Campaign_Group_Subscribers_Database_Manager();
            $this->logger->debug('Campaign Subscribers Database instance stored');

            $this->transition_database = new Transition_Database_Manager();
            $this->logger->debug('Transition Database instance stored');

            $this->transition_subscribers_database = new Transition_Subscribers_Database_Manager();
            $this->logger->debug('Transition Subscribers Database instance stored');
            
            // Initialize sync and transition managers
            $this->sync_manager = new \Bema\Sync_Manager($this->logger);
            $this->logger->debug('Sync Manager instance stored');
            
            $this->transition_manager = new \Bema\Transition_Manager($this->logger);
            $this->logger->debug('Transition Manager instance stored');
            
            // Initialize campaign manager
            $this->campaign_manager = new Campaign_Manager($mailerLiteInstance);

            $this->cache = new WP_Object_Cache();
            $this->logger->debug('Cache initialized');

            // Initialize progress tracker and error queue
            $this->initializeProgress();
            $this->logger->debug('Progress initialized');

            $this->initializeErrorQueue();
            $this->logger->debug('Error queue initialized');

            // Register shutdown function
            register_shutdown_function([$this, 'handleShutdown']);
            $this->logger->debug('Shutdown handler registered');

            $this->logger->info('EM_Sync construction completed successfully');
        } catch (Exception $e) {
            // If logger is available, use it; otherwise fall back to error_log
            if ($this->logger) {
                $this->logger->critical('EM_Sync initialization failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            } else {
                error_log("EM_Sync initialization failed: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
            }
            $this->logError('Failed to initialize EM_Sync', $e);
            throw new Sync_Exception('Failed to initialize sync system: ' . $e->getMessage());
        }
    }

    private function logError(string $message, Exception $e, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->error($message, array_merge($context, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]));
        } else {
            error_log($message . ': ' . $e->getMessage());
        }
    }

    private function shouldStopSync(): bool
    {
        return (bool) get_option('bema_sync_stop_flag', false);
    }

    private function getMemoryLimitInBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }

        preg_match('/^(\d+)(.)$/', $memoryLimit, $matches);
        if (!$matches) {
            return 128 * 1024 * 1024; // 128MB default
        }

        $value = (int) $matches[1];
        switch (strtoupper($matches[2])) {
            case 'G':
                $value *= 1024;
            // fallthrough
            case 'M':
                $value *= 1024;
            // fallthrough
            case 'K':
                $value *= 1024;
        }

        return $value;
    }

    private function formatBytes($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    public function updateSubscriberField(string $email, string $fieldName, $fieldValue): void
    {
        $subscriber = $this->mailerLiteInstance->getSubscriber($email);
        if ($subscriber) {
            $this->mailerLiteInstance->updateSubscriber($subscriber['id'], [
                'fields' => [
                    $fieldName => $fieldValue
                ]
            ]);
        }
    }

    public function stopSync(): bool
    {
        return update_option('bema_sync_stop_flag', true);
    }

    private function getLoggerConfig(): array
    {
        $config = [
            'max_file_size_mb' => 10,
            'max_file_age_days' => 30,
        ];

        // Set log level based on environment
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $config['log_level'] = Bema_CRM_Logger::DEBUG; // Verbose in development
        } else {
            $config['log_level'] = Bema_CRM_Logger::WARNING; // Production level
        }

        return $config;
    }

    // Add placeholder methods for missing functionality
    private function loadApiCredentials(): void
    {
        // Placeholder implementation
    }

    private function validateApiCredentials(): bool
    {
        return true; // Placeholder implementation
    }

    private function initializeProgress(): void
    {
        $this->syncStatus = [
            'status' => 'idle',
            'processed' => 0,
            'total' => 0
        ];
    }

    private function initializeErrorQueue(): void
    {
        $this->errorQueue = [];
    }

    private function errorHandler($errno, $errstr, $errfile, $errline): bool
    {
        return false; // Let PHP handle the error
    }

    public function handleShutdown(): void
    {
        // Placeholder implementation
    }

    // Add other essential methods as placeholders
    public function syncAll(array $campaigns): bool
    {
        return true; // Placeholder implementation
    }

    public function getGroups(): array
    {
        return []; // Placeholder implementation
    }

    public function getMailerLiteInstance()
    {
        return $this->mailerLiteInstance;
    }

    public function getCurrentProgress(): array
    {
        return $this->syncStatus;
    }

    public function getEDDInstance()
    {
        return $this->eddInstance;
    }

    public function syncGroups(): array
    {
        return [
            'synced' => [],
            'failed' => []
        ];
    }

    public function getErrorLogs(): array
    {
        return $this->errorQueue;
    }

    public function clearErrorLogs(): void
    {
        $this->errorQueue = [];
        update_option(self::ERROR_LOG_KEY, [], false);
    }

    public function validateAPIConnections(): array
    {
        return [
            'mailerlite' => true,
            'edd' => true
        ];
    }

    private function updateProgress(array $data): void
    {
        $this->syncStatus = array_merge($this->syncStatus, $data);
        update_option(self::STATUS_KEY, $this->syncStatus, false);
    }
}