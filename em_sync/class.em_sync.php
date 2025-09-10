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
    private $queueManager;
    private $settings;
    private $sync_scheduler;
    private $utils;
    private $campaign_database;
    private $subscribers_database;
    private $sync_database;
    private $field_database;
    private $group_database;
    private $campaign_group_subscribers_database;
    private $transition_database;
    private $transition_subscribers_database;

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
        ?Bema_CRM_Logger $logger = null,
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
            // Initialize campaign manager
            $this->campaign_manager = new Campaign_Manager($mailerLiteInstance);

            $this->cache = new WP_Object_Cache();
            $this->logger->debug('Cache initialized');

            $this->queueManager = new SyncQueueManager();
            $this->logger->debug('Queue manager initialized');

            // Set memory limit
            $this->setMemoryLimit();
            $this->logger->debug('Memory limit set');

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

    /**
     * Get logger configuration based on environment
     */
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

    /**
     * Load API credentials from settings
     */
    private function loadApiCredentials(): void
    {
        try {
            if (!$this->settings) {
                $this->logger->warning('Settings instance not available for API credential loading');
                return;
            }

            $settings = $this->settings->get_settings();
            if (!is_array($settings)) {
                $this->logger->error('Invalid settings data received', [
                    'type' => gettype($settings)
                ]);
                return;
            }

            $this->logger->debug('Loading API credentials', [
                'has_mailerlite_key' => !empty($settings['api']['mailerlite_api_key']),
                'has_edd_key' => !empty($settings['api']['edd_api_key']),
                'has_edd_token' => !empty($settings['api']['edd_token'])
            ]);

            // Reinitialize providers with credentials if needed
            if (!empty($settings['api']['mailerlite_api_key'])) {
                try {
                    $this->mailerLiteInstance = new Providers\MailerLite(
                        $settings['api']['mailerlite_api_key'],
                        $this->logger
                    );
                    $this->logger->debug('MailerLite provider reinitialized with credentials');
                } catch (Exception $e) {
                    $this->logger->error('Failed to reinitialize MailerLite provider', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            if (!empty($settings['api']['edd_api_key']) && !empty($settings['api']['edd_token'])) {
                try {
                    $this->eddInstance = new Providers\EDD(
                        $settings['api']['edd_api_key'],
                        $settings['api']['edd_token'],
                        $this->logger
                    );
                    $this->logger->debug('EDD provider reinitialized with credentials');
                } catch (Exception $e) {
                    $this->logger->error('Failed to reinitialize EDD provider', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Error loading API credentials', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Validate API credentials before sync
     * @throws Exception
     * @return bool
     */
    private function validateApiCredentials(): bool
    {
        try {
            if (!$this->mailerLiteInstance || !$this->eddInstance) {
                throw new Exception('API providers not properly initialized');
            }

            // Load API credentials from settings
            $this->loadApiCredentials();

            $this->logger->info('Starting API validation');

            // Test MailerLite connection first
            try {
                $mailerliteResult = $this->mailerLiteInstance->validateConnection();
                $this->logger->info('MailerLite validation successful', [
                    'result' => $mailerliteResult
                ]);
            } catch (Exception $e) {
                $this->logger->error('MailerLite validation failed', [
                    'error' => $e->getMessage()
                ]);
                throw new Exception('MailerLite API connection failed: ' . $e->getMessage());
            }

            // Then test EDD connection
            try {
                $eddResult = $this->eddInstance->validateConnection();
                $this->logger->info('EDD validation successful', [
                    'result' => $eddResult
                ]);
            } catch (Exception $e) {
                $this->logger->error('EDD validation failed', [
                    'error' => $e->getMessage()
                ]);
                throw new Exception('EDD API connection failed: ' . $e->getMessage());
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error('API validation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function setMemoryLimit(): void
    {
        try {
            $currentLimit = ini_get('memory_limit');
            $bytes = $this->getMemoryLimitInBytes();

            if ($bytes < $this->getMemoryLimitInBytes($this->maxMemoryLimit)) {
                $result = ini_set('memory_limit', $this->maxMemoryLimit);
                if ($result === false) {
                    $this->logger->warning('Failed to increase memory limit', [
                        'current' => $currentLimit,
                        'requested' => $this->maxMemoryLimit
                    ]);
                } else {
                    $this->logger->info('Memory limit increased', [
                        'from' => $currentLimit,
                        'to' => $this->maxMemoryLimit
                    ]);
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Error setting memory limit', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function initializeProgress(): void
    {
        try {
            $this->syncStatus = [
                'total' => 0,
                'processed' => 0,
                'failed' => 0,
                'retried' => 0,
                'status' => 'idle',
                'start_time' => null,
                'end_time' => null,
                'current_campaign' => null,
                'campaign_progress' => [],
                'memory_usage' => $this->formatBytes(memory_get_usage(true)),
                'peak_memory' => $this->formatBytes(memory_get_peak_usage(true))
            ];

            $result = update_option(self::STATUS_KEY, $this->syncStatus, false);
            if (!$result) {
                $this->logger->warning('Failed to initialize progress status in database');
            }
        } catch (Exception $e) {
            $this->logger->error('Error initializing progress', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Set minimal fallback status
            $this->syncStatus = ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    private function initializeErrorQueue(): void
    {
        try {
            $this->errorQueue = get_option(self::ERROR_LOG_KEY, []);
            if (!is_array($this->errorQueue)) {
                $this->logger->warning('Error queue data corrupted, reinitializing', [
                    'found_type' => gettype($this->errorQueue)
                ]);
                $this->errorQueue = [];
                $result = update_option(self::ERROR_LOG_KEY, [], false);
                if (!$result) {
                    $this->logger->error('Failed to reinitialize error queue in database');
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Error initializing error queue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Set fallback empty array
            $this->errorQueue = [];
        }
    }

    public function syncAll(array $campaigns): bool
    {
        try {
            if (!$this->validateApiCredentials()) {
                throw new Exception('API credentials validation failed');
            }

            if ($this->shouldStopSync()) {
                return false;
            }

            $startTime = microtime(true);

            $this->logger->info('Starting sync process', [
                'campaigns_count' => count($campaigns),
                'start_time' => date('Y-m-d H:i:s', (int) $startTime)
            ]);

            delete_option('bema_sync_stop_flag');

            $this->updateProgress([
                'status' => 'running',
                'start_time' => $startTime,
                'processed' => 0,
                'total' => 0
            ]);

            // Validate campaigns
            $validCampaigns = $this->validateCampaigns($campaigns);
            if (empty($validCampaigns)) {
                throw new Exception('No valid campaigns to process');
            }

            $totalProcessed = 0;
            $totalCampaigns = count($validCampaigns);

            // Process each campaign
            foreach ($validCampaigns as $index => $campaign) {
                if ($this->shouldStopSync()) {
                    $this->logger->warning('Sync process stopped by user');
                    $this->updateProgress([
                        'status' => 'stopped',
                        'end_time' => microtime(true),
                        'processed' => $totalProcessed,
                        'total' => $totalCampaigns
                    ]);
                    return false;
                }

                try {
                    $this->updateProgress([
                        'current_campaign' => $campaign['name'],
                        'status' => 'processing_campaign',
                        'campaign_number' => $index + 1,
                        'total_campaigns' => $totalCampaigns
                    ]);

                    // Process campaign groups
                    $this->processCampaignGroups($campaign);
                    $totalProcessed++;

                    $this->logger->info('Campaign completed', [
                        'campaign' => $campaign['name'],
                        'processed' => $totalProcessed,
                        'total' => $totalCampaigns
                    ]);
                } catch (Exception $e) {
                    $this->logger->error('Campaign processing failed', [
                        'campaign' => $campaign['name'],
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    continue;
                }
            }

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            // Final completion status
            $this->updateProgress([
                'status' => 'completed',
                'end_time' => $endTime,
                'processed' => $totalProcessed,
                'total' => $totalCampaigns,
                'duration' => $duration
            ]);

            $this->logger->info('Sync completed successfully', [
                'total_processed' => $totalProcessed,
                'total_campaigns' => $totalCampaigns,
                'duration' => $duration,
                'start_time' => date('Y-m-d H:i:s', (int) $startTime),
                'end_time' => date('Y-m-d H:i:s', (int) $endTime)
            ]);

            // Clear any remaining sync flags
            delete_option('bema_sync_running');
            delete_transient('bema_sync_lock');
            wp_cache_delete('sync_in_progress', 'bema_sync');

            return true;
        } catch (Exception $e) {
            $this->logger->error('Sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->updateProgress([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'end_time' => microtime(true)
            ]);

            // Clear sync flags even on failure
            delete_option('bema_sync_running');
            delete_transient('bema_sync_lock');
            wp_cache_delete('sync_in_progress', 'bema_sync');

            return false;
        }
    }

    private function processCampaign($campaign, $batchProcessor): void
    {
        try {
            // Get campaign groups first
            $groups = $this->campaign_manager->get_campaign_groups($campaign['name']);
            if (!$groups) {
                throw new Exception("No groups defined for campaign: {$campaign['name']}");
            }

            foreach ($groups as $groupType => $groupSuffix) {
                if ($this->shouldStopSync()) {
                    break;
                }

                // Construct full group name
                $fullGroupName = "{$campaign['name']}_{$groupSuffix}";

                // Get subscribers for this group
                $subscribers = $this->mailerLiteInstance->getGroupSubscribers($fullGroupName);
                $totalSubscribers = count($subscribers);
                $processedCount = 0;
                $retryCount = 0;

                foreach (array_chunk($subscribers, $this->batchSize) as $batch) {
                    try {
                        $startTime = microtime(true);
                        $this->processCampaignBatch($batch, $campaign, $groupType);
                        $processedCount += count($batch);

                        $this->updateProgress([
                            'campaign_progress' => [
                                'campaign' => $campaign['name'],
                                'group' => $groupType,
                                'processed' => $processedCount,
                                'total' => $totalSubscribers,
                                'batch_time' => round(microtime(true) - $startTime, 2)
                            ]
                        ]);
                    } catch (RetryableException $e) {
                        if ($retryCount < $this->maxRetries) {
                            $retryCount++;
                            $this->logger->warning('Retrying batch', [
                                'campaign' => $campaign['name'],
                                'group' => $groupType,
                                'attempt' => $retryCount,
                                'error' => $e->getMessage()
                            ]);
                            continue;
                        }
                        throw $e;
                    }

                    if (!$this->canContinueProcessing(time())) {
                        $remainingSubscribers = array_slice($subscribers, $processedCount);
                        $this->queueRemainingWork($campaign, $remainingSubscribers, $groupType);
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Campaign processing failed', [
                'campaign' => $campaign['name'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function processCampaignGroup(array $campaign, string $groupType, string $groupId): void
    {
        try {
            // Load existing progress
            $progressState = $this->loadProgressState();
            $startPage = 1;

            if (
                $progressState &&
                $progressState['campaign'] === $campaign['name'] &&
                $progressState['group_type'] === $groupType
            ) {
                $startPage = $progressState['next_page'];
            }

            $currentPage = $startPage;
            $processedPages = 0;

            while ($processedPages < self::MAX_PAGES_PER_RUN) {
                if ($this->shouldStopSync()) {
                    break;
                }

                // Get subscribers for this page
                $subscribers = $this->mailerLiteInstance->getGroupSubscribers($groupId, [
                    'page' => $currentPage,
                    'limit' => self::SUBSCRIBERS_PER_PAGE
                ]);

                if (empty($subscribers)) {
                    // No more subscribers to process
                    $this->clearProgressState();
                    break;
                }

                $this->updateProgress([
                    'total' => count($subscribers),
                    'current_group' => $groupType,
                    'current_page' => $currentPage,
                    'total_pages_processed' => $processedPages
                ]);

                try {
                    $this->processCampaignBatch($subscribers, $campaign, $groupType);
                } catch (Exception $e) {
                    // Save state for retry
                    $this->saveProgressState([
                        'campaign' => $campaign['name'],
                        'group_type' => $groupType,
                        'group_id' => $groupId,
                        'next_page' => $currentPage,
                        'error' => $e->getMessage(),
                        'retry_count' => ($progressState['retry_count'] ?? 0) + 1
                    ]);

                    throw $e;
                }

                $currentPage++;
                $processedPages++;

                // Save progress state
                $this->saveProgressState([
                    'campaign' => $campaign['name'],
                    'group_type' => $groupType,
                    'group_id' => $groupId,
                    'next_page' => $currentPage,
                    'retry_count' => 0
                ]);

                // Memory management
                $this->manageMemory();
            }

            // If we've hit the page limit, save state for next run
            if ($processedPages >= self::MAX_PAGES_PER_RUN) {
                $this->saveProgressState([
                    'campaign' => $campaign['name'],
                    'group_type' => $groupType,
                    'group_id' => $groupId,
                    'next_page' => $currentPage,
                    'retry_count' => 0
                ]);

                $this->logger->info('Max pages reached for current run', [
                    'campaign' => $campaign['name'],
                    'group' => $groupType,
                    'pages_processed' => $processedPages,
                    'next_page' => $currentPage
                ]);
            }
        } catch (Exception $e) {
            $this->logger->error('Group processing failed', [
                'campaign' => $campaign['name'],
                'group' => $groupType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function getCampaignGroupId(string $campaignName, string $groupSuffix): ?string
    {
        try {
            $groups = $this->mailerLiteInstance->getGroups();
            $fullGroupName = "{$campaignName}_{$groupSuffix}";

            // Case-insensitive search
            foreach ($groups as $group) {
                if (strcasecmp($group['name'], $fullGroupName) === 0) {
                    return $group['id'];
                }
            }

            return null;
        } catch (Exception $e) {
            $this->logger->error('Failed to get campaign group ID', [
                'campaign' => $campaignName,
                'group' => $groupSuffix,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function saveProgressState(array $state): void
    {
        update_option(self::PROGRESS_STATE_KEY, [
            'timestamp' => current_time('mysql'),
            'state' => $state
        ], false);

        $this->logger->debug('Progress state saved', $state);
    }

    private function loadProgressState(): ?array
    {
        $saved = get_option(self::PROGRESS_STATE_KEY);
        if (!$saved) {
            return null;
        }

        $this->logger->debug('Progress state loaded', $saved);

        return $saved['state'];
    }

    private function clearProgressState(): void
    {
        try {
            $result = delete_option(self::PROGRESS_STATE_KEY);
            if (!$result) {
                $this->logger->warning('Failed to clear progress state from database');
            } else {
                $this->logger->debug('Progress state cleared successfully');
            }
        } catch (Exception $e) {
            $this->logger->error('Error clearing progress state', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Queue remaining work for later processing
     */
    private function queueRemainingWork(array $campaign, array $subscribers, string $groupType): void
    {
        $this->queueManager->addToQueue([
            'campaign' => $campaign,
            'subscribers' => $subscribers,
            'group_type' => $groupType
        ], 'high');

        $this->logger->info('Queued remaining work', [
            'campaign' => $campaign['name'],
            'group' => $groupType,
            'remaining_subscribers' => count($subscribers)
        ]);
    }

    private function processCampaignGroups(array $campaign): void
    {
        try {
            if (!isset($campaign['name'])) {
                throw new Exception('Campaign name missing');
            }

            // Get campaign groups
            $groups = $this->campaign_manager->get_campaign_groups($campaign['name']);
            if (!$groups) {
                throw new Exception("No groups defined for campaign: {$campaign['name']}");
            }

            foreach ($groups as $groupType => $groupSuffix) {
                if ($this->shouldStopSync()) {
                    return;
                }

                // Construct full group name
                $fullGroupName = "{$campaign['name']}_{$groupSuffix}";

                // Get group ID from MailerLite
                $groupId = $this->getGroupIdByName($fullGroupName);
                if (!$groupId) {
                    $this->logger->warning('Group not found', [
                        'group_name' => $fullGroupName
                    ]);
                    continue;
                }

                $this->processCampaignGroup($campaign, $groupType, $groupId);
            }
        } catch (Exception $e) {
            $this->logger->error('Campaign processing failed', [
                'campaign' => $campaign['name'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process a batch of subscribers within a transaction
     */
    private function processBatchWithTransaction(array $batch, array $campaign, string $groupType): void
    {
        try {
            $this->dbManager->beginTransactionWithRetry(self::TRANSACTION_TIMEOUT);

            foreach ($batch as $subscriber) {
                if ($this->shouldStopSync()) {
                    $this->dbManager->rollback();
                    return;
                }

                try {
                    // Check purchase status
                    $hasPurchased = $this->eddInstance->hasUserPurchasedProduct(
                        $subscriber['id'],
                        $campaign['product_id'] ?? null
                    );

                    // Update MailerLite custom fields
                    if (!empty($subscriber['id'])) {
                        $this->mailerLiteInstance->updateSubscriber(
                            $subscriber['id'],
                            [
                                'fields' => [
                                    $campaign['field'] => $hasPurchased ? 1 : 0
                                ]
                            ]
                        );
                    }

                    // Update local database
                    $this->dbManager->updateSubscriberPurchaseStatus(
                        $subscriber['email'],
                        $campaign['name'],
                        $hasPurchased
                    );

                    // Update group mapping
                    $this->dbManager->updateSubscriberGroup(
                        $subscriber['email'],
                        $subscriber['group_id'],
                        $campaign['name']
                    );

                    // Handle tier transition if needed
                    if ($hasPurchased) {
                        $this->handleTierTransition($subscriber, $campaign, $groupType);
                    }

                    $this->updateProgress([
                        'processed' => $this->getCurrentProgress()['processed'] + 1
                    ]);
                } catch (Exception $e) {
                    $this->logger->error('Failed to process subscriber', [
                        'email' => $subscriber['email'],
                        'campaign' => $campaign['name'],
                        'error' => $e->getMessage()
                    ]);

                    // Add to error queue but continue processing
                    $this->addToErrorQueue([
                        'email' => $subscriber['email'],
                        'campaign' => $campaign['name'],
                        'error' => $e->getMessage(),
                        'timestamp' => time()
                    ]);
                }
            }

            $this->dbManager->commit();
        } catch (Exception $e) {
            $this->dbManager->rollback();
            throw $e;
        }
    }

    /**
     * Process a batch of campaign subscribers
     * @param array $batch Subscriber batch
     * @param array $context Campaign context data
     * @param string $groupType Current group type
     */
    private function processCampaignBatch(array $batch, array $context, string $groupType): void
    {
        try {
            $this->dbManager->beginTransactionWithRetry(self::TRANSACTION_TIMEOUT);

            foreach ($batch as $subscriber) {
                if ($this->shouldStopSync()) {
                    $this->dbManager->rollback();
                    return;
                }

                try {
                    $this->processSingleSubscriber($subscriber, $context, $groupType);
                } catch (Exception $e) {
                    $this->logError('Single subscriber processing failed', $e, [
                        'email' => $subscriber['email'] ?? 'unknown'
                    ]);
                }

                $this->updateProgress([
                    'processed' => $this->getCurrentProgress()['processed'] + 1
                ]);
            }

            $this->dbManager->commit();
        } catch (Exception $e) {
            $this->dbManager->rollback();
            throw $e;
        }
    }

    public function syncGroups(): array
    {
        try {
            // Get all MailerLite groups first
            $mailerlite_groups = $this->mailerLiteInstance->getGroups();

            // Get all configured campaigns
            $campaigns = $this->campaign_manager->get_all_valid_campaigns();

            $results = [
                'synced' => [],
                'failed' => []
            ];

            foreach ($campaigns as $campaign) {
                // Get required groups for this campaign
                $campaign_groups = $this->campaign_manager->get_campaign_groups($campaign);

                foreach ($campaign_groups as $tier => $group_name) {
                    $full_group_name = "{$campaign}_{$group_name}";

                    // Check if group exists
                    $exists = false;
                    foreach ($mailerlite_groups as $ml_group) {
                        if (strcasecmp($ml_group['name'], $full_group_name) === 0) {
                            $exists = true;
                            $results['synced'][] = [
                                'campaign' => $campaign,
                                'tier' => $tier,
                                'group_id' => $ml_group['id']
                            ];
                            break;
                        }
                    }

                    if (!$exists) {
                        $results['failed'][] = $full_group_name;
                    }
                }
            }

            return $results;
        } catch (Exception $e) {
            $this->logger->error('Group sync failed', [
                'error' => $e->getMessage()
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    private function determineNextTier(string $currentTier, bool $hasPurchased): string
    {
        // Tier progression map
        $tierProgression = [
            'opt-in' => [
                'purchased' => 'gold',
                'default' => 'bronze'
            ],
            'bronze' => [
                'purchased' => 'silver',
                'default' => 'bronze'
            ],
            'silver' => [
                'purchased' => 'gold',
                'default' => 'silver'
            ],
            'gold' => [
                'purchased' => 'gold',
                'default' => 'gold'
            ]
        ];

        $currentTier = strtolower($currentTier);
        if (!isset($tierProgression[$currentTier])) {
            return $currentTier;
        }

        return $hasPurchased
            ? $tierProgression[$currentTier]['purchased']
            : $tierProgression[$currentTier]['default'];
    }

    /**
     * Handle retryable sync errors
     */
    private function handleRetryableError(Sync_Exception $e, array $campaign, array $subscribers): void
    {
        try {
            // Use sync_scheduler's max retries
            $maxRetries = $this->sync_scheduler ? $this->sync_scheduler->getMaxRetries() : 3;

            if ($e->getRetryCount() < $maxRetries) {
                $e->incrementRetryCount();
                $delay = $e->getNextRetryDelay();

                $retryContext = [
                    'campaign' => $campaign['name'],
                    'retry_count' => $e->getRetryCount(),
                    'next_retry_delay' => $delay,
                    'subscribers_count' => count($subscribers),
                    'max_retries' => $maxRetries
                ];

                $this->logger->info('Scheduling retry for failed batch', $retryContext);

                // Add to retry queue with context
                $this->addToErrorQueue(array_merge(
                    $e->getErrorDetails(),
                    $retryContext
                ));
            }
        } catch (Exception $retryError) {
            $this->logger->error('Failed to handle retry', [
                'error' => $retryError->getMessage(),
                'original_error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Set sync scheduler instance
     * @param Sync_Scheduler $scheduler The scheduler instance
     */
    public function setSyncScheduler(\Bema\Sync_Scheduler $scheduler): void
    {
        $this->sync_scheduler = $scheduler;
        if ($this->logger) {
            $this->logger->debug('Sync scheduler set', [
                'scheduler_initialized' => isset($this->sync_scheduler) ? 'yes' : 'no'
            ]);
        }
    }

    /**
     * Get groups for campaign
     */
    private function getCampaignGroups(array $campaigns): array
    {
        try {
            $this->logger->debug('Getting campaign groups', [
                'campaigns_count' => count($campaigns)
            ]);

            // Get all MailerLite groups first
            $mailerlite_groups = $this->mailerLiteInstance->getGroups();
            if (empty($mailerlite_groups)) {
                throw new Exception('No groups found in MailerLite');
            }

            $this->logger->debug('MailerLite groups retrieved', [
                'groups_found' => count($mailerlite_groups),
                'group_names' => array_column($mailerlite_groups, 'name')
            ]);

            $campaign_groups = [];
            foreach ($campaigns as $campaign) {
                $groups = $this->campaign_manager->get_campaign_groups($campaign['name']);

                if (!$groups) {
                    $this->logger->warning('No groups defined for campaign', [
                        'campaign' => $campaign['name']
                    ]);
                    continue;
                }

                // Map local groups to MailerLite group IDs
                $mapped_groups = [];
                foreach ($groups as $local_name => $mailerlite_name) {
                    foreach ($mailerlite_groups as $group) {
                        if ($group['name'] === $mailerlite_name) {
                            $mapped_groups[$local_name] = $group['id'];
                            break;
                        }
                    }
                }

                if (empty($mapped_groups)) {
                    $this->logger->warning('No matching MailerLite groups found for campaign', [
                        'campaign' => $campaign['name'],
                        'defined_groups' => $groups
                    ]);
                    continue;
                }

                $campaign_groups[$campaign['name']] = [
                    'groups' => $mapped_groups,
                    'field' => $campaign['field'],
                    'tag' => $campaign['tag']
                ];
            }

            $this->logger->info('Campaign groups processed', [
                'campaign_groups_found' => count($campaign_groups),
                'campaigns' => array_keys($campaign_groups)
            ]);

            if (empty($campaign_groups)) {
                throw new Exception('No valid campaign groups found in MailerLite');
            }

            return $campaign_groups;
        } catch (Exception $e) {
            $this->logger->error('Failed to get campaign groups', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Get product ID for campaign
     */
    public function getEDDProductForCampaign(string $campaignName): ?int
    {
        try {
            $this->logger->debug('Getting EDD product for campaign', [
                'campaign' => $campaignName
            ]);

            // Parse campaign code to get artist and product info
            $parts = explode('_', $campaignName);
            if (count($parts) !== 3) {
                return null;
            }

            [$year, $artist, $product] = $parts;

            // Search EDD products with this naming convention
            $productId = $this->eddInstance->findProductByNamePattern($artist, $product);

            $this->logger->debug('EDD product search completed', [
                'found_product_id' => $productId,
                'artist' => $artist,
                'product' => $product,
                'campaign_name' => $campaignName
            ]);

            return $productId;
        } catch (Exception $e) {
            $this->logger->error('Failed to get EDD product', [
                'campaign' => $campaignName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Get EDD instance
     * @return \Bema\Providers\EDD|null
     */
    public function getEDDInstance()
    {
        return $this->eddInstance;
    }

    /**
     * Validate campaigns before processing
     * @param array $campaigns Campaigns to validate
     * @return array Valid campaigns
     */
    private function validateCampaigns(array $campaigns): array
    {
        try {
            $mailerliteGroups = $this->mailerLiteInstance->getGroups();

            $this->logger->debug('Validating campaigns', [
                'available_groups_count' => count($mailerliteGroups),
                'campaigns_to_validate' => count($campaigns)
            ]);

            if (empty($mailerliteGroups)) {
                throw new Exception('No groups found in MailerLite');
            }

            $validCampaigns = [];
            foreach ($campaigns as $campaign) {
                if (!isset($campaign['name'])) {
                    continue;
                }

                // Check if campaign is valid
                if (!$this->campaign_manager->is_valid_campaign($campaign['name'])) {
                    $this->logger->warning('Invalid campaign skipped', [
                        'campaign' => $campaign['name']
                    ]);
                    continue;
                }

                // Get required groups for this campaign
                $requiredGroups = $this->campaign_manager->get_campaign_groups($campaign['name']);
                if (!$requiredGroups) {
                    $this->logger->warning('Campaign missing groups configuration', [
                        'campaign' => $campaign['name']
                    ]);
                    continue;
                }

                // Validate each required group exists in MailerLite
                $missingGroups = [];
                $groupIds = [];
                foreach ($requiredGroups as $groupType => $groupSuffix) {
                    $found = false;
                    $fullGroupName = "{$campaign['name']}_{$groupSuffix}";

                    foreach ($mailerliteGroups as $mlGroup) {
                        // Case-insensitive comparison
                        if (strcasecmp($mlGroup['name'], $fullGroupName) === 0) {
                            $groupIds[$groupType] = $mlGroup['id'];
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $missingGroups[] = $fullGroupName;
                    }
                }

                if (!empty($missingGroups)) {
                    $this->logger->warning('Campaign has missing groups', [
                        'campaign' => $campaign['name'],
                        'missing_groups' => $missingGroups
                    ]);
                    continue;
                }

                // Add group IDs to campaign data
                $campaign['group_ids'] = $groupIds;
                $validCampaigns[] = $campaign;

                $this->logger->debug('Campaign validated successfully', [
                    'campaign' => $campaign['name'],
                    'group_ids' => $groupIds
                ]);
            }

            return $validCampaigns;
        } catch (Exception $e) {
            $this->logger->error('Campaign validation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function processSingleSubscriber(array $subscriber, array $campaign, string $currentGroup): void
    {
        try {
            $this->logger->debug('Processing subscriber', [
                'email' => $subscriber['email'],
                'campaign' => $campaign['name'],
                'current_group' => $currentGroup
            ]);

            // Get product ID and check purchase status
            $productId = $this->getEDDProductForCampaign($campaign['name']);

            if (!$productId) {
                $this->logger->warning('No product found for campaign', [
                    'campaign' => $campaign['name']
                ]);
                $hasPurchased = false;
            } else {
                $hasPurchased = $this->eddInstance->hasUserPurchasedProduct(
                    $subscriber['id'],
                    $productId
                );
            }

            $this->logger->debug('Purchase check completed', [
                'email' => $subscriber['email'],
                'campaign' => $campaign['name'],
                'has_purchased' => $hasPurchased,
                'product_id' => $productId
            ]);

            // Get campaign groups
            $campaignGroups = $this->campaign_manager->get_campaign_groups($campaign['name']);
            if (!$campaignGroups) {
                throw new Exception("No groups defined for campaign: {$campaign['name']}");
            }

            // Get next tier based on current group and purchase status
            $nextTier = $this->determineNextTier($currentGroup, $hasPurchased);

            // If tier change is needed
            if ($nextTier !== $currentGroup) {
                $nextGroupId = $this->getGroupIdByName("{$campaign['name']}_{$campaignGroups[$nextTier]}");
                if (!$nextGroupId) {
                    throw new Exception("Could not find group ID for tier: {$nextTier}");
                }

                // Remove from current group and add to new group in MailerLite
                if (!empty($subscriber['id'])) {
                    $this->mailerLiteInstance->removeSubscriberFromGroup(
                        $subscriber['id'],
                        $subscriber['group_id']
                    );

                    $this->mailerLiteInstance->addSubscriberToGroup(
                        $subscriber['id'],
                        $nextGroupId
                    );
                }

                // Update subscriber tier in database
                $this->dbManager->updateSubscriberTier(
                    $subscriber['email'],
                    $campaign['name'],
                    $nextTier
                );

                // Update group mapping
                $this->dbManager->updateSubscriberGroup(
                    $subscriber['email'],
                    $nextGroupId,
                    $campaign['name']
                );
            }

            // Always update purchase field in MailerLite
            if (!empty($subscriber['id'])) {
                $this->mailerLiteInstance->updateSubscriber(
                    $subscriber['id'],
                    [
                        'fields' => [
                            $campaign['field'] => $hasPurchased ? 1 : 0
                        ]
                    ]
                );
            }

            // Update purchase status in database
            $this->dbManager->updateSubscriberPurchaseStatus(
                $subscriber['email'],
                $campaign['name'],
                $hasPurchased
            );

            $this->logger->info('Subscriber processed successfully', [
                'email' => $subscriber['email'],
                'campaign' => $campaign['name'],
                'from_tier' => $currentGroup,
                'to_tier' => $nextTier,
                'has_purchased' => $hasPurchased,
                'product_id' => $productId,
                'next_group_id' => isset($nextGroupId) ? $nextGroupId : null
            ]);
        } catch (Exception $e) {
            $this->logger->error('Subscriber processing failed', [
                'email' => $subscriber['email'],
                'campaign' => $campaign['name'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    // In class.em_sync.php, add:

    public function processCampaignTransitions(string $fromCampaign, string $toCampaign): array
    {
        $results = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        try {
            // Validate campaigns
            $currentCampaign = $this->campaign_manager->get_campaign_details($fromCampaign);
            $nextCampaign = $this->campaign_manager->get_campaign_details($toCampaign);

            if (!$currentCampaign || !$nextCampaign) {
                throw new Exception('Invalid campaign configuration');
            }

            // Get subscribers from purchase tiers
            $purchaseTiers = ['gold_purchased', 'silver_purchased', 'bronze_purchased'];

            foreach ($purchaseTiers as $tier) {
                $groupId = $this->getGroupIdByName("{$fromCampaign}_{$tier}");
                if (!$groupId)
                    continue;

                $subscribers = $this->mailerLiteInstance->getGroupSubscribers($groupId);

                foreach ($subscribers as $subscriber) {
                    $results['processed']++;

                    try {
                        // Wait for MailerLite to process tier updates
                        $this->mailerLiteInstance->verifyTierUpdate(
                            $subscriber['id'],
                            $tier
                        );

                        $this->processSubscriberTransition(
                            $subscriber,
                            $currentCampaign,
                            $nextCampaign
                        );

                        $results['success']++;
                    } catch (Exception $e) {
                        $results['failed']++;
                        $results['errors'][] = [
                            'email' => $subscriber['email'],
                            'error' => $e->getMessage()
                        ];
                    }
                }
            }

            return $results;
        } catch (Exception $e) {
            throw new Exception("Campaign transition failed: " . $e->getMessage());
        }
    }

    // In class.em_sync.php, add:

    private function processSubscriberTransition(array $subscriber, array $currentCampaign, array $nextCampaign): void
    {
        try {
            $this->dbManager->beginTransactionWithRetry(self::TRANSACTION_TIMEOUT);

            $transitionId = $this->dbManager->logTransition(
                $subscriber['email'],
                $currentCampaign['name'],
                $nextCampaign['name'],
                $subscriber['tier']
            );

            // Verify and wait for MailerLite workflow
            $verificationResult = $this->verifyPurchaseTier($subscriber['id'], $transitionId);
            if (!$verificationResult['verified']) {
                throw new Exception($verificationResult['message']);
            }

            $currentTier = $verificationResult['tier'];

            // Get next tier
            $nextTier = $this->campaign_manager->getNextCampaignTier($currentTier);
            if (!$nextTier) {
                throw new Exception("Could not determine next tier for {$currentTier}");
            }

            // Get destination group
            $nextGroupId = $this->getGroupIdByName("{$nextCampaign['name']}_{$nextTier}");
            if (!$nextGroupId) {
                throw new Exception("Destination group not found for tier: {$nextTier}");
            }

            // Process group changes
            $this->processGroupChanges($subscriber['id'], $nextGroupId);

            // Update subscriber record
            $this->dbManager->updateSubscriberTier(
                $subscriber['email'],
                $nextCampaign['name'],
                $nextTier
            );

            // Complete transition
            $this->dbManager->completeTransition($transitionId, [
                'to_tier' => $nextTier,
                'status' => 'completed',
                'completed_at' => current_time('mysql')
            ]);

            $this->dbManager->commit();
        } catch (Exception $e) {
            $this->dbManager->rollback();
            $this->dbManager->updateTransition($transitionId, [
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function verifyPurchaseTier(string $subscriberId, int $transitionId): array
    {
        $maxAttempts = 5;
        $attempts = 0;
        $delay = 30;

        while ($attempts < $maxAttempts) {
            $subscriberGroups = $this->mailerLiteInstance->getSubscriberGroups($subscriberId);
            foreach ($subscriberGroups as $group) {
                if (strpos(strtoupper($group['name']), '_PURCHASED') !== false) {
                    return [
                        'verified' => true,
                        'tier' => str_replace('_PURCHASED', '', $group['name']),
                        'message' => 'Purchase tier verified'
                    ];
                }
            }

            $attempts++;
            $this->dbManager->updateTransitionAttempt($transitionId, $attempts);
            if ($attempts < $maxAttempts) {
                sleep($delay);
            }
        }

        return [
            'verified' => false,
            'tier' => null,
            'message' => "Purchase tier not verified after {$maxAttempts} attempts"
        ];
    }

    private function processGroupChanges(string $subscriberId, string $newGroupId): void
    {
        // Remove from current groups
        $currentGroups = $this->mailerLiteInstance->getSubscriberGroups($subscriberId);
        foreach ($currentGroups as $group) {
            $this->mailerLiteInstance->removeSubscriberFromGroup($subscriberId, $group['id']);
        }

        // Add to new group
        $this->mailerLiteInstance->addSubscriberToGroup($subscriberId, $newGroupId);
    }

    private function queueTransitions(array $subscribers, string $fromCampaign, string $toCampaign): void
    {
        $batchSize = 50;
        $batches = array_chunk($subscribers, $batchSize);

        foreach ($batches as $index => $batch) {
            wp_schedule_single_event(
                time() + (300 * ($index + 1)), // Stagger batches 5 minutes apart
                'bema_process_transition_batch',
                [$batch, $fromCampaign, $toCampaign]
            );
        }
    }

    private function getCurrentSubscriberTier(string $email, string $campaign): ?string
    {
        $subscriber = $this->dbManager->getSubscriberByEmail($email);
        if (!$subscriber || $subscriber['campaign'] !== $campaign) {
            return null;
        }
        return $subscriber['tier'];
    }

    /**
     * Handle tier transition for subscriber
     */
    private function handleTierTransition(array $subscriber, array $campaign, string $currentGroup): void
    {
        try {
            // Get campaign groups
            $campaignGroups = $this->campaign_manager->get_campaign_groups($campaign['name']);
            if (empty($campaignGroups)) {
                throw new Exception("No groups defined for campaign: {$campaign['name']}");
            }

            // Check purchase status
            $productId = $this->getEDDProductForCampaign($campaign['name']);
            if (!$productId) {
                throw new Exception("No product found for campaign: {$campaign['name']}");
            }

            $hasPurchased = $this->eddInstance->hasUserPurchasedProduct(
                $subscriber['id'],
                $productId
            );

            // Get next tier based on current group and purchase status
            $nextGroup = $this->campaign_manager->get_next_group($currentGroup, $hasPurchased);
            if (!$nextGroup || !isset($campaignGroups[$nextGroup])) {
                return; // No transition needed or invalid next group
            }

            // Validate the transition
            if (!$this->campaign_manager->validate_tier_transition($currentGroup, $nextGroup, $hasPurchased)) {
                $this->logger->warning('Invalid tier transition attempted', [
                    'subscriber' => $subscriber['email'],
                    'from_group' => $currentGroup,
                    'to_group' => $nextGroup,
                    'has_purchased' => $hasPurchased
                ]);
                return;
            }

            $nextGroupId = $this->getGroupIdByName($campaignGroups[$nextGroup]);
            if (!$nextGroupId) {
                throw new Exception("Could not find group ID for: {$campaignGroups[$nextGroup]}");
            }

            $this->logger->debug('Handling tier transition', [
                'subscriber' => $subscriber['email'],
                'campaign' => $campaign['name'],
                'from_group' => $currentGroup,
                'to_group' => $nextGroup,
                'has_purchased' => $hasPurchased
            ]);

            // Remove from current group
            if (!empty($subscriber['id'])) {
                $this->mailerLiteInstance->removeSubscriberFromGroup(
                    $subscriber['id'],
                    $subscriber['group_id']
                );

                // Add to next group
                $this->mailerLiteInstance->addSubscriberToGroup(
                    $subscriber['id'],
                    $nextGroupId
                );
            }

            // Update local database
            $this->dbManager->updateSubscriberTier(
                $subscriber['email'],
                $campaign['name'],
                $nextGroup
            );

            // Update group mapping
            $this->dbManager->updateSubscriberGroup(
                $subscriber['email'],
                $nextGroupId,
                $campaign['name']
            );

            $this->logger->info('Tier transition completed', [
                'email' => $subscriber['email'],
                'campaign' => $campaign['name'],
                'from_group' => $currentGroup,
                'to_group' => $nextGroup,
                'has_purchased' => $hasPurchased
            ]);
        } catch (Exception $e) {
            $this->logger->error('Tier transition failed', [
                'subscriber' => $subscriber['email'],
                'campaign' => $campaign['name'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Get MailerLite group ID by name
     */
    private function getGroupIdByName(string $groupName): ?string
    {
        try {
            if (empty($groupName)) {
                $this->logger->warning('Empty group name provided to getGroupIdByName');
                return null;
            }

            $groups = $this->mailerLiteInstance->getGroups();
            if (!is_array($groups)) {
                $this->logger->error('Invalid groups data received from MailerLite', [
                    'type' => gettype($groups)
                ]);
                return null;
            }

            foreach ($groups as $group) {
                if (!is_array($group) || !isset($group['name'], $group['id'])) {
                    $this->logger->warning('Invalid group data structure', [
                        'group' => $group
                    ]);
                    continue;
                }

                if ($group['name'] === $groupName) {
                    $this->logger->debug('Group ID found', [
                        'group_name' => $groupName,
                        'group_id' => $group['id']
                    ]);
                    return $group['id'];
                }
            }

            $this->logger->warning('Group not found', [
                'group_name' => $groupName,
                'available_groups' => array_column($groups, 'name')
            ]);
            return null;
        } catch (Exception $e) {
            $this->logger->error('Error getting group ID by name', [
                'group_name' => $groupName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Get current group for subscriber
     * @param string $subscriberId
     * @return string|null
     */
    private function getCurrentGroup(string $subscriberId): ?string
    {
        try {
            $subscriberGroups = $this->mailerLiteInstance->getSubscriberGroups($subscriberId);

            if (!empty($subscriberGroups)) {
                return $subscriberGroups[0]['name'];
            }
            return null;
        } catch (Exception $e) {
            $this->logger->error('Failed to get current group', [
                'subscriber_id' => $subscriberId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Add error to queue for retry
     */
    private function addToErrorQueue(array $error): void
    {
        try {
            $errorQueue = get_option('bema_sync_errors', []);
            array_unshift($errorQueue, [
                'timestamp' => current_time('mysql'),
                'email' => $error['email'] ?? null,
                'campaign' => $error['campaign'] ?? null,
                'error' => $error['error'],
                'retry_count' => 0,
                'last_attempt' => time()
            ]);

            // Keep only last 100 errors
            $errorQueue = array_slice($errorQueue, 0, 100);
            update_option('bema_sync_errors', $errorQueue, false);

            $this->logger->debug('Error added to queue', [
                'error' => $error
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to add error to queue', [
                'error' => $e->getMessage(),
                'original_error' => $error,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Process retry queue
     */
    private function processRetryQueue(): void
    {
        try {
            $errorQueue = get_option('bema_sync_errors', []);
            $updatedQueue = [];
            $maxRetries = 3;
            $retryDelay = 300; // 5 minutes

            foreach ($errorQueue as $error) {
                // Skip if not ready for retry
                if (time() - $error['last_attempt'] < $retryDelay) {
                    $updatedQueue[] = $error;
                    continue;
                }

                // Skip if max retries reached
                if ($error['retry_count'] >= $maxRetries) {
                    continue;
                }

                try {
                    // Attempt retry
                    $this->processSingleRetry($error);
                    // If successful, don't add back to queue
                } catch (Exception $e) {
                    // Update retry count and add back to queue
                    $error['retry_count']++;
                    $error['last_attempt'] = time();
                    $error['last_error'] = $e->getMessage();
                    $updatedQueue[] = $error;
                }
            }

            update_option('bema_sync_errors', $updatedQueue, false);
        } catch (Exception $e) {
            $this->logger->error('Failed to process retry queue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function processSingleRetry(array $error): void
    {
        if (empty($error['email']) || empty($error['campaign'])) {
            return;
        }

        // Get campaign details
        $campaign = $this->campaign_manager->get_campaign_details($error['campaign']);
        if (!$campaign) {
            return;
        }

        // Get subscriber details
        $subscriber = $this->mailerLiteInstance->getSubscribers([
            'email' => $error['email'],
            'limit' => 1
        ]);

        if (empty($subscriber)) {
            return;
        }

        // Process single subscriber
        $this->processBatchWithTransaction([$subscriber[0]], $campaign, $subscriber[0]['group_type'] ?? 'unknown');
    }

    /**
     * Update sync progress
     */
    private function updateProgress(array $data): void
    {
        try {
            $current = $this->getCurrentProgress();
            $updated = array_merge($current, $data);
            update_option('bema_sync_status', $updated, false);

            $this->logger->debug('Progress updated', $updated);
        } catch (Exception $e) {
            $this->logger->error('Failed to update progress', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Get current sync progress
     */
    public function getCurrentProgress(): array
    {
        return get_option('bema_sync_status', [
            'total' => 0,
            'processed' => 0,
            'failed' => 0,
            'status' => 'idle',
            'current_campaign' => null,
            'current_group' => null,
            'memory_usage' => size_format(memory_get_usage(true)),
            'peak_memory' => size_format(memory_get_peak_usage(true))
        ]);
    }

    private function handleSyncError(array $batch = null, Exception $error = null): void
    {
        if ($batch && $error) {
            $this->logger->error('Sync error occurred', [
                'batch_size' => count($batch['subscribers'] ?? []),
                'campaign' => $batch['campaign']['name'] ?? 'unknown',
                'error' => $error->getMessage(),
                'trace' => $error->getTraceAsString()
            ]);
        }
    }

    private function handleBatchError(array $batch, Exception $e): void
    {
        $errorData = [
            'batch_size' => count($batch),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => time()
        ];

        $this->logger->error('Batch processing failed', $errorData);

        $this->updateProgress([
            'failed' => $this->getCurrentProgress()['failed'] + count($batch),
            'last_error' => $e->getMessage()
        ]);

        if ($e instanceof RetryableException && $e->isRetryable()) {
            $this->queueManager->addToQueue($batch, self::PRIORITY_HIGH);
            $this->updateProgress(['retried' => $this->getCurrentProgress()['retried'] + count($batch)]);
        }

        $this->addToErrorQueue($errorData);
    }

    private function canContinueProcessing($startTime): bool
    {
        $timeElapsed = time() - $startTime;
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimitInBytes();
        $memoryThresholdBytes = $memoryLimit * $this->memoryThreshold;

        if ($timeElapsed >= $this->maxProcessingTime) {
            $this->logger->warning('Time limit reached', [
                'elapsed' => $timeElapsed,
                'limit' => $this->maxProcessingTime
            ]);
            return false;
        }

        if ($memoryUsage >= $memoryThresholdBytes) {
            $this->logger->warning('Memory threshold reached', [
                'usage' => $this->formatBytes($memoryUsage),
                'limit' => $this->formatBytes($memoryThresholdBytes)
            ]);
            return false;
        }

        return true;
    }

    private function manageMemory(): void
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimitInBytes();
        $peakMemory = memory_get_peak_usage(true);

        if ($memoryUsage > ($memoryLimit * $this->memoryThreshold)) {
            $this->logger->warning('Memory cleanup triggered', [
                'usage' => $this->formatBytes($memoryUsage),
                'peak' => $this->formatBytes($peakMemory),
                'limit' => $this->formatBytes($memoryLimit)
            ]);

            if (function_exists('gc_collect_cycles')) {
                $collected = gc_collect_cycles();
                $this->logger->info('Garbage collection completed', [
                    'collected' => $collected
                ]);
            }

            $this->cache->flush();
        }

        $this->updateProgress([
            'memory_usage' => $this->formatBytes($memoryUsage),
            'peak_memory' => $this->formatBytes($peakMemory)
        ]);
    }

    /**
     * Get available groups from MailerLite
     * @return array
     */
    public function getGroups(): array
    {
        try {
            $this->logger->debug('Getting MailerLite groups');

            $groups = $this->mailerLiteInstance->getGroups();

            $this->logger->info('MailerLite groups retrieved successfully', [
                'count' => count($groups)
            ]);

            return $groups;
        } catch (Exception $e) {
            $this->logger->error('Failed to get groups from MailerLite', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Get MailerLite groups with campaign-specific caching
     * @param string $campaign Campaign code
     * @return array
     */
    private function getMailerLiteGroups(string $campaign): array
    {
        try {
            $cacheKey = 'ml_groups_' . md5($campaign);
            $cachedGroups = $this->cache->get($cacheKey);

            if ($cachedGroups !== null) {
                $this->logger->debug('Using cached groups', [
                    'campaign' => $campaign
                ]);
                return $cachedGroups;
            }

            $groups = $this->mailerLiteInstance->getGroups();

            // Filter groups relevant to this campaign if needed
            if ($this->campaign_manager) {
                $campaignGroups = $this->campaign_manager->get_campaign_groups($campaign);
                if ($campaignGroups) {
                    $groups = array_filter($groups, function ($group) use ($campaignGroups) {
                        return in_array($group['name'], $campaignGroups);
                    });
                }
            }

            $this->cache->set($cacheKey, $groups, self::CACHE_TTL);

            $this->logger->debug('Groups fetched and cached', [
                'campaign' => $campaign,
                'count' => count($groups)
            ]);

            return $groups;
        } catch (Exception $e) {
            $this->logger->error('Failed to get campaign groups', [
                'campaign' => $campaign,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    private function errorHandler($errno, $errstr, $errfile, $errline): bool
    {
        $this->logger->error('PHP Error', [
            'errno' => $errno,
            'error' => $errstr,
            'file' => $errfile,
            'line' => $errline
        ]);
        return true;
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->logger->critical('Fatal Error', [
                'error' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line']
            ]);

            $this->updateProgress([
                'status' => 'failed',
                'error' => 'Fatal error: ' . $error['message']
            ]);
        }
    }

    private function logError(string $message, Exception $exception, array $context = []): void
    {
        $errorData = [
            'message' => $message,
            'error' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ] + $context;

        $this->logger->error($message, $errorData);
        $this->addToErrorQueue($errorData);
    }

    public function getErrorLogs(): array
    {
        return $this->errorQueue;
    }

    /**
     * Get MailerLite instance
     * @return \Bema\Providers\MailerLite|null
     */
    public function getMailerLiteInstance()
    {
        return $this->mailerLiteInstance;
    }

    public function clearErrorLogs(): void
    {
        try {
            $this->errorQueue = [];
            $result = update_option(self::ERROR_LOG_KEY, [], false);
            if (!$result) {
                $this->logger->warning('Failed to clear error logs in database');
            } else {
                $this->logger->info('Error logs cleared successfully');
            }
        } catch (Exception $e) {
            $this->logger->error('Error clearing error logs', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function generateCacheKey($data): string
    {
        try {
            if ($data === null) {
                $this->logger->warning('Null data provided to generateCacheKey');
                return 'sync_null_' . time();
            }

            $serialized = serialize($data);
            if ($serialized === false) {
                $this->logger->warning('Failed to serialize data for cache key', [
                    'data_type' => gettype($data)
                ]);
                return 'sync_error_' . time();
            }

            return 'sync_' . md5($serialized);
        } catch (Exception $e) {
            $this->logger->error('Error generating cache key', [
                'error' => $e->getMessage(),
                'data_type' => gettype($data)
            ]);
            return 'sync_fallback_' . time();
        }
    }

    private function getMemoryLimitInBytes(string $memoryLimit = null): int
    {
        try {
            $memoryLimit = $memoryLimit ?? ini_get('memory_limit');

            if ($memoryLimit === false) {
                $this->logger->warning('Failed to get memory limit from ini_get');
                return 128 * 1024 * 1024; // 128MB default
            }

            if ($memoryLimit === '-1') {
                return PHP_INT_MAX;
            }

            preg_match('/^(\d+)(.)$/', $memoryLimit, $matches);
            if (!$matches) {
                $this->logger->warning('Invalid memory limit format', [
                    'memory_limit' => $memoryLimit
                ]);
                return 128 * 1024 * 1024; // 128MB default
            }

            $value = (int) $matches[1];
            if ($value <= 0) {
                $this->logger->warning('Invalid memory limit value', [
                    'value' => $value,
                    'memory_limit' => $memoryLimit
                ]);
                return 128 * 1024 * 1024; // 128MB default
            }

            switch (strtoupper($matches[2])) {
                case 'G':
                    $value *= 1024;
                case 'M':
                    $value *= 1024;
                case 'K':
                    $value *= 1024;
                    break;
                default:
                    $this->logger->warning('Unknown memory limit unit', [
                        'unit' => $matches[2],
                        'memory_limit' => $memoryLimit
                    ]);
            }

            return $value;
        } catch (Exception $e) {
            $this->logger->error('Error parsing memory limit', [
                'memory_limit' => $memoryLimit ?? 'null',
                'error' => $e->getMessage()
            ]);
            return 128 * 1024 * 1024; // 128MB default
        }
    }

    private function formatBytes($bytes): string
    {
        try {
            if (!is_numeric($bytes)) {
                $this->logger->warning('Non-numeric value provided to formatBytes', [
                    'value' => $bytes,
                    'type' => gettype($bytes)
                ]);
                return '0 B';
            }

            $units = ['B', 'KB', 'MB', 'GB'];
            $bytes = max($bytes, 0);

            if ($bytes === 0) {
                return '0 B';
            }

            $pow = floor(log($bytes) / log(1024));
            $pow = min($pow, count($units) - 1);
            $pow = max($pow, 0);

            $value = $bytes / (1024 ** $pow);
            return round($value, 2) . ' ' . $units[$pow];
        } catch (Exception $e) {
            $this->logger->error('Error formatting bytes', [
                'bytes' => $bytes,
                'error' => $e->getMessage()
            ]);
            return '0 B';
        }
    }

    public function validateAPIConnections(): array
    {
        return $this->validateConnections();
    }

    private function validateConnections(): array
    {
        $results = [
            'status' => 'success',
            'errors' => []
        ];

        try {
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $eddActive = \is_plugin_active('easy-digital-downloads/easy-digital-downloads.php') ||
                \is_plugin_active('easy-digital-downloads-pro/easy-digital-downloads.php');

            // Get settings safely
            $settings = $this->settings ? $this->settings->get_settings() : [];
            $mailerliteKey = $settings['api']['mailerlite_api_key'] ?? '';
            $eddKey = $settings['api']['edd_api_key'] ?? '';

            // Validate MailerLite connection
            if ($this->mailerLiteInstance && !empty($mailerliteKey)) {
                try {
                    if (!$this->mailerLiteInstance->validateConnection()) {
                        $results['errors'][] = 'MailerLite connection failed';
                    }
                } catch (Exception $e) {
                    $results['errors'][] = 'MailerLite: ' . $e->getMessage();
                }
            }

            // Validate EDD connection
            if ($eddActive && $this->eddInstance && !empty($eddKey)) {
                try {
                    if (!$this->eddInstance->validateConnection()) {
                        $results['errors'][] = 'EDD connection failed';
                    }
                } catch (Exception $e) {
                    $results['errors'][] = 'EDD: ' . $e->getMessage();
                }
            }

            if (!empty($results['errors'])) {
                $results['status'] = 'error';
            }

            return $results;
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'errors' => ['Validation error: ' . $e->getMessage()]
            ];
        }
    }

    private function handleFormSubmission(string $email, ?string $firstName, ?string $lastName): ?string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Sync_Exception('Invalid email format');
        }

        try {
            if ($this->dbManager->emailExists($email)) {
                return "Subscriber already exists";
            }

            $subscriberData = [
                'tier' => 'unassigned',
                'purchase_indicator' => 0,
                'campaign' => '',
                'mailerlite_group_id' => '0',
                'subscriber' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'source' => 'Bema Store'
            ];

            $this->dbManager->beginTransactionWithRetry();
            $this->dbManager->addSubscriber($subscriberData);

            $this->mailerLiteInstance->addOrUpdateSubscriber([
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName
            ]);

            $this->dbManager->commit();
            return null;
        } catch (Exception $e) {
            $this->dbManager->rollback();
            $this->logError('Form submission sync failed', $e);
            throw $e;
        }
    }

    private function prepareCampaignsForSync(array $campaignData): array
    {
        $preparedCampaigns = [];
        foreach ($campaignData as $campaign) {
            if (!isset($campaign['id']) || !isset($campaign['name'])) {
                $this->logger->warning('Invalid campaign data', ['campaign' => $campaign]);
                continue;
            }

            $preparedCampaigns[] = [
                'id' => $campaign['id'],
                'name' => $campaign['name'],
                'subscribers' => [],
                'status' => 'pending'
            ];
        }
        return $preparedCampaigns;
    }

    private function getSubscribersForCampaign(array $campaign): array
    {
        try {
            return $this->mailerLiteInstance->getSubscribers([
                'campaign_id' => $campaign['id']
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch subscribers', [
                'campaign' => $campaign['name'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function handleCampaignError($campaign, Exception $e): void
    {
        $this->logger->error('Campaign processing failed', [
            'campaign' => $campaign['name'],
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->updateProgress([
            'failed' => $this->getCurrentProgress()['failed'] + 1,
            'last_error' => $e->getMessage()
        ]);

        if ($e instanceof RetryableException && $e->isRetryable()) {
            $this->queueManager->addToQueue([$campaign], self::PRIORITY_HIGH);
        }
    }

    private function processRemainingQueue($startTime): void
    {
        while ($batch = $this->queueManager->getNextBatch($this->batchSize)) {
            if (!$this->canContinueProcessing($startTime)) {
                $this->logger->warning('Processing time limit reached');
                break;
            }

            try {
                $this->processBatch($batch);
            } catch (Exception $e) {
                $this->handleBatchError($batch, $e);
            }

            $this->manageMemory();
        }
    }

    private function processBatch(array $batch): void
    {
        foreach ($batch as $item) {
            try {
                $this->processSingleItem($item);
            } catch (Exception $e) {
                $this->handleBatchError([$item], $e);
            }
        }
    }

    private function processSingleItem(array $item): void
    {
        $cacheKey = $this->generateCacheKey($item);

        if ($cached = $this->cache->get($cacheKey)) {
            return;
        }

        try {
            $this->dbManager->beginTransactionWithRetry(self::TRANSACTION_TIMEOUT);

            $subscriber = isset($item['subscriber']) ? $item['subscriber'] : $item;
            $campaign = isset($item['campaign']) ? $item['campaign'] : null;

            if (!$campaign || !isset($subscriber['email'])) {
                throw new Sync_Exception('Invalid item data');
            }

            $currentStatus = $this->dbManager->getSubscriberByEmail($subscriber['email']);
            $purchaseData = $this->getEDDPurchaseData($campaign['name']);
            $mlGroups = $this->getMailerLiteGroups($campaign['name']);

            $tierInfo = $this->determineSubscriberTier(
                $subscriber['email'],
                $currentStatus,
                $purchaseData,
                $mlGroups
            );

            if ($this->hasTierChanged($currentStatus, $tierInfo)) {
                $this->updateSubscriberTier($subscriber['email'], $tierInfo, $campaign['name']);
                if (isset($subscriber['id'])) {
                    $this->updateMailerLiteGroups($subscriber['id'], $tierInfo['tier'], $mlGroups);
                }
            }

            $this->dbManager->commit();
            $this->cache->set($cacheKey, true, self::CACHE_TTL);
        } catch (Exception $e) {
            $this->dbManager->rollback();
            throw $e;
        }
    }

    private function getEDDPurchaseData(string $campaign): array
    {
        $cacheKey = 'edd_purchases_' . md5($campaign);
        return $this->cache->get($cacheKey) ?? $this->eddInstance->getSales($campaign);
    }

    private function determineSubscriberTier(string $email, ?array $currentStatus, array $eddData, array $mlGroups): array
    {
        $tierInfo = [
            'tier' => 'unassigned',
            'purchase_indicator' => 0,
            'group_id' => null
        ];

        $hasPurchased = in_array($email, $eddData['edd_emails'] ?? []);
        $currentTier = $currentStatus['tier'] ?? 'unassigned';

        switch ($currentTier) {
            case 'unassigned':
                $tierInfo['tier'] = $hasPurchased ? 'gold_purchased' : 'opt-in';
                break;
            case 'opt-in':
                $tierInfo['tier'] = $hasPurchased ? 'gold_purchased' : 'silver';
                break;
            case 'gold':
                $tierInfo['tier'] = $hasPurchased ? 'gold_purchased' : 'silver';
                break;
            case 'silver':
                $tierInfo['tier'] = $hasPurchased ? 'silver_purchased' : 'bronze';
                break;
            case 'bronze':
                $tierInfo['tier'] = $hasPurchased ? 'bronze_purchased' : 'wood';
                break;
            default:
                $tierInfo['tier'] = $currentTier;
        }

        $tierInfo['purchase_indicator'] = $hasPurchased ? 1 : 0;
        $tierInfo['group_id'] = $mlGroups[$tierInfo['tier']] ?? null;

        return $tierInfo;
    }

    private function hasTierChanged(?array $currentData, array $newTierInfo): bool
    {
        return !isset($currentData['tier']) ||
            $currentData['tier'] !== $newTierInfo['tier'] ||
            ($currentData['purchase_indicator'] ?? 0) !== $newTierInfo['purchase_indicator'];
    }

    private function updateSubscriberTier(string $email, array $tierInfo, string $campaign): void
    {
        $subscriber = $this->dbManager->getSubscriberByEmail($email);
        if (!$subscriber) {
            throw new Sync_Exception("Subscriber not found: $email");
        }

        $data = [
            'tier' => $tierInfo['tier'],
            'purchase_indicator' => $tierInfo['purchase_indicator'],
            'campaign' => $campaign,
            'mailerlite_group_id' => $tierInfo['group_id'],
            'date_added' => current_time('mysql')
        ];

        $this->dbManager->updateSubscriber($subscriber['bema_id'], $data);
    }

    private function updateMailerLiteGroups(string $subscriberId, string $tier, array $mlGroups): void
    {
        if (!isset($mlGroups[$tier])) {
            throw new Sync_Exception("Invalid tier group: $tier");
        }

        $newGroupId = $mlGroups[$tier];

        foreach ($mlGroups as $groupId) {
            if ($groupId !== $newGroupId) {
                $this->mailerLiteInstance->removeSubscriberFromGroup($subscriberId, $groupId);
            }
        }

        $this->mailerLiteInstance->addSubscriberToGroup($subscriberId, $newGroupId);
    }

    /**
     * Updates a subscriber's custom field with the provided field name and value.
     *
     * This function uses the dedicated getSubscriber() method to find the subscriber's
     * ID by email, then uses that ID to update a specific field via the MailerLite API.
     * This is the correct and reliable method to perform this operation.
     *
     * @param string $email The email of the subscriber to update.
     * @param string $field_name The name of the field to update (e.g., 'first_name').
     * @param string|int $field_value The new value for the field.
     * @return bool Returns true on success, false on failure.
     */
    public function updateSubscriberField(string $email, string|int $field_name, string|int $field_value): bool
    {
        try {
            $field_name = (string) $field_name;
            $field_name = strtolower($field_name);

            // Use the dedicated getSubscriber() function to get the full subscriber data by email.
            $subscriber = $this->mailerLiteInstance->getSubscriber($email);

            // Check if a subscriber was found. If not, the function will have already thrown an exception
            // or returned an empty array, depending on its implementation.
            if (!empty($subscriber)) {
                $subscriber_id = $subscriber['id'];

                // Prepare the data to be sent to MailerLite
                $subscriber_data = [
                    'fields' => [
                        $field_name => (string) $field_value // Ensure the value is a string as required by the API
                    ]
                ];

                $this->logger->debug('Subscriber found, attempting to update field', [
                    'email' => $email,
                    'id' => $subscriber_id,
                    'field_name' => $field_name
                ]);

                // Call the updateSubscriber method and check its return value for success
                $update_successful = $this->mailerLiteInstance->updateSubscriber($subscriber_id, $subscriber_data);

                if ($update_successful) {
                    $this->logger->info('Successfully updated subscriber field', [
                        'email' => $email,
                        'field_name' => $field_name
                    ]);
                    return true;
                } else {
                    $this->logger->error('UpdateSubscriber API call failed', [
                        'email' => $email,
                        'field_name' => $field_name
                    ]);
                    return false;
                }
            } else {
                // This block may be hit if getSubscriber() returns an empty array instead of throwing an exception.
                $this->logger->error('Subscriber not found for update', ['email' => $email]);
                return false;
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to update subscriber field status due to exception', [
                'email' => $email,
                'field_name' => $field_name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Re-throw the exception to allow the original caller to handle it
            throw $e;
        }
    }
    public function updatePurchaseStatus(string $email, string $campaign_code): void
    {
        try {
            // Get the field name for this campaign
            $field_name = $this->campaign_manager->get_purchase_field_name($campaign_code);

            if (!$field_name) {
                throw new Exception("Invalid campaign code: {$campaign_code}");
            }

            // Get EDD purchase data for this email
            $purchase_data = $this->eddInstance->getSales($campaign_code);

            // Check if this email has purchased
            $has_purchased = in_array($email, $purchase_data['edd_emails'] ?? []);

            // Update MailerLite field
            $subscriber_data = [
                'fields' => [
                    $field_name => $has_purchased ? 1 : 0
                ]
            ];

            // Get subscriber ID from MailerLite
            $subscribers = $this->mailerLiteInstance->getSubscribers(['email' => $email, 'limit' => 1]);

            if (!empty($subscribers[0]['id'])) {
                $this->mailerLiteInstance->updateSubscriber($subscribers[0]['id'], $subscriber_data);

                $this->logger->info('Purchase status updated', [
                    'email' => $email,
                    'campaign' => $campaign_code,
                    'field' => $field_name,
                    'purchased' => $has_purchased
                ]);
            }

            // Update local database
            $this->dbManager->updateSubscriberPurchaseStatus($email, $campaign_code, $has_purchased);
        } catch (Exception $e) {
            $this->logger->error('Failed to update purchase status', [
                'email' => $email,
                'campaign' => $campaign_code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function stopSync(): bool
    {
        try {
            $this->logger->info('Attempting to stop sync process');

            // Set stop flag immediately
            update_option('bema_sync_stop_flag', true, false);

            // Abort any pending API requests
            if ($this->mailerLiteInstance) {
                $this->mailerLiteInstance->abortPendingRequests();
            }

            // Clear all transients and locks
            delete_transient('bema_sync_lock');
            delete_transient('bema_current_sync');
            delete_transient('bema_api_request_lock');

            // Clear all scheduled events
            wp_clear_scheduled_hook('bema_crm_hourly_sync');
            wp_clear_scheduled_hook('bema_crm_daily_sync');
            wp_clear_scheduled_hook('bema_crm_custom_sync');

            // Force immediate status update
            $this->updateProgress([
                'status' => 'stopped',
                'end_time' => time()
            ]);

            // Clear any queued items
            if ($this->queueManager) {
                $this->queueManager->clearQueue();
            }

            // Force garbage collection
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $this->logger->info('Sync stopped by user');
            $this->logger->info('Sync process stopped successfully');

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to stop sync', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    private function shouldStopSync(): bool
    {
        $stopFlag = get_option('bema_sync_stop_flag');
        if ($stopFlag) {
            $this->logger->warning('Stop flag detected - halting operation');
            $this->updateProgress([
                'status' => 'stopped',
                'end_time' => time()
            ]);
            return true;
        }
        return false;
    }

    private function acquireSyncLock(): bool
    {
        $lock_acquired = set_transient('bema_sync_lock', time(), 3600); // 1 hour timeout
        if (!$lock_acquired) {
            $this->logger->error('Failed to acquire sync lock');
            return false;
        }
        return true;
    }

    private function releaseSyncLock(): void
    {
        try {
            $result = delete_transient('bema_sync_lock');
            if (!$result) {
                $this->logger->warning('Failed to release sync lock');
            } else {
                $this->logger->debug('Sync lock released successfully');
            }
        } catch (Exception $e) {
            $this->logger->error('Error releasing sync lock', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function processPurchaseUpdates(array $subscribers, string $campaign_code): void
    {
        $batch_size = 100; // Process in smaller batches
        $batches = array_chunk($subscribers, $batch_size);

        foreach ($batches as $batch) {
            try {
                $this->dbManager->beginTransactionWithRetry(self::TRANSACTION_TIMEOUT);

                foreach ($batch as $subscriber) {
                    $this->updatePurchaseStatus($subscriber['email'], $campaign_code);

                    // Add a small delay to prevent rate limiting
                    usleep(100000); // 100ms delay
                }

                $this->dbManager->commit();

                $this->logger->info('Batch purchase updates completed', [
                    'campaign' => $campaign_code,
                    'batch_size' => count($batch)
                ]);
            } catch (Exception $e) {
                $this->dbManager->rollback();
                $this->logger->error('Batch purchase updates failed', [
                    'campaign' => $campaign_code,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }

            // Memory management
            $this->manageMemory();
        }
    }

    /**
     * Get subscriber status from MailerLite
     */
    public function getSubscriberStatus(string $email): array
    {
        try {
            $status = [
                'status' => 'unknown',
                'last_updated' => null,
                'groups' => []
            ];

            // Get status from MailerLite
            if ($this->mailerLiteInstance) {
                $subscribers = $this->mailerLiteInstance->getSubscribers([
                    'email' => $email,
                    'limit' => 1
                ]);

                if (!empty($subscribers[0])) {
                    $subscriber = $subscribers[0];
                    $status = [
                        'status' => $subscriber['status'] ?? 'unknown',
                        'last_updated' => $subscriber['updated_at'] ?? null,
                        'groups' => $subscriber['groups'] ?? []
                    ];
                }
            }

            return $status;
        } catch (Exception $e) {
            $this->logger->error('Failed to get subscriber status', [
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'status' => 'error',
                'last_updated' => null,
                'groups' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get purchase history from EDD
     */
    public function getEDDPurchaseHistory(string $email): array
    {
        try {
            $history = [
                'purchases' => [],
                'total_spent' => 0,
                'last_purchase' => null
            ];

            // Get purchase history from EDD
            if ($this->eddInstance) {
                $sales = $this->eddInstance->getSales(null);  // Get all sales

                if (!empty($sales['sales_data'])) {
                    $customerPurchases = array_filter($sales['sales_data'], function ($sale) use ($email) {
                        return strtolower($sale['email']) === strtolower($email);
                    });

                    if (!empty($customerPurchases)) {
                        $history['purchases'] = array_map(function ($purchase) {
                            return [
                                'id' => $purchase['transaction_id'],
                                'date' => $purchase['purchase_date'],
                                'amount' => $purchase['purchase_amount'],
                                'products' => $purchase['products']
                            ];
                        }, $customerPurchases);

                        // Calculate totals
                        $history['total_spent'] = array_sum(array_column($customerPurchases, 'purchase_amount'));
                        $history['last_purchase'] = max(array_column($customerPurchases, 'purchase_date'));
                    }
                }
            }

            return $history;
        } catch (Exception $e) {
            $this->logger->error('Failed to get EDD purchase history', [
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'purchases' => [],
                'total_spent' => 0,
                'last_purchase' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Synchronizes album and custom campaign data with MailerLite.
     *
     * Retrieves all albums and custom campaigns, merges them,
     * checks for their existence on MailerLite, creates drafts for missing ones,
     * and bulk upserts them into the local database.
     *
     * @return bool True if the sync process is successful, false otherwise.
     */
    public function sync_album_campaign_data(): bool
    {
        $this->logger->info('Starting album campaign data sync');

        try {
            // Fetch albums from local system (e.g., WordPress posts or DB table).
            $this->logger->debug('Fetching albums from local system');
            $albums = $this->utils->get_all_albums();
            $this->logger->info('Albums fetched from local system', [
                'album_count' => count($albums),
                'albums' => array_column($albums, 'album')
            ]);

            // Fetch custom campaigns stored in local DB.
            $this->logger->debug('Fetching custom campaigns from database');
            $custom_campaigns = $this->campaign_database->get_all_campaigns();
            $this->logger->info('Custom campaigns fetched from database', [
                'custom_campaign_count' => count($custom_campaigns),
                'campaigns' => array_column($custom_campaigns, 'campaign')
            ]);

            // Prepare a combined map of all campaign data.
            $this->logger->debug('Preparing combined campaign store');
            $campaign_store_map = $this->prepare_campaign_store($albums, $custom_campaigns);
            $this->logger->info('Campaign store prepared', [
                'total_campaigns' => count($campaign_store_map),
                'campaign_names' => array_keys($campaign_store_map)
            ]);

            // Retrieve all campaigns from MailerLite (name => id map).
            $this->logger->debug('Fetching campaigns from MailerLite API');
            $mailerlite_campaign_map = $this->mailerLiteInstance->get_campaigns_name_to_id_map();

            // Abort if MailerLite campaigns could not be retrieved.
            if (!is_array($mailerlite_campaign_map)) {
                $this->logger->error('Failed to retrieve MailerLite campaigns - invalid response type', [
                    'response_type' => gettype($mailerlite_campaign_map)
                ]);
                return false;
            }

            if (empty($mailerlite_campaign_map)) {
                $this->logger->warning('No campaigns found in MailerLite - this might be expected for new accounts');
            }

            $this->logger->info('MailerLite campaigns retrieved', [
                'mailerlite_campaign_count' => count($mailerlite_campaign_map),
                'mailerlite_campaigns' => array_keys($mailerlite_campaign_map)
            ]);

            // Check campaigns against MailerLite, create missing drafts, and collect data for DB.
            $this->logger->debug('Processing campaigns against MailerLite');
            $campaigns_to_upsert = $this->process_campaigns($campaign_store_map, $mailerlite_campaign_map);
            $this->logger->info('Campaign processing completed', [
                'campaigns_to_upsert_count' => count($campaigns_to_upsert),
                'campaigns_to_upsert' => array_column($campaigns_to_upsert, 'campaign')
            ]);

            // Bulk upsert the collected campaigns into the local database.
            if (!empty($campaigns_to_upsert)) {
                $this->logger->debug('Performing bulk upsert to local database');
                $upsert_result = $this->campaign_database->upsert_campaigns_bulk($campaigns_to_upsert);
                $this->logger->info('Campaign bulk upsert completed', [
                    'upsert_result' => $upsert_result,
                    'campaigns_upserted' => count($campaigns_to_upsert)
                ]);
            } else {
                $this->logger->info('No campaigns to upsert - all campaigns already synchronized');
            }

            $this->logger->info('Album campaign data sync completed successfully');
            return true;

        } catch (Exception $e) {
            $this->logger->error('Album campaign data sync failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Builds a campaign store from albums and custom campaigns.
     *
     * Ensures both sources (albums + custom) are represented in a unified array.
     *
     * @param array $albums            List of album records from local system.
     * @param array $custom_campaigns  List of custom campaigns from DB.
     * @return array                   Campaigns mapped by campaign name.
     */
    private function prepare_campaign_store(array $albums, array $custom_campaigns): array
    {
        $campaign_store_map = [];

        // Add custom campaigns into the store.
        foreach ($custom_campaigns as $campaign) {
            $campaign_store_map[$campaign['campaign']] = [
                'name' => $campaign['campaign'],
                'product_id' => $campaign['product_id'],
                'album' => $campaign['album'],
                'year' => $campaign['year'],
                'artist' => $campaign['artist'],
            ];
        }

        // Add album campaigns into the store.
        foreach ($albums as $album) {
            // Generate a standardized campaign name (e.g., "2024_Artist_Album").
            $campaign_name = $this->utils->get_campaign_group_name(
                $album['year'],
                $album['artist'],
                $album['album']
            );

            $campaign_store_map[$campaign_name] = [
                'name' => $campaign_name,
                'product_id' => $album['product_id'],
                'album' => $album['album'],
                'year' => $album['year'],
                'artist' => $album['artist'],
            ];
        }

        return $campaign_store_map;
    }

    /**
     * Checks campaigns against MailerLite and prepares data for DB upsert.
     *
     * - If campaign exists on MailerLite → add with ID.
     * - If campaign does not exist → create a draft, then add with new ID.
     *
     * @param array $campaign_store_map      Local prepared campaigns.
     * @param array $mailerlite_campaign_map MailerLite campaigns (name => id).
     * @return array                         List of campaigns ready for DB upsert.
     */
    private function process_campaigns(array $campaign_store_map, array $mailerlite_campaign_map): array
    {
        $campaigns_to_upsert = [];

        foreach ($campaign_store_map as $campaign) {
            // Check if campaign already exists on MailerLite.
            $campaign_id = $mailerlite_campaign_map[$campaign['name']] ?? null;

            if ($campaign_id) {
                // Campaign exists → add to upsert list.
                $campaigns_to_upsert[] = $this->format_campaign_for_upsert($campaign, $campaign_id);
            } else {
                // Campaign missing → create new draft on MailerLite.
                $campaign_id = $this->create_new_mailerlite_campaign($campaign);

                // If creation was successful → add to upsert list.
                if ($campaign_id) {
                    $campaigns_to_upsert[] = $this->format_campaign_for_upsert($campaign, $campaign_id);
                }
            }
        }

        return $campaigns_to_upsert;
    }

    /**
     * Creates a new draft campaign in MailerLite.
     *
     * @param array $campaign Campaign data (name, album, artist, etc.).
     * @return string|null    The created campaign ID, or null on failure.
     */
    private function create_new_mailerlite_campaign(array $campaign): ?string
    {
        // Prepare a subject line depending on whether it's an album campaign.
        $subject = isset($campaign['album'])
            ? 'Music album: ' . $campaign['album'] . ' by ' . $campaign['artist']
            : 'Custom campaign with no album';

        // Call MailerLite API to create a draft campaign.
        $response = $this->mailerLiteInstance->create_draft_campaign(
            $campaign['name'],
            'regular', // Campaign type.
            $subject
        );

        // Return the new campaign ID if available.
        return $response['id'] ?? null;
    }

    /**
     * Formats campaign data into the structure expected for DB upsert.
     *
     * @param array  $campaign    Campaign details from local system.
     * @param string $campaign_id The MailerLite campaign ID.
     * @return array              Normalized campaign data for DB.
     */
    private function format_campaign_for_upsert(array $campaign, string $campaign_id): array
    {
        return [
            'campaign' => strtoupper($campaign['name']), // Normalize name (uppercase).
            'id' => $campaign_id,
            'product_id' => $campaign['product_id'] ?? null,
        ];
    }
    /**
     * Synchronizes campaign purchase fields with MailerLite and the local database.
     *
     * This function orchestrates the entire synchronization process by:
     * 1. Generating a list of required campaign purchase field names.
     * 2. Creating any fields that are missing in MailerLite and preparing the data
     * for a database upsert.
     * 3. Upserting the collected field data (both existing and newly created) into the
     * local database.
     *
     * @return bool Returns true on successful completion of the synchronization.
     */
    public function sync_mailerlite_field_data(): bool
    {
        $this->logger->info('Starting MailerLite field data sync');

        try {
            // Generate the list of required purchase field names from existing campaigns.
            $this->logger->debug('Generating required field names from campaigns');
            $required_fields = $this->get_required_fields();
            $this->logger->info('Required fields generated', [
                'required_field_count' => count($required_fields),
                'required_fields' => $required_fields
            ]);

            if (empty($required_fields)) {
                $this->logger->warning('No required fields found - no campaigns available or field generation failed');
                return true; // Not an error, just nothing to sync
            }

            // Process the fields, creating any missing ones in MailerLite and preparing
            // the data for the local database.
            $this->logger->debug('Processing fields and preparing for upsert');
            $fields_to_upsert = $this->prepare_field_data_for_upsert($required_fields);
            $this->logger->info('Field processing completed', [
                'fields_to_upsert_count' => count($fields_to_upsert),
                'fields_to_upsert' => array_column($fields_to_upsert, 'field_name')
            ]);

            if (empty($fields_to_upsert)) {
                $this->logger->warning('No fields to upsert - all fields may already exist or processing failed');
                return true;
            }

            // Perform a bulk upsert operation on the local database with the collected field data.
            $this->logger->debug('Performing bulk upsert to local database');
            $upsert_result = $this->field_database->upsert_fields_bulk($fields_to_upsert);
            $this->logger->info('Field bulk upsert completed', [
                'upsert_result' => $upsert_result,
                'fields_upserted' => count($fields_to_upsert)
            ]);

            $this->logger->info('MailerLite field data sync completed successfully');
            return true;

        } catch (Exception $e) {
            $this->logger->error('MailerLite field data sync failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Generates a list of required purchase field names based on existing campaigns.
     *
     * It fetches all campaign names and appends a '_purchase' suffix to each to
     * create the standard field name.
     *
     * @return array A list of required field names (e.g., ['CAMPAIGN_NAME_PURCHASE']).
     */
    private function get_required_fields(): array
    {
        // Fetch all campaign names from the utility service.
        $campaign_names = $this->utils->get_campaigns_names();

        // Get custom campaign names from the database
        $custom_campaigns = $this->campaign_database->get_all_campaign_names();

        $all_campaign_names = array_merge($campaign_names, $custom_campaigns);
        $all_campaign_names = array_unique($all_campaign_names);
        $all_campaign_names = array_values($all_campaign_names); // Reindex array

        $purchase_fields = [];

        // Iterate through campaign names and format them into the required field names.
        foreach ($all_campaign_names as $campaign_name) {
            $purchase_fields[] = strtoupper($campaign_name . '_purchase');
        }

        return $purchase_fields;
    }

    /**
     * Prepares a data array for a bulk database upsert by processing a list of required fields.
     *
     * This is the main orchestrator function. It fetches existing fields, checks each required
     * field for existence, creates it if necessary, and then formats the data for a database upsert.
     *
     * @param array $required_fields An array of field names that need to exist in MailerLite.
     * @return array An array of field data, ready for bulk upserting into the local database.
     */
    private function prepare_field_data_for_upsert(array $required_fields): array
    {

        $fields_to_upsert = [];

        // Fetch all MailerLite fields and create a quick-lookup map.
        $mailerlite_fields_map = $this->get_mailerlite_fields_map();

        // Loop through each required field to ensure it exists and collect its data.
        foreach ($required_fields as $field_name) {

            // Get the field ID, creating the field in MailerLite if it doesn't exist.
            $field_id = $this->get_or_create_field($field_name, $mailerlite_fields_map);

            // If a valid field ID was obtained, proceed to get campaign data and build the upsert array.
            if ($field_id) {
                $campaign_id = $this->get_campaign_id_for_field($field_name);
                if ($campaign_id) {
                    $fields_to_upsert[] = $this->build_field_data_array($field_id, $field_name, $campaign_id);
                }
            }
        }

        return $fields_to_upsert;
    }

    /**
     * Fetches all existing MailerLite fields and creates a hash map for efficient lookups.
     *
     * @return array A key-value map of MailerLite field names (normalized) to their IDs.
     */
    private function get_mailerlite_fields_map(): array
    {
        $mailerlite_fields = $this->mailerLiteInstance->getFields();
        $fields_map = [];

        // Normalize field names to uppercase for case-insensitive matching.
        foreach ($mailerlite_fields as $field) {
            $normalized_name = strtoupper($field['name']);
            $fields_map[$normalized_name] = $field['id'];
        }

        return $fields_map;
    }

    /**
     * Checks if a field exists in MailerLite and creates it if it's missing.
     *
     * @param string $field_name The name of the field to check or create.
     * @param array $fields_map A hash map of existing MailerLite fields.
     * @return string|null The ID of the existing or newly created field, or null on failure.
     */
    private function get_or_create_field(string $field_name, array $fields_map): ?string
    {
        // Check if the field already exists in the pre-fetched map.
        if (isset($fields_map[$field_name])) {
            return $fields_map[$field_name];
        }

        // Field is missing; create it in MailerLite with a 'number' type.
        $new_field = $this->mailerLiteInstance->createField($field_name, 'number');
        return $new_field['id'] ?? null;
    }

    /**
     * Retrieves the campaign ID associated with a given field name.
     *
     * @param string $field_name The name of the field.
     * @return string|null The ID of the associated campaign, or null if not found.
     */
    private function get_campaign_id_for_field(string $field_name): ?string
    {
        // Extract the campaign name from the field name using a utility method.
        $campaign_name = $this->utils->get_campaign_name_from_text($field_name);

        // Look up the campaign in the local database.
        $campaign = $this->campaign_database->get_campaign_by_name($campaign_name);

        return $campaign['id'] ?? null;
    }

    /**
     * Formats field data into an associative array for upserting.
     *
     * @param string $field_id The ID of the MailerLite field.
     * @param string $field_name The name of the MailerLite field.
     * @param string $campaign_id The ID of the associated campaign.
     * @return array The formatted data array.
     */
    private function build_field_data_array(string $field_id, string $field_name, string $campaign_id): array
    {
        // Return a structured array matching the local database schema.
        return [
            'id' => $field_id,
            'field_name' => $field_name,
            'campaign_id' => $campaign_id
        ];
    }

    /**
     * Synchronizes MailerLite groups with local campaign data.
     *
     * This function retrieves all campaign names, generates the corresponding
     * group names, checks for their existence in MailerLite, creates any missing
     * groups, and upserts the relevant group data into the local database.
     *
     * @return bool True on successful synchronization, false on failure.
     */
    /**
     * Synchronizes MailerLite group data with the local database.
     *
     * This function orchestrates the entire synchronization process, including:
     * 1. Fetching campaign names from a utility class.
     * 2. Generating all potential group names based on the campaigns.
     * 3. Fetching existing groups from the MailerLite API.
     * 4. Processing the fetched groups to identify matches and determine which groups need to be upserted.
     * 5. Creating any missing groups in MailerLite that exist in the campaign data but not on the platform.
     * 6. Performing a bulk upsert of all groups (existing and newly created) into the local database.
     * 7. Logging the entire process from start to finish.
     *
     * @return bool True on successful completion of the sync, false on failure.
     */
    public function sync_mailerlite_group_data(): bool
    {
        $this->logger->info('Starting MailerLite group data sync');

        try {
            // Fetch all campaign names from the utility class.
            $campaign_name_list = $this->fetch_campaign_names();
            // Generate a complete list of all expected group names from the campaigns.
            $all_campaign_group_names = $this->generate_all_campaign_group_names($campaign_name_list);

            // Fetch all existing groups from the MailerLite API.
            $mailerlite_group_data = $this->fetch_mailerlite_groups();
            $this->logger->info('MailerLite groups fetched', [
                'mailerlite_group_count' => count($mailerlite_group_data),
                'mailerlite_groups' => array_column($mailerlite_group_data, 'name')
            ]);

            // Process the existing MailerLite groups to find matches and prepare for upsert.
            $process_result = $this->process_mailerlite_groups($mailerlite_group_data, $all_campaign_group_names);
            $group_names_found_on_mailerlite = $process_result['found_names'];
            $groups_to_upsert = $process_result['groups_to_upsert'];

            $this->logger->info('Existing groups processed', [
                'groups_found' => count($group_names_found_on_mailerlite),
                'groups_found_names' => $group_names_found_on_mailerlite
            ]);

            // Determine which groups are in the campaign list but missing from MailerLite.
            $missing_groups = array_diff(
                array_map('strtoupper', $all_campaign_group_names),
                $group_names_found_on_mailerlite
            );

            if (!empty($missing_groups)) {
                $this->logger->info('Creating missing groups in MailerLite', [
                    'missing_group_count' => count($missing_groups),
                    'missing_groups' => $missing_groups
                ]);
                // Create the missing groups in MailerLite and get their data.
                $new_groups = $this->create_missing_groups($missing_groups);

                // Merge the newly created groups with the existing ones to be upserted.
                $groups_to_upsert = array_merge($groups_to_upsert, $new_groups);
            } else {
                $this->logger->info('No missing groups - all groups already exist in MailerLite');
            }

            // Upsert all identified groups into the local database if there are any to process.
            if (!empty($groups_to_upsert)) {
                $this->logger->debug('Performing bulk upsert to local database');
                $upsert_result = $this->group_database->upsert_groups_bulk($groups_to_upsert);
                $this->logger->info('Group bulk upsert completed', [
                    'upsert_result' => $upsert_result,
                    'groups_upserted' => count($groups_to_upsert),
                    'upserted_groups' => array_column($groups_to_upsert, 'group_name')
                ]);
            } else {
                $this->logger->info('No groups to upsert');
            }

            $this->logger->info('MailerLite group data sync completed successfully');
            return true;
        } catch (Exception $e) {
            // Log any exceptions that occur during the sync process.
            $this->logger->error('MailerLite group data sync failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Fetches campaign names from a utility class.
     *
     * @return array A list of campaign names.
     */
    private function fetch_campaign_names(): array
    {
        $this->logger->debug('Fetching campaign names from utils');
        $campaign_name_list = $this->campaign_database->get_all_campaign_names();
        $this->logger->info('Campaign names fetched', [
            'campaign_count' => count($campaign_name_list),
            'campaigns' => $campaign_name_list
        ]);
        return $campaign_name_list;
    }

    /**
     * Generates a list of all possible group names for all campaigns.
     *
     * @param array $campaign_name_list An array of campaign names.
     * @return array A consolidated list of all campaign group names.
     */
    private function generate_all_campaign_group_names(array $campaign_name_list): array
    {
        $this->logger->debug('Generating all campaign group names');
        $all_campaign_group_names = [];
        foreach ($campaign_name_list as $campaign_name) {
            // Get the specific groups for each campaign.
            $campaign_groups = $this->utils->get_campaign_group_names($campaign_name);
            // Merge them into a single list.
            $all_campaign_group_names = array_merge($all_campaign_group_names, $campaign_groups);
            $this->logger->debug('Generated groups for campaign', [
                'campaign' => $campaign_name,
                'groups' => $campaign_groups
            ]);
        }
        $this->logger->info('All campaign group names generated', [
            'total_group_names' => count($all_campaign_group_names),
            'group_names' => $all_campaign_group_names
        ]);
        return $all_campaign_group_names;
    }

    /**
     * Fetches all groups from the MailerLite API.
     *
     * @return array An array of group data from MailerLite.
     */
    private function fetch_mailerlite_groups(): array
    {
        $this->logger->debug('Fetching groups from MailerLite API');
        return $this->mailerLiteInstance->getGroups();
    }

    /**
     * Processes the groups fetched from MailerLite, identifying groups to upsert and those found.
     *
     * @param array $mailerlite_group_data The raw group data from the MailerLite API.
     * @param array $all_campaign_group_names A list of all expected group names.
     * @return array An associative array containing 'found_names' and 'groups_to_upsert'.
     */
    private function process_mailerlite_groups(array $mailerlite_group_data, array $all_campaign_group_names): array
    {
        $this->logger->debug('Processing existing MailerLite groups');
        // Convert all campaign group names to uppercase for case-insensitive matching.
        $all_upper = array_map('strtoupper', $all_campaign_group_names);
        $group_names_found_on_mailerlite = [];
        $groups_to_upsert = [];

        foreach ($mailerlite_group_data as $group) {
            $group_name_upper = strtoupper($group['name']);
            // Check if the group name matches one of the expected campaign group names.
            if (in_array($group_name_upper, $all_upper, true)) {
                // Extract the campaign name from the group name.
                $campaign_name = $this->utils->get_campaign_name_from_text($group['name']);
                // Get the campaign data from the local database.
                $campaign_data = $this->campaign_database->get_campaign_by_name($campaign_name);

                if (!$campaign_data || !isset($campaign_data['id'])) {
                    $this->logger->warning('Campaign not found for group', [
                        'group_name' => $group['name'],
                        'extracted_campaign_name' => $campaign_name
                    ]);
                    continue;
                }

                $campaign_id = $campaign_data['id'];

                // Record the found group's uppercase name and prepare it for upsert.
                $group_names_found_on_mailerlite[] = $group_name_upper;
                $groups_to_upsert[] = [
                    'id' => $group['id'],
                    'group_name' => $group['name'],
                    'campaign_id' => $campaign_id
                ];

                $this->logger->debug('Found matching group', [
                    'group_name' => $group['name'],
                    'group_id' => $group['id'],
                    'campaign_id' => $campaign_id
                ]);
            }
        }

        return [
            'found_names' => $group_names_found_on_mailerlite,
            'groups_to_upsert' => $groups_to_upsert
        ];
    }

    /**
     * Creates new groups in MailerLite for the provided list of missing group names.
     *
     * This method iterates through an array of group names, extracts campaign data,
     * and uses the MailerLite API to create each group. It logs the process and
     * handles cases where campaign data is not found or group creation fails.
     * The method returns an array of successfully created groups, formatted for local database upsert.
     *
     * @param array $missing_groups An array of group names that need to be created.
     * @return array An array of newly created group data, formatted for upserting.
     */
    private function create_missing_groups(array $missing_groups): array
    {
        $created_groups_to_upsert = [];

        // Loop through each group name that is missing from MailerLite.
        foreach ($missing_groups as $group_name) {
            $this->logger->debug('Attempting to create group in MailerLite', ['group_name' => $group_name]);

            // Extract the campaign name from the group name and retrieve its data from the database.
            $campaign_name = $this->utils->get_campaign_name_from_text($group_name);
            $campaign_data = $this->campaign_database->get_campaign_by_name($campaign_name);

            // Check if the campaign data was found.
            if (!isset($campaign_data['id'])) {
                // If the campaign isn't found, log a warning and skip to the next group.
                $this->logger->warning('Campaign details not found for group creation', [
                    'group_name' => $group_name,
                    'campaign_name' => $campaign_name
                ]);
                continue;
            }

            // Call the MailerLite API to create the new group.
            $new_group = $this->mailerLiteInstance->createGroup($group_name);
            $this->logger->debug('MailerLite API response for new group creation', ['response' => $new_group]);

            // Check if the API call was successful and returned a valid group ID.
            if ($new_group && isset($new_group['id'])) {
                $campaign_id = $campaign_data['id'];

                // Format the data for upserting into the local database.
                $created_groups_to_upsert[] = [
                    'id' => $new_group['id'],
                    'group_name' => $new_group['name'],
                    'campaign_id' => $campaign_id
                ];

                // Log a success message with details of the created group.
                $this->logger->info('Successfully created group', [
                    'group_name' => $new_group['name'],
                    'group_id' => $new_group['id'],
                    'campaign_id' => $campaign_id
                ]);
            } else {
                // If group creation failed, log an error.
                $this->logger->error('Failed to create group in MailerLite', [
                    'group_name' => $group_name,
                    'response' => $new_group
                ]);
            }
        }

        $this->logger->info('Finished processing all missing groups.');
        return $created_groups_to_upsert;
    }

    public function sync_subscribers(): int
    {
        $this->logger->info('Starting subscriber sync');

        try {
            $this->logger->debug('Fetching all subscribers from MailerLite API');
            $all_subscribers = $this->mailerLiteInstance->getSubscribers();
            $subscribers_count = count($all_subscribers);

            $this->logger->info('Subscribers fetched from MailerLite', [
                'subscriber_count' => $subscribers_count
            ]);

            if (empty($all_subscribers)) {
                $this->logger->warning('No subscribers found in MailerLite');
                return 0;
            }

            // Store all subscribers in the main table in a batch operation if possible
            $this->logger->debug('Syncing subscribers to local database');

            try {
                $sync_result = $this->subscribers_database->sync_subscribers($all_subscribers);
            } catch (Exception $db_e) {
                throw $db_e;
            }

            $this->logger->info('Subscriber sync to database completed', [
                'sync_result' => $sync_result,
                'subscribers_synced' => $subscribers_count
            ]);

            $this->logger->info('Subscriber sync completed successfully', [
                'total_subscribers' => $subscribers_count
            ]);

            return $subscribers_count;

        } catch (Exception $e) {
            $this->logger->error('Subscriber sync failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            return 0;
        }
    }

    public function sync_mailerlite_campaign_group_subscribers()
    {
        $this->logger->info('Starting campaign group subscriber sync');

        try {
            // Prepare data for batch upsert into the campaign_group_subscribers_database
            $campaign_subscribers_data = [];

            $this->logger->debug('Fetching all groups from local database');
            $campaign_group_list = $this->group_database->get_all_groups();
            $this->logger->info('Local groups fetched', [
                'group_count' => count($campaign_group_list),
                'groups' => array_column($campaign_group_list, 'group_name')
            ]);

            $this->logger->debug('Fetching MailerLite groups map');
            $mailerlite_groups_map = $this->mailerLiteInstance->getAllGroupsNameMap();
            $this->logger->info('MailerLite groups map fetched', [
                'mailerlite_groups_count' => count($mailerlite_groups_map)
            ]);

            foreach ($campaign_group_list as $group) {
                $this->logger->debug('Processing group', [
                    'group_name' => $group['group_name'],
                    'group_id' => $group['id']
                ]);

                // Get group details from the MailerLite list. The key for lookup is the group id.
                $group_details = $mailerlite_groups_map[strtoupper($group['group_name'])] ?? null;

                // Check if group details were successfully retrieved from MailerLite.
                if (!$group_details) {
                    $this->logger->warning('Group not found in MailerLite', [
                        'group_name' => $group['group_name']
                    ]);
                    continue;
                }

                // Fetch the subscribers for the MailerLite group.
                $this->logger->debug('Fetching subscribers for group', [
                    'group_name' => $group['group_name'],
                    'group_id' => $group['id']
                ]);
                $group_subscribers = $this->mailerLiteInstance->getGroupSubscribers($group['id']);

                // Check if there are any subscribers in the group. If not, log it and move on.
                if (empty($group_subscribers)) {
                    $this->logger->debug('No subscribers found in group', [
                        'group_name' => $group['group_name']
                    ]);
                    continue;
                }

                $this->logger->info('Subscribers found in group', [
                    'group_name' => $group['group_name'],
                    'subscriber_count' => count($group_subscribers)
                ]);

                // Extract the campaign name from the group name.
                $campaign_name = $this->utils->get_campaign_name_from_text($group['group_name']);

                // Check if the campaign name was successfully extracted.
                if (empty($campaign_name)) {
                    $this->logger->warning('Could not extract campaign name from group', [
                        'group_name' => $group['group_name']
                    ]);
                    continue;
                }

                // Get the campaign ID from the database using the extracted name.
                $campaign_data = $this->campaign_database->get_campaign_by_name($campaign_name);

                // Check if the campaign ID was successfully retrieved from the database.
                if (empty($campaign_data) || empty($campaign_data['id'])) {
                    $this->logger->warning('Campaign not found in database', [
                        'campaign_name' => $campaign_name,
                        'group_name' => $group['group_name']
                    ]);
                    continue;
                }

                $campaign_id = $campaign_data['id'];
                $this->logger->debug('Processing subscribers for campaign group', [
                    'campaign_name' => $campaign_name,
                    'campaign_id' => $campaign_id,
                    'group_name' => $group['group_name'],
                    'subscriber_count' => count($group_subscribers)
                ]);

                foreach ($group_subscribers as $subscriber) {
                    $tier = $this->utils->get_tier_from_group_name($group['group_name']);
                    $purchase_id = $this->get_purchase_id_from_subscriber($subscriber, $campaign_name);

                    $campaign_subscriber = [
                        'campaign_id' => $campaign_id,
                        'subscriber_id' => $subscriber['id'],
                        'group_id' => $group['id'],
                        'subscriber_tier' => ucwords(strtolower($tier)),
                        'purchase_id' => $purchase_id,
                    ];

                    $campaign_subscribers_data[] = $campaign_subscriber;
                }

                $this->logger->debug('Processed subscribers for group', [
                    'group_name' => $group['group_name'],
                    'processed_count' => count($group_subscribers)
                ]);
            }

            // Final batch upsert
            if (!empty($campaign_subscribers_data)) {
                $this->logger->info('Performing bulk upsert of campaign subscribers', [
                    'total_campaign_subscribers' => count($campaign_subscribers_data)
                ]);

                $upsert_result = $this->campaign_group_subscribers_database->upsert_campaign_subscribers_bulk($campaign_subscribers_data);
                $this->logger->info('Campaign subscriber bulk upsert completed', [
                    'upsert_result' => $upsert_result,
                    'subscribers_upserted' => count($campaign_subscribers_data)
                ]);

                $this->logger->info('Campaign group subscriber sync completed successfully');
                return true;
            } else {
                $this->logger->warning('No campaign subscribers to upsert');
                return false;
            }

        } catch (Exception $e) {
            $this->logger->error('Campaign group subscriber sync failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Synchronizes all MailerLite data, including campaigns, fields, groups, and subscribers.
     *
     * This method orchestrates the entire synchronization process to ensure the local database
     * is up-to-date with MailerLite's data. It provides granular status updates for user feedback
     * and optimizes data processing for efficiency.
     *
     * @return void
     * @throws Exception If any of the MailerLite API calls or database operations fail.
     */
    public function sync_all_mailerlite_data(): void
    {
        $start_time = microtime(true);
        $sync_option_key = 'bema_crm_sync_status';

        $this->logger->info('=== STARTING FULL MAILERLITE SYNC ===', [
            'timestamp' => date('Y-m-d H:i:s'),
            'memory_usage' => $this->formatBytes(memory_get_usage(true)),
            'peak_memory' => $this->formatBytes(memory_get_peak_usage(true))
        ]);

        try {
            // 1. Sync Album Campaigns
            $this->logger->info('STEP 1/5: Starting album campaign sync');

            $this->update_sync_status('Running', 'Updating campaigns', 0, 5, $sync_option_key);

            $step_start = microtime(true);
            $campaign_result = $this->sync_album_campaign_data();
            $step_duration = round(microtime(true) - $step_start, 2);

            $this->logger->info('STEP 1/5: Album campaign sync completed', [
                'duration_seconds' => $step_duration,
                'result' => $campaign_result,
                'memory_usage' => $this->formatBytes(memory_get_usage(true))
            ]);

            // 2. Sync Fields
            $this->logger->info('STEP 2/5: Starting field sync');

            $this->update_sync_status('Running', 'Updating field database', 1, 5, $sync_option_key);

            $step_start = microtime(true);
            $field_result = $this->sync_mailerlite_field_data();
            $step_duration = round(microtime(true) - $step_start, 2);

            $this->logger->info('STEP 2/5: Field sync completed', [
                'duration_seconds' => $step_duration,
                'result' => $field_result,
                'memory_usage' => $this->formatBytes(memory_get_usage(true))
            ]);

            // 3. Sync Groups
            $this->logger->info('STEP 3/5: Starting group sync');

            $this->update_sync_status('Running', 'Updating group database', 2, 5, $sync_option_key);

            $step_start = microtime(true);
            $group_result = $this->sync_mailerlite_group_data();
            $step_duration = round(microtime(true) - $step_start, 2);

            $this->logger->info('STEP 3/5: Group sync completed', [
                'duration_seconds' => $step_duration,
                'result' => $group_result,
                'memory_usage' => $this->formatBytes(memory_get_usage(true))
            ]);

            // 4a. Sync Subscribers
            $this->logger->info('STEP 4/5: Starting subscriber sync');

            $this->update_sync_status('Running', 'Fetching subscribers data', 3, 5, $sync_option_key);

            $step_start = microtime(true);
            $subscribers_count = $this->sync_subscribers();
            $step_duration = round(microtime(true) - $step_start, 2);

            $this->logger->info('STEP 4/5: Subscriber sync completed', [
                'duration_seconds' => $step_duration,
                'subscribers_count' => $subscribers_count,
                'memory_usage' => $this->formatBytes(memory_get_usage(true))
            ]);

            // 4b. Sync Campaign Group subscribers
            $this->logger->info('STEP 5/5: Starting campaign group subscriber sync');

            $this->update_sync_status('Running', 'Syncing campaign group subscribers', 4, 5, $sync_option_key);

            $step_start = microtime(true);
            $campaign_subscriber_result = $this->sync_mailerlite_campaign_group_subscribers();
            $step_duration = round(microtime(true) - $step_start, 2);

            $this->logger->info('STEP 5/5: Campaign group subscriber sync completed', [
                'duration_seconds' => $step_duration,
                'result' => $campaign_subscriber_result,
                'memory_usage' => $this->formatBytes(memory_get_usage(true))
            ]);

            // 5. Final Status Update and History
            $total_duration = round(microtime(true) - $start_time, 2);

            $this->update_sync_status('Completed', 'Sync completed', 5, 5, $sync_option_key, $subscribers_count);

            $this->sync_database->upsert_sync_record('Completed', $subscribers_count, "Successfully synced {$subscribers_count} subscribers.", null);

            $this->logger->info('=== FULL MAILERLITE SYNC COMPLETED SUCCESSFULLY ===', [
                'total_duration_seconds' => $total_duration,
                'subscribers_synced' => $subscribers_count,
                'final_memory_usage' => $this->formatBytes(memory_get_usage(true)),
                'peak_memory_usage' => $this->formatBytes(memory_get_peak_usage(true)),
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (Exception $e) {
            $total_duration = round(microtime(true) - $start_time, 2);

            $this->logger->error('=== FULL MAILERLITE SYNC FAILED ===', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'duration_before_failure' => $total_duration,
                'memory_usage' => $this->formatBytes(memory_get_usage(true)),
                'peak_memory' => $this->formatBytes(memory_get_peak_usage(true)),
                'timestamp' => date('Y-m-d H:i:s'),
                'stack_trace' => $e->getTraceAsString()
            ]);

            $this->update_sync_status('Idle', 'Sync failed: ' . $e->getMessage(), 0, 5, $sync_option_key);

            $this->sync_database->upsert_sync_record('Failed', 0, $e->getMessage(), null);
        }
    }

    /**
     * Validate an EDD customer order by ID and email.
     * Compatible with both EDD 2.x (edd_payment) and 3.x+ (edd_get_order).
     *
     * @param int    $order_id The EDD order ID (required).
     * @param string $email    The customer's email address (required).
     * @return bool True if valid match, false otherwise.
     */
    function validate_edd_order_and_customer(int $order_id, string $email): bool
    {
        // Sanitize inputs
        echo "Debugging: Starting validation for Order ID: {$order_id} and Email: {$email}\n";
        $order_id = absint($order_id);
        $email = sanitize_email($email);
        echo "Debugging: Sanitized Order ID: {$order_id} and Sanitized Email: {$email}\n";

        if ($order_id <= 0 || empty($email)) {
            echo "Debugging: Invalid input. Order ID is not positive or email is empty.\n";
            return false;
        }

        $order_email = '';

        // EDD 3.x and newer
        if (function_exists('edd_get_order')) {
            echo "Debugging: EDD 3.x detected. Attempting to get order.\n";
            $order = edd_get_order($order_id);
            if (!$order) {
                echo "Debugging: Order not found for ID: {$order_id}\n";
                return false;
            }
            $order_email = !empty($order->email) ? sanitize_email($order->email) : '';
            echo "Debugging: Found order email (3.x): {$order_email}\n";
        }
        // EDD 2.x (legacy)
        elseif (function_exists('edd_get_payment')) {
            echo "Debugging: EDD 2.x detected. Attempting to get payment.\n";
            $payment = edd_get_payment($order_id);
            if (!$payment) {
                echo "Debugging: Payment not found for ID: {$order_id}\n";
                return false;
            }
            $order_email = !empty($payment->email) ? sanitize_email($payment->email) : '';
            echo "Debugging: Found order email (2.x): {$order_email}\n";
        } else {
            // EDD not available
            echo "Debugging: EDD core functions not found.\n";
            return false;
        }

        // Compare email case-insensitively
        $comparison_result = strcasecmp($order_email, $email) === 0;
        echo "Debugging: Comparing emails '{$order_email}' and '{$email}'. Result: " . ($comparison_result ? 'Match' : 'No Match') . "\n";
        return $comparison_result;
    }

    /**
     * Transitions subscribers between campaigns based on defined rules.
     *
     * This function handles the logic for moving subscribers from a source campaign to a destination campaign.
     * It iterates through a set of predefined transition rules, identifies subscribers that meet the criteria
     * (e.g., a purchase has been made), and moves them to the appropriate group in the destination campaign.
     * The process includes validating campaign and group existence, retrieving subscribers, filtering them based on rules,
     * performing a bulk import to the new group, and logging the transition for tracking purposes.
     *
     * @param string $source_campaign_name The name of the source campaign to transition subscribers from.
     * @param string $destination_campaign_name The name of the destination campaign to transition subscribers to.
     *
     * @return void
     */
    public function transition_campaigns(string $source_campaign_name, string $destination_campaign_name)
    {
        try {
            // Retrieve transition rules from the options table.
            $transition_rules = get_option('bema_crm_transition_matrix', []);
            if (empty($transition_rules)) {
                $this->logger->info('Transition rules are not defined.', 'info');
                return;
            }

            // Get the source campaign details by name.
            $source_campaign = $this->campaign_database->get_campaign_by_name($source_campaign_name);
            if (!$source_campaign) {
                throw new Exception("Source campaign '{$source_campaign_name}' not found.");
            }
            $source_campaign_id = $source_campaign['id'];

            // Get the destination campaign details by name.
            $destination_campaign = $this->campaign_database->get_campaign_by_name($destination_campaign_name);
            if (!$destination_campaign) {
                throw new Exception("Destination campaign '{$destination_campaign_name}' not found.");
            }
            $destination_campaign_id = $destination_campaign['id'];

            // Record the transition in the database for historical tracking.
            $transition_id = $this->transition_database->insert_record($source_campaign_id, $destination_campaign_id, "Complete", 0);
            
            // Total count of subscribers transferred.
            $transfer_count = 0;
            
            // Iterate through each defined transition rule.
            foreach ($transition_rules as $index => $rule) {
                // Normalize the current tier name for consistent group naming.
                $normalize_current_tier = strtoupper(str_replace(' ', '_', $rule['current_tier']));
                $normalize_next_tier = strtoupper(str_replace(' ', '_', $rule['next_tier']));

                // Construct the name of the custom field used to track purchases.
                $source_purchase_field = strtolower($source_campaign_name . '_' . 'PURCHASE');

                // Find the source group based on the campaign name and tier.
                $source_group_name = $source_campaign_name . '_' . $normalize_current_tier;
                $source_campaign_group = $this->group_database->get_group_by_name($source_group_name);

                if (!$source_campaign_group) {
                    $this->logger->log("Source group '{$source_group_name}' not found. Skipping.", 'warning');
                    continue; // Skip this rule if the source group doesn't exist.
                }
                $source_campaign_group_id = $source_campaign_group['id'];

                // Find the corresponding destination group.
                $destination_group_name = $destination_campaign_name . '_' . $normalize_next_tier;
                $destination_campaign_group = $this->group_database->get_group_by_name($destination_group_name);

                if (!$destination_campaign_group) {
                    $this->logger->log("Destination group '{$destination_group_name}' not found. Skipping.", 'warning');
                    continue; // Skip this rule if the destination group doesn't exist.
                }

                $destination_campaign_group_id = $destination_campaign_group['id'];

                // Retrieve all subscribers from the source group.
                $source_campaign_subscribers = $this->mailerLiteInstance->getGroupSubscribers($source_campaign_group_id);

                if (empty($source_campaign_subscribers)) {
                    $this->logger->log("No subscribers found in group '{$source_group_name}'. Skipping.", 'info');
                    continue; // Skip if there are no subscribers to process.
                }

                $subscribers_to_transfer = [];
                // Check if the rule requires a purchase for transition.
                if (!empty($rule['requires_purchase'])) {
                    // Filter subscribers based on the purchase field.
                    foreach ($source_campaign_subscribers as $subscriber) {
                        $field_value = $subscriber['fields'][$source_purchase_field] ?? null;
                        if ($field_value) {
                            $is_valid_purchase = false;
                            try {
                                // Validate the purchase using an external system (e.g., EDD).
                                $is_valid_purchase = $this->validate_edd_order_and_customer($field_value, $subscriber['email']);
                            } catch (Exception $ve) {
                                // Suppress validation errors to continue processing.
                                $this->logger->log("Validation error for subscriber '{$subscriber['email']}'", 'warning', ['error' => $ve->getMessage()]);
                            }
                            if ($is_valid_purchase) {
                                $subscribers_to_transfer[] = $subscriber; // Add subscriber to the transfer list.
                            }
                        }
                    }
                } else {
                    // If no purchase is required, all subscribers are eligible for transfer.
                    $subscribers_to_transfer = $source_campaign_subscribers;
                }

                $transfer_count += count($subscribers_to_transfer);

                if (empty($subscribers_to_transfer)) {
                    $this->logger->log("No subscribers to transfer for group '{$source_group_name}' based on rules.", 'info');
                    continue; // Skip if no subscribers meet the transfer criteria.
                }

                try {
                    // Perform a bulk import of eligible subscribers to the destination group.
                    $this->mailerLiteInstance->importBulkSubscribersToGroup($subscribers_to_transfer, $destination_campaign_group_id);
                } catch (Exception $e) {
                    // Log any errors that occur during the bulk import.
                    $this->logger->info('Bulk import error', 'error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                }

                // Store the transferred subscribers for the transition record.
                $this->transition_subscribers_database->bulk_upsert($subscribers_to_transfer, $transition_id);
            }

            // Record the transition in the database for historical tracking.
            $this->transition_database->upsert_record($transition_id, "Complete", $transfer_count);

        } catch (Exception $e) {
            // Catch and log any global exceptions that occur during the function execution.
            $this->logger->info('Error transitioning campaigns', 'error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    /**
     * Validates group data in the database against MailerLite groups.
     * 
     * This function fetches all groups from MailerLite and compares them with
     * groups stored in the local database. Any groups that exist in the database
     * but no longer exist in MailerLite will be deleted from the database.
     *
     * @return array Returns an array with validation results including counts of validated and deleted groups
     * @throws Exception If there are issues with API calls or database operations
     */
    public function validate_group_data_in_database(): array
    {
        $this->logger->info('Starting group data validation against MailerLite');

        $validation_results = [
            'status' => 'success',
            'total_db_groups' => 0,
            'validated_groups' => 0,
            'deleted_groups' => 0,
            'deleted_group_ids' => [],
            'errors' => []
        ];

        try {
            // Fetch all groups from MailerLite with ID as key
            $this->logger->debug('Fetching groups from MailerLite API');
            $mailerlite_groups_map = $this->mailerLiteInstance->getAllGroupsIdMap();

            if (!is_array($mailerlite_groups_map)) {
                throw new Exception('Failed to fetch groups from MailerLite or invalid response format');
            }

            $this->logger->info('MailerLite groups fetched', [
                'mailerlite_groups_count' => count($mailerlite_groups_map),
                'group_ids' => array_keys($mailerlite_groups_map)
            ]);

            // Fetch all groups from local database
            $this->logger->debug('Fetching groups from local database');
            $database_groups = $this->group_database->get_all_groups();

            if (!is_array($database_groups)) {
                throw new Exception('Failed to fetch groups from local database');
            }

            $validation_results['total_db_groups'] = count($database_groups);

            $this->logger->info('Database groups fetched', [
                'database_groups_count' => count($database_groups),
                'group_ids' => array_column($database_groups, 'id')
            ]);

            // Start database transaction for consistency
            $this->dbManager->beginTransactionWithRetry(self::TRANSACTION_TIMEOUT);

            // Validate each group in the database
            foreach ($database_groups as $db_group) {
                $group_id = $db_group['id'];
                $group_name = $db_group['group_name'];

                // Convert group ID to uppercase string for comparison (as getAllGroupsIdMap uses uppercase keys)
                $group_id_key = strtoupper((string) $group_id);

                $this->logger->debug('Validating group', [
                    'group_id' => $group_id,
                    'group_name' => $group_name,
                    'lookup_key' => $group_id_key
                ]);

                // Check if the group still exists in MailerLite
                if (isset($mailerlite_groups_map[$group_id_key])) {
                    // Group exists in MailerLite, mark as validated
                    $validation_results['validated_groups']++;

                    $this->logger->debug('Group validated - exists in MailerLite', [
                        'group_id' => $group_id,
                        'group_name' => $group_name
                    ]);
                } else {
                    // Group no longer exists in MailerLite, delete from database
                    $this->logger->warning('Group not found in MailerLite, deleting from database', [
                        'group_id' => $group_id,
                        'group_name' => $group_name
                    ]);

                    try {
                        $deleted_rows = $this->group_database->delete_group_by_id($group_id);

                        if ($deleted_rows !== false && $deleted_rows > 0) {
                            $validation_results['deleted_groups']++;
                            $validation_results['deleted_group_ids'][] = $group_id;

                            $this->logger->info('Group successfully deleted from database', [
                                'group_id' => $group_id,
                                'group_name' => $group_name,
                                'deleted_rows' => $deleted_rows
                            ]);
                        } else {
                            $error_msg = "Failed to delete group from database: {$group_name} (ID: {$group_id})";
                            $validation_results['errors'][] = $error_msg;
                            $this->logger->error($error_msg);
                        }
                    } catch (Exception $delete_error) {
                        $error_msg = "Error deleting group {$group_name} (ID: {$group_id}): " . $delete_error->getMessage();
                        $validation_results['errors'][] = $error_msg;
                        $this->logger->error($error_msg, [
                            'exception' => $delete_error->getMessage(),
                            'trace' => $delete_error->getTraceAsString()
                        ]);
                    }
                }
            }

            // Commit the transaction
            $this->dbManager->commit();

            // Set status based on whether there were any errors
            if (!empty($validation_results['errors'])) {
                $validation_results['status'] = 'completed_with_errors';
            }

            $this->logger->info('Group data validation completed', [
                'status' => $validation_results['status'],
                'total_db_groups' => $validation_results['total_db_groups'],
                'validated_groups' => $validation_results['validated_groups'],
                'deleted_groups' => $validation_results['deleted_groups'],
                'errors_count' => count($validation_results['errors'])
            ]);

            return $validation_results;

        } catch (Exception $e) {
            // Rollback transaction on error
            if (isset($this->dbManager)) {
                $this->dbManager->rollback();
            }

            $validation_results['status'] = 'failed';
            $validation_results['errors'][] = $e->getMessage();

            $this->logger->error('Group data validation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

}

// Queue Manager Class with Priority Support
class SyncQueueManager
{
    const QUEUE_OPTION = 'bema_sync_queue';

    public function addToQueue($items, $priority = 'normal')
    {
        $queue = $this->getQueue();

        foreach ((array) $items as $item) {
            $queue[] = [
                'data' => $item,
                'priority' => $priority,
                'added_time' => time()
            ];
        }

        usort($queue, function ($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return $a['added_time'] - $b['added_time'];
            }

            $priorities = [
                'high' => 3,
                'normal' => 2,
                'low' => 1
            ];

            return $priorities[$b['priority']] - $priorities[$a['priority']];
        });

        update_option(self::QUEUE_OPTION, $queue);
    }

    public function getNextBatch($batchSize)
    {
        $queue = $this->getQueue();
        $batch = array_slice($queue, 0, $batchSize);

        if (!empty($batch)) {
            $queue = array_slice($queue, $batchSize);
            update_option(self::QUEUE_OPTION, $queue);
            return array_column($batch, 'data');
        }

        return [];
    }

    private function getQueue()
    {
        return get_option(self::QUEUE_OPTION, []);
    }

    public function clearQueue(): void
    {
        update_option(self::QUEUE_OPTION, []);
    }
}
