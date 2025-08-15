<?php

namespace Bema;

use Exception;
use RuntimeException;
use WP_Object_Cache;
use Bema\Providers\EDD;
use Bema\Providers\MailerLite;
use Bema\Exceptions\Sync_Exception;
use Bema\Exceptions\API_Exception;
use Bema\Exceptions\Validation_Exception;
use Bema\SyncBatchProcessor;
use Bema\Campaign_Manager;
use Bema\Bema_Settings;
use Bema\BemaCRMLogger;

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
        ?BemaCRMLogger $logger = null,
        ?Bema_Settings $settings = null
    ) {
        try {
            debug_to_file('Starting EM_Sync construction');

            // Set error handling
            set_error_handler([$this, 'errorHandler']);
            debug_to_file('Error handler set');

            // Initialize logger
            $this->logger = $logger ?? new BemaCRMLogger();
            debug_to_file('Logger initialized');

            // Initialize database manager
            global $wpdb;
            $this->dbManager = new Database_Manager($wpdb, $this->logger);
            debug_to_file('Database manager initialized');

            // Store settings instance
            $this->settings = $settings;
            debug_to_file('Settings instance stored');

            // Load API credentials
            $this->loadApiCredentials();

            // Store provider instances
            $this->mailerLiteInstance = $mailerLiteInstance;
            debug_to_file('MailerLite instance stored');

            $this->eddInstance = $eddInstance;
            debug_to_file('EDD instance stored');

            // Initialize campaign manager
            $this->campaign_manager = new Campaign_Manager($mailerLiteInstance, $this->logger);

            $this->cache = new WP_Object_Cache();
            debug_to_file('Cache initialized');

            $this->queueManager = new SyncQueueManager();
            debug_to_file('Queue manager initialized');

            // Set memory limit
            $this->setMemoryLimit();
            debug_to_file('Memory limit set');

            // Initialize progress tracker and error queue
            $this->initializeProgress();
            debug_to_file('Progress initialized');

            $this->initializeErrorQueue();
            debug_to_file('Error queue initialized');

            // Register shutdown function
            register_shutdown_function([$this, 'handleShutdown']);
            debug_to_file('Shutdown handler registered');

            debug_to_file('EM_Sync construction completed successfully');
        } catch (Exception $e) {
            debug_to_file("EM_Sync initialization failed: " . $e->getMessage());
            debug_to_file("Stack trace: " . $e->getTraceAsString());
            $this->logError('Failed to initialize EM_Sync', $e);
            throw new Sync_Exception('Failed to initialize sync system: ' . $e->getMessage());
        }
    }

    /**
     * Load API credentials from settings
     */
    private function loadApiCredentials(): void
    {
        if (!$this->settings) {
            return;
        }

        $settings = $this->settings->get_settings();

        debug_to_file([
            'loading_credentials' => true,
            'has_mailerlite_key' => !empty($settings['api']['mailerlite_api_key']),
            'has_edd_key' => !empty($settings['api']['edd_api_key']),
            'has_edd_token' => !empty($settings['api']['edd_token'])
        ], 'API_CREDENTIALS');

        // Reinitialize providers with credentials if needed
        if (!empty($settings['api']['mailerlite_api_key'])) {
            $this->mailerLiteInstance = new Providers\MailerLite(
                $settings['api']['mailerlite_api_key'],
                $this->logger
            );
        }

        if (!empty($settings['api']['edd_api_key']) && !empty($settings['api']['edd_token'])) {
            $this->eddInstance = new Providers\EDD(
                $settings['api']['edd_api_key'],
                $settings['api']['edd_token'],
                $this->logger
            );
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

            debug_to_file('Starting API validation', 'API_VALIDATION');

            // Test MailerLite connection first
            try {
                $mailerliteResult = $this->mailerLiteInstance->validateConnection();
                debug_to_file([
                    'mailerlite_validation' => 'success',
                    'result' => $mailerliteResult
                ], 'API_VALIDATION');
            } catch (Exception $e) {
                debug_to_file([
                    'mailerlite_validation' => 'failed',
                    'error' => $e->getMessage()
                ], 'API_VALIDATION');
                throw new Exception('MailerLite API connection failed: ' . $e->getMessage());
            }

            // Then test EDD connection
            try {
                $eddResult = $this->eddInstance->validateConnection();
                debug_to_file([
                    'edd_validation' => 'success',
                    'result' => $eddResult
                ], 'API_VALIDATION');
            } catch (Exception $e) {
                debug_to_file([
                    'edd_validation' => 'failed',
                    'error' => $e->getMessage()
                ], 'API_VALIDATION');
                throw new Exception('EDD API connection failed: ' . $e->getMessage());
            }

            return true;
        } catch (Exception $e) {
            debug_to_file([
                'api_validation_failed' => true,
                'error' => $e->getMessage()
            ], 'API_VALIDATION');
            throw $e;
        }
    }

    private function setMemoryLimit(): void
    {
        $currentLimit = ini_get('memory_limit');
        $bytes = $this->getMemoryLimitInBytes();

        if ($bytes < $this->getMemoryLimitInBytes($this->maxMemoryLimit)) {
            ini_set('memory_limit', $this->maxMemoryLimit);
            $this->logger->log('Memory limit increased', 'info', [
                'from' => $currentLimit,
                'to' => $this->maxMemoryLimit
            ]);
        }
    }

    private function initializeProgress(): void
    {
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
        update_option(self::STATUS_KEY, $this->syncStatus, false);
    }

    private function initializeErrorQueue(): void
    {
        $this->errorQueue = get_option(self::ERROR_LOG_KEY, []);
        if (!is_array($this->errorQueue)) {
            $this->errorQueue = [];
            update_option(self::ERROR_LOG_KEY, [], false);
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

            debug_to_file([
                'data' => $campaigns,
                'start_time' => $startTime,
                'entering_sync_all' => true
            ], 'SYNC_PROGRESS');

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
                    debug_to_file('Sync process stopped by user', 'SYNC_COMPLETION');
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

                    debug_to_file([
                        'campaign_completed' => true,
                        'campaign' => $campaign['name'],
                        'processed' => $totalProcessed,
                        'total' => $totalCampaigns
                    ], 'SYNC_PROGRESS');
                } catch (Exception $e) {
                    $this->logger->log('Campaign processing failed', 'error', [
                        'campaign' => $campaign['name'],
                        'error' => $e->getMessage()
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

            debug_to_file([
                'sync_completed' => true,
                'total_processed' => $totalProcessed,
                'total_campaigns' => $totalCampaigns,
                'duration' => $duration,
                'start_time' => date('Y-m-d H:i:s', (int) $startTime),
                'end_time' => date('Y-m-d H:i:s', (int) $endTime)
            ], 'SYNC_COMPLETION');

            // Clear any remaining sync flags
            delete_option('bema_sync_running');
            delete_transient('bema_sync_lock');
            wp_cache_delete('sync_in_progress', 'bema_sync');

            return true;
        } catch (Exception $e) {
            debug_to_file([
                'sync_failed' => true,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'SYNC_ERROR');

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
                            $this->logger->log('Retrying batch', 'warning', [
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
            $this->logger->log('Campaign processing failed', 'error', [
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

                $this->logger->log('Max pages reached for current run', 'info', [
                    'campaign' => $campaign['name'],
                    'group' => $groupType,
                    'pages_processed' => $processedPages,
                    'next_page' => $currentPage
                ]);
            }
        } catch (Exception $e) {
            $this->logger->log('Group processing failed', 'error', [
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
            $this->logger->log('Failed to get campaign group ID', 'error', [
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

        debug_to_file([
            'progress_state_saved' => $state
        ], 'SYNC_PROGRESS');
    }

    private function loadProgressState(): ?array
    {
        $saved = get_option(self::PROGRESS_STATE_KEY);
        if (!$saved) {
            return null;
        }

        debug_to_file([
            'progress_state_loaded' => $saved
        ], 'SYNC_PROGRESS');

        return $saved['state'];
    }

    private function clearProgressState(): void
    {
        delete_option(self::PROGRESS_STATE_KEY);
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

        $this->logger->log('Queued remaining work', 'info', [
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
                    $this->logger->log('Group not found', 'warning', [
                        'group_name' => $fullGroupName
                    ]);
                    continue;
                }

                $this->processCampaignGroup($campaign, $groupType, $groupId);
            }
        } catch (Exception $e) {
            $this->logger->log('Campaign processing failed', 'error', [
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
                    $this->logger->log('Failed to process subscriber', 'error', [
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
            $this->logger->log('Group sync failed', 'error', [
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

                $this->logger->log('Scheduling retry for failed batch', 'info', $retryContext);

                // Add to retry queue with context
                $this->addToErrorQueue(array_merge(
                    $e->getErrorDetails(),
                    $retryContext
                ));
            }
        } catch (Exception $retryError) {
            $this->logger->log('Failed to handle retry', 'error', [
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
        debug_to_file('Sync scheduler updated', 'SYNC_INIT');

        $this->logger?->log('Sync scheduler set', 'debug', [
            'scheduler_initialized' => isset($this->sync_scheduler) ? 'yes' : 'no'
        ]);
    }

    /**
     * Get groups for campaign
     */
    private function getCampaignGroups(array $campaigns): array
    {
        try {
            debug_to_file([
                'method' => 'getCampaignGroups',
                'campaigns' => $campaigns
            ], 'SYNC_DEBUG');

            // Get all MailerLite groups first
            $mailerlite_groups = $this->mailerLiteInstance->getGroups();
            if (empty($mailerlite_groups)) {
                throw new Exception('No groups found in MailerLite');
            }

            debug_to_file([
                'mailerlite_groups_found' => count($mailerlite_groups),
                'mailerlite_groups' => array_column($mailerlite_groups, 'name')
            ], 'SYNC_DEBUG');

            $campaign_groups = [];
            foreach ($campaigns as $campaign) {
                $groups = $this->campaign_manager->get_campaign_groups($campaign['name']);

                if (!$groups) {
                    debug_to_file([
                        'warning' => 'No groups defined for campaign',
                        'campaign' => $campaign['name']
                    ], 'SYNC_DEBUG');
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
                    debug_to_file([
                        'warning' => 'No matching MailerLite groups found for campaign',
                        'campaign' => $campaign['name'],
                        'defined_groups' => $groups
                    ], 'SYNC_DEBUG');
                    continue;
                }

                $campaign_groups[$campaign['name']] = [
                    'groups' => $mapped_groups,
                    'field' => $campaign['field'],
                    'tag' => $campaign['tag']
                ];
            }

            debug_to_file([
                'campaign_groups_found' => count($campaign_groups),
                'campaign_groups' => $campaign_groups
            ], 'SYNC_GROUPS');

            if (empty($campaign_groups)) {
                throw new Exception('No valid campaign groups found in MailerLite');
            }

            return $campaign_groups;
        } catch (Exception $e) {
            $this->logger->log('Failed to get campaign groups', 'error', [
                'error' => $e->getMessage()
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
            debug_to_file([
                'method' => 'getEDDProductForCampaign',
                'campaign' => $campaignName
            ], 'SYNC_DEBUG');

            // Parse campaign code to get artist and product info
            $parts = explode('_', $campaignName);
            if (count($parts) !== 3) {
                return null;
            }

            [$year, $artist, $product] = $parts;

            // Search EDD products with this naming convention
            $productId = $this->eddInstance->findProductByNamePattern($artist, $product);

            debug_to_file([
                'found_product_id' => $productId,
                'artist' => $artist,
                'product' => $product,
                'campaign_name' => $campaignName
            ], 'SYNC_DEBUG');

            return $productId;
        } catch (Exception $e) {
            $this->logger->log('Failed to get EDD product', 'error', [
                'campaign' => $campaignName,
                'error' => $e->getMessage()
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

            debug_to_file([
                'available_mailerlite_groups' => $mailerliteGroups
            ], 'CAMPAIGN_VALIDATION');

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
                    debug_to_file([
                        'invalid_campaign' => $campaign['name']
                    ], 'CAMPAIGN_VALIDATION');
                    continue;
                }

                // Get required groups for this campaign
                $requiredGroups = $this->campaign_manager->get_campaign_groups($campaign['name']);
                if (!$requiredGroups) {
                    debug_to_file([
                        'missing_campaign_groups' => $campaign['name']
                    ], 'CAMPAIGN_VALIDATION');
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
                    debug_to_file([
                        'campaign' => $campaign['name'],
                        'missing_groups' => $missingGroups
                    ], 'CAMPAIGN_VALIDATION');
                    continue;
                }

                // Add group IDs to campaign data
                $campaign['group_ids'] = $groupIds;
                $validCampaigns[] = $campaign;

                debug_to_file([
                    'campaign_validated' => $campaign['name'],
                    'group_ids' => $groupIds
                ], 'CAMPAIGN_VALIDATION');
            }

            return $validCampaigns;
        } catch (Exception $e) {
            debug_to_file([
                'campaign_validation_failed' => true,
                'error' => $e->getMessage()
            ], 'CAMPAIGN_VALIDATION');
            throw $e;
        }
    }

    private function processSingleSubscriber(array $subscriber, array $campaign, string $currentGroup): void
    {
        try {
            debug_to_file([
                'processing_subscriber' => true,
                'email' => $subscriber['email'],
                'campaign' => $campaign['name'],
                'current_group' => $currentGroup
            ], 'SYNC_PROCESSING');

            // Get product ID and check purchase status
            $productId = $this->getEDDProductForCampaign($campaign['name']);

            if (!$productId) {
                $this->logger->log('No product found for campaign', 'warning', [
                    'campaign' => $campaign['name']
                ]);
                $hasPurchased = false;
            } else {
                $hasPurchased = $this->eddInstance->hasUserPurchasedProduct(
                    $subscriber['id'],
                    $productId
                );
            }

            debug_to_file([
                'purchase_check_complete' => true,
                'email' => $subscriber['email'],
                'campaign' => $campaign['name'],
                'has_purchased' => $hasPurchased,
                'product_id' => $productId
            ], 'SYNC_PROCESSING');

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

            debug_to_file([
                'subscriber_processed' => true,
                'email' => $subscriber['email'],
                'campaign' => $campaign['name'],
                'from_tier' => $currentGroup,
                'to_tier' => $nextTier,
                'has_purchased' => $hasPurchased,
                'product_id' => $productId,
                'next_group_id' => isset($nextGroupId) ? $nextGroupId : null
            ], 'SYNC_PROCESSING');
        } catch (Exception $e) {
            debug_to_file([
                'subscriber_processing_failed' => true,
                'email' => $subscriber['email'],
                'campaign' => $campaign['name'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'SYNC_ERROR');
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
                $this->logger->log('Invalid tier transition attempted', 'warning', [
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

            debug_to_file([
                'handling_tier_transition' => [
                    'subscriber' => $subscriber['email'],
                    'campaign' => $campaign['name'],
                    'from_group' => $currentGroup,
                    'to_group' => $nextGroup,
                    'has_purchased' => $hasPurchased
                ]
            ], 'SYNC_DEBUG');

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

            $this->logger->log('Tier transition completed', 'info', [
                'email' => $subscriber['email'],
                'campaign' => $campaign['name'],
                'from_group' => $currentGroup,
                'to_group' => $nextGroup,
                'has_purchased' => $hasPurchased
            ]);
        } catch (Exception $e) {
            $this->logger->log('Tier transition failed', 'error', [
                'subscriber' => $subscriber['email'],
                'campaign' => $campaign['name'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get MailerLite group ID by name
     */
    private function getGroupIdByName(string $groupName): ?string
    {
        $groups = $this->mailerLiteInstance->getGroups();
        foreach ($groups as $group) {
            if ($group['name'] === $groupName) {
                return $group['id'];
            }
        }
        return null;
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
            $this->logger->log('Failed to get current group', 'error', [
                'subscriber_id' => $subscriberId,
                'error' => $e->getMessage()
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

            $this->logger->log('Error added to queue', 'debug', [
                'error' => $error
            ]);
        } catch (Exception $e) {
            $this->logger->log('Failed to add error to queue', 'error', [
                'error' => $e->getMessage(),
                'original_error' => $error
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
            $this->logger->log('Failed to process retry queue', 'error', [
                'error' => $e->getMessage()
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

            debug_to_file([
                'progress_updated' => $updated
            ], 'SYNC_PROGRESS');
        } catch (Exception $e) {
            $this->logger->log('Failed to update progress', 'error', [
                'error' => $e->getMessage()
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
            $this->logger->log('Sync error occurred', 'error', [
                'batch_size' => count($batch['subscribers'] ?? []),
                'campaign' => $batch['campaign']['name'] ?? 'unknown',
                'error' => $error->getMessage()
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

        $this->logger->log('Batch processing failed', 'error', $errorData);

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
            $this->logger->log('Time limit reached', 'warning', [
                'elapsed' => $timeElapsed,
                'limit' => $this->maxProcessingTime
            ]);
            return false;
        }

        if ($memoryUsage >= $memoryThresholdBytes) {
            $this->logger->log('Memory threshold reached', 'warning', [
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
            $this->logger->log('Memory cleanup triggered', 'warning', [
                'usage' => $this->formatBytes($memoryUsage),
                'peak' => $this->formatBytes($peakMemory),
                'limit' => $this->formatBytes($memoryLimit)
            ]);

            if (function_exists('gc_collect_cycles')) {
                $collected = gc_collect_cycles();
                $this->logger->log('Garbage collection completed', 'info', [
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
            debug_to_file('Getting MailerLite groups', 'SYNC_GROUPS');

            $groups = $this->mailerLiteInstance->getGroups();

            debug_to_file([
                'groups_retrieved' => true,
                'count' => count($groups)
            ], 'SYNC_GROUPS');

            return $groups;
        } catch (Exception $e) {
            debug_to_file([
                'get_groups_failed' => true,
                'error' => $e->getMessage()
            ], 'SYNC_GROUPS');

            $this->logger->log('Failed to get groups from MailerLite', 'error', [
                'error' => $e->getMessage()
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
                debug_to_file([
                    'using_cached_groups' => true,
                    'campaign' => $campaign
                ], 'SYNC_GROUPS');
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

            debug_to_file([
                'groups_fetched' => true,
                'campaign' => $campaign,
                'count' => count($groups)
            ], 'SYNC_GROUPS');

            return $groups;
        } catch (Exception $e) {
            debug_to_file([
                'get_groups_failed' => true,
                'campaign' => $campaign,
                'error' => $e->getMessage()
            ], 'SYNC_GROUPS');

            $this->logger->log('Failed to get campaign groups', 'error', [
                'campaign' => $campaign,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    private function errorHandler($errno, $errstr, $errfile, $errline): bool
    {
        $this->logger->log('PHP Error', 'error', [
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
            $this->logger->log('Fatal Error', 'critical', [
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

        $this->logger->log($message, 'error', $errorData);
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
        $this->errorQueue = [];
        update_option(self::ERROR_LOG_KEY, [], false);
    }

    private function generateCacheKey($data): string
    {
        return 'sync_' . md5(serialize($data));
    }

    private function getMemoryLimitInBytes(string $memoryLimit = null): int
    {
        $memoryLimit = $memoryLimit ?? ini_get('memory_limit');
        if ($memoryLimit === '-1')
            return PHP_INT_MAX;

        preg_match('/^(\d+)(.)$/', $memoryLimit, $matches);
        if (!$matches)
            return 128 * 1024 * 1024; // 128MB default

        $value = (int) $matches[1];
        switch (strtoupper($matches[2])) {
            case 'G':
                $value *= 1024;
            case 'M':
                $value *= 1024;
            case 'K':
                $value *= 1024;
        }

        return $value;
    }

    private function formatBytes($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
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
            throw new Validation_Exception('Invalid email format');
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
                $this->logger->log('Invalid campaign data', 'warning', ['campaign' => $campaign]);
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
            $this->logger->log('Failed to fetch subscribers', 'error', [
                'campaign' => $campaign['name'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function handleCampaignError($campaign, Exception $e): void
    {
        $this->logger->log('Campaign processing failed', 'error', [
            'campaign' => $campaign['name'],
            'error' => $e->getMessage()
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
                $this->logger->log('Processing time limit reached', 'warning');
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
                throw new Validation_Exception('Invalid item data');
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

                $this->logger->log('Subscriber found, attempting to update field.', 'debug', [
                    'email' => $email,
                    'id' => $subscriber_id,
                    'field_name' => $field_name
                ]);

                // Call the updateSubscriber method and check its return value for success
                $update_successful = $this->mailerLiteInstance->updateSubscriber($subscriber_id, $subscriber_data);

                if ($update_successful) {
                    $this->logger->log('Successfully updated subscriber field.', 'info', [
                        'email' => $email,
                        'field_name' => $field_name
                    ]);
                    return true;
                } else {
                    $this->logger->log('UpdateSubscriber API call failed.', 'error', [
                        'email' => $email,
                        'field_name' => $field_name
                    ]);
                    return false;
                }
            } else {
                // This block may be hit if getSubscriber() returns an empty array instead of throwing an exception.
                $this->logger->log('Subscriber not found for update.', 'error', ['email' => $email]);
                return false;
            }

        } catch (Exception $e) {
            $this->logger->log('Failed to update subscriber field status due to exception', 'error', [
                'email' => $email,
                'field_name' => $field_name,
                'error' => $e->getMessage()
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

                $this->logger->log('Purchase status updated', 'info', [
                    'email' => $email,
                    'campaign' => $campaign_code,
                    'field' => $field_name,
                    'purchased' => $has_purchased
                ]);
            }

            // Update local database
            $this->dbManager->updateSubscriberPurchaseStatus($email, $campaign_code, $has_purchased);
        } catch (Exception $e) {
            $this->logger->log('Failed to update purchase status', 'error', [
                'email' => $email,
                'campaign' => $campaign_code,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function stopSync(): bool
    {
        try {
            debug_to_file('Attempting to stop sync process', 'SYNC_STOP');

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

            $this->logger->log('Sync stopped by user', 'info');
            debug_to_file('Sync process stopped successfully', 'SYNC_STOP');

            return true;
        } catch (Exception $e) {
            debug_to_file([
                'error' => 'Failed to stop sync',
                'message' => $e->getMessage()
            ], 'SYNC_ERROR');

            $this->logger->log('Failed to stop sync', 'error', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function shouldStopSync(): bool
    {
        $stopFlag = get_option('bema_sync_stop_flag');
        if ($stopFlag) {
            debug_to_file('Stop flag detected - halting operation', 'SYNC_STOP');
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
            $this->logger->log('Failed to acquire sync lock', 'error');
            return false;
        }
        return true;
    }

    private function releaseSyncLock(): void
    {
        delete_transient('bema_sync_lock');
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

                $this->logger->log('Batch purchase updates completed', 'info', [
                    'campaign' => $campaign_code,
                    'batch_size' => count($batch)
                ]);
            } catch (Exception $e) {
                $this->dbManager->rollback();
                $this->logger->log('Batch purchase updates failed', 'error', [
                    'campaign' => $campaign_code,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }

            // Memory management
            $this->manageMemory();
        }
    }


    /**
     * get_album_details: returns album release year and artist name from a provided album name.
     * * @param string $album_name
     * @return array{artist: mixed, year: string}
     */
    public function get_album_details(string $album_name): array
    {
        global $wpdb;
        
        $album_name = sanitize_text_field($album_name);

        // Find the EDD download (post) by its title
        $query = $wpdb->prepare(
        "
        SELECT ID, post_date
        FROM {$wpdb->posts}
        WHERE post_title = %s
        AND post_type = 'download'
        AND post_status = 'publish'
        LIMIT 1
        ",
            $album_name
        );

        $album = $wpdb->get_row($query, ARRAY_A);

        if (empty($album)) {
            return [
                'artist' => '',
                'year' => '0',
            ];
        }

        $release_year = '0';
        if (!empty($album['post_date'])) {
            $dateArr = explode('-', $album['post_date']);
            $release_year = $dateArr[0];
        }

        $artist_name = '';

        // Fetch the artist name from the post's categories
        $artist_term = $wpdb->get_row(
            $wpdb->prepare(
                "
            SELECT t.name
            FROM {$wpdb->terms} AS t
            INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
            INNER JOIN {$wpdb->term_relationships} AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tr.object_id = %d
            AND tt.taxonomy = 'download_category'
            AND t.slug LIKE %s
            LIMIT 1
            ",
                $album['ID'],
                '%-artist' // Search for a term slug ending in '-artist'
            ),
            ARRAY_A
        );

        if (!empty($artist_term['name'])) {
            $artist_name = $artist_term['name'];
        }

        return [
            'artist' => $artist_name,
            'year' => $release_year,
        ];
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
            $this->logger->log('Failed to get subscriber status', 'error', [
                'email' => $email,
                'error' => $e->getMessage()
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
            $this->logger->log('Failed to get EDD purchase history', 'error', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return [
                'purchases' => [],
                'total_spent' => 0,
                'last_purchase' => null,
                'error' => $e->getMessage()
            ];
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
