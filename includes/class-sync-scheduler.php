<?php

namespace Bema;

use Exception;
use Bema\Interfaces\Lock_Handler;
use Bema\Interfaces\Health_Monitor;
use Bema\Interfaces\Stats_Collector;
use Bema\Handlers\Default_Lock_Handler;
use Bema\Handlers\Default_Health_Monitor;
use Bema\Handlers\Default_Stats_Collector;

if (!defined('ABSPATH')) {
    exit;
}

class Sync_Scheduler
{
    const HOURLY_HOOK = 'bema_crm_hourly_sync';
    const DAILY_HOOK = 'bema_crm_daily_sync';
    const WEEKLY_HOOK = 'bema_crm_weekly_sync';
    const CUSTOM_HOOK = 'bema_crm_custom_sync';
    const RETRY_HOOK = 'bema_crm_retry_sync';
    const HEALTH_CHECK_HOOK = 'bema_crm_health_check';

    const FAILED_JOBS_KEY = 'bema_sync_failed_jobs';
    const STATUS_KEY = 'bema_sync_status';
    const STATS_KEY = 'bema_sync_stats';
    const LOCK_KEY_PREFIX = 'bema_sync_lock_';

    const MAX_RETRIES = 3;
    const LOCK_TIMEOUT = 900; // 15 minutes
    const HEALTH_CHECK_INTERVAL = 300; // 5 minutes
    const MAX_EXECUTION_TIME = 3600; // 1 hour
    const BATCH_SIZE = 1000;

    private $logger;
    private $sync_instance;
    private $lockKey;
    private $maxMemoryUsage = '256M';
    private $memoryThreshold = 0.8;
    private $lockHandler;
    private $healthMonitor;
    private $statsCollector;
    private $sync_scheduler;

    private $defaultSettings = [
        'retry_delay' => 300,
        'batch_size' => 1000,
        'memory_limit' => '256M',
        'timeout' => 3600,
        'enable_logging' => true,
        'notification_email' => '',
        'parallel_jobs' => 1
    ];

    private static $instance = null;

    public static function get_instance(
        EM_Sync $sync_instance,
        ?Lock_Handler $lockHandler = null,
        ?Health_Monitor $healthMonitor = null,
        ?Stats_Collector $statsCollector = null,
        ?Bema_CRM_Logger $logger = null
    ): self {
        if (null === self::$instance) {
            self::$instance = new self($sync_instance, $lockHandler, $healthMonitor, $statsCollector, $logger);
        }
        return self::$instance;
    }

    private function __construct(
        EM_Sync $sync_instance,
        Lock_Handler $lockHandler = null,
        Health_Monitor $healthMonitor = null,
        Stats_Collector $statsCollector = null,
        ?Bema_CRM_Logger $logger = null
    ) {
        $this->logger = $logger ?? Bema_CRM_Logger::create('sync-scheduler');
        $this->sync_instance = $sync_instance;
        $this->lockHandler = $lockHandler ?? new Default_Lock_Handler();
        $this->healthMonitor = $healthMonitor ?? new Default_Health_Monitor();
        $this->statsCollector = $statsCollector ?? new Default_Stats_Collector();

        $this->lockKey = self::LOCK_KEY_PREFIX . wp_hash(__FILE__);
        $this->init();
    }

    private function init(): void
    {
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);

        // Register cron hooks
        $hooks = $this->get_sync_hooks();
        foreach ($hooks as $hook) {
            add_action($hook, [$this, "run_{$hook}"]);
        }

        // Make sure custom sync hook is registered
        add_action('bema_crm_custom_sync', [$this, 'run_bema_crm_custom_sync']);

        // Register health monitoring
        add_action(self::HEALTH_CHECK_HOOK, [$this, 'perform_health_check']);

        // Register deactivation
        register_deactivation_hook(BEMA_PATH . 'bema_crm.php', [$this, 'deactivate_scheduler']);

        // Recovery and monitoring
        add_action('init', [$this, 'initialize_monitoring']);

        $this->setup_default_schedules();
    }

    public function add_cron_intervals($schedules): array
    {
        $custom_intervals = [
            'every_five_minutes' => [
                'interval' => 300,
                'display' => 'Every Five Minutes'
            ],
            'every_fifteen_minutes' => [
                'interval' => 900,
                'display' => 'Every Fifteen Minutes'
            ],
            'twice_daily' => [
                'interval' => 43200,
                'display' => 'Twice Daily'
            ],
            'weekly' => [
                'interval' => 604800,
                'display' => 'Weekly'
            ]
        ];

        return array_merge($schedules, $custom_intervals);
    }

    public function schedule_sync(string $frequency, array $campaigns): bool
    {
        try {
            debug_to_file([
                'frequency' => $frequency,
                'campaigns' => $campaigns
            ], 'SCHEDULE_SYNC');

            // Process tier transitions for each campaign
            foreach ($campaigns as $campaign) {
                $this->processTierTransitions($campaign);
            }

            // For custom (immediate) syncs, run directly
            if ($frequency === 'custom') {
                return $this->start_immediate_sync($campaigns);
            }

            // For scheduled syncs
            $settings = $this->prepare_sync_settings($frequency, []);
            $hook = $this->get_hook_for_frequency($frequency);
            $timestamp = $this->calculate_next_run_time($frequency);

            $args = [
                'campaigns' => $campaigns,
                'settings' => array_merge($settings, [
                    'start_time' => $timestamp,
                    'status' => 'scheduled'
                ])
            ];

            // Clear any existing schedule
            $this->clear_existing_schedule($hook);

            // Schedule new event
            $scheduled = wp_schedule_event($timestamp, $frequency, $hook, [$args]);

            debug_to_file([
                'hook' => $hook,
                'timestamp' => $timestamp,
                'args' => $args
            ], 'SCHEDULE_SYNC');

            if ($scheduled) {
                return $this->register_schedule($frequency, $campaigns);
            }

            return false;
        } catch (Exception $e) {
            debug_to_file([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'SCHEDULE_SYNC_ERROR');

            return false;
        }
    }

    private function start_immediate_sync(array $campaigns): bool
    {
        try {
            // Validate sync state first
            $this->validate_sync_state();

            // Execute the sync
            return $this->execute_sync($campaigns);
        } catch (Exception $e) {
            debug_to_file([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'SYNC_START_ERROR');

            return false;
        }
    }

    private function execute_sync(array $campaigns): bool
    {
        try {
            // Get lock
            $lock_key = 'bema_sync_lock';
            if (get_transient($lock_key)) {
                throw new Exception('Another sync is already in progress');
            }

            // Set lock with 30-minute timeout
            set_transient($lock_key, time(), 1800);

            try {
                debug_to_file([
                    'starting_sync' => true,
                    'campaigns' => $campaigns,
                    'time' => time()
                ], 'SYNC_EXECUTE');

                // Set initial status
                update_option('bema_sync_status', [
                    'status' => 'running',
                    'start_time' => time(),
                    'processed' => 0,
                    'total' => 0,
                    'current_campaign' => null
                ], false);

                // Run sync directly
                $result = $this->sync_instance->syncAll($campaigns);

                // Update final status
                update_option('bema_sync_status', [
                    'status' => $result ? 'completed' : 'failed',
                    'end_time' => time(),
                    'last_sync' => current_time('mysql')
                ], false);

                debug_to_file([
                    'sync_completed' => true,
                    'result' => $result ? 'success' : 'failed',
                    'end_time' => time()
                ], 'SYNC_EXECUTE');

                return $result;
            } finally {
                delete_transient($lock_key);
            }
        } catch (Exception $e) {
            debug_to_file([
                'sync_failed' => true,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'SYNC_EXECUTE_ERROR');

            update_option('bema_sync_status', [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'end_time' => time()
            ], false);

            return false;
        }
    }

    private function validate_sync_state(): void
    {
        // Check if there's an existing sync
        $current_status = get_option('bema_sync_status', ['status' => 'idle']);

        debug_to_file([
            'current_sync_status' => $current_status
        ], 'SYNC_VALIDATE');

        // If status is running but lock doesn't exist, clean up
        if ($current_status['status'] === 'running' && !get_transient('bema_sync_lock')) {
            debug_to_file('Found stale running status without lock', 'SYNC_VALIDATE');
            $this->cleanup_existing_sync();
            return;
        }

        // If lock exists but status isn't running, clean up
        if (get_transient('bema_sync_lock') && $current_status['status'] !== 'running') {
            debug_to_file('Found lock without running status', 'SYNC_VALIDATE');
            $this->cleanup_existing_sync();
            return;
        }

        debug_to_file('Sync state validated', 'SYNC_VALIDATE');
    }

    private function cleanup_existing_sync(): void
    {
        // Clear any running sync flags
        delete_transient('bema_sync_lock');

        // Update sync status
        update_option('bema_sync_status', [
            'status' => 'idle',
            'end_time' => time(),
            'processed' => 0,
            'total' => 0
        ], false);

        // Clear any scheduled sync events
        if ($this->sync_scheduler) {
            $this->cleanup_existing_schedule();
        }

        debug_to_file('Cleaned up existing sync state', 'SYNC_CLEANUP');
    }

    /**
     * Process tier transitions for a campaign
     */
    private function processTierTransitions(array $campaign): void
    {
        try {
            if (!isset($campaign['groups'])) {
                return;
            }

            $transitions = [];

            foreach ($campaign['groups'] as $currentTier => $groupId) {
                // Get next tier based on current tier
                $nextTier = $this->getNextTier($currentTier);
                if (!$nextTier) {
                    continue;
                }

                $transitions[] = [
                    'campaign' => $campaign['name'],
                    'from_tier' => $currentTier,
                    'to_tier' => $nextTier,
                    'timestamp' => current_time('mysql')
                ];
            }

            if (!empty($transitions)) {
                $this->trackTierTransitions($transitions);
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to process tier transitions', [
                'campaign' => $campaign['name'],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get next tier based on current tier
     */
    private function getNextTier(string $currentTier): ?string
    {
        $tierHierarchy = [
            'optin' => 'bronze',
            'bronze' => 'silver',
            'silver' => 'gold',
            'gold' => null // Top tier
        ];

        return $tierHierarchy[$currentTier] ?? null;
    }

    private function trackTierTransitions(array $transitions): void
    {
        try {
            $this->logger->info('Tracking tier transitions', [
                'transitions' => $transitions
            ]);

            update_option('bema_tier_transitions', [
                'transitions' => $transitions,
                'last_update' => current_time('mysql')
            ], false);
        } catch (Exception $e) {
            $this->logger->error('Failed to track tier transitions', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function register_schedule(string $frequency, array $campaigns): bool
    {
        try {
            // Store schedule configuration instead of using wp-cron
            $schedules = get_option('bema_sync_schedules', []);

            $schedules[$frequency] = [
                'campaigns' => $campaigns,
                'last_run' => time(),
                'next_run' => $this->calculate_next_run_time($frequency),
                'enabled' => true
            ];

            update_option('bema_sync_schedules', $schedules, false);

            debug_to_file([
                'message' => 'Schedule registered',
                'frequency' => $frequency,
                'next_run' => date('Y-m-d H:i:s', $schedules[$frequency]['next_run'])
            ], 'SCHEDULE_SYNC');

            return true;
        } catch (Exception $e) {
            debug_to_file([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'SCHEDULE_REGISTER_ERROR');

            return false;
        }
    }

    public function setup_default_schedules(): void
    {
        try {
            // Register default schedules without immediate execution
            $this->register_schedule('hourly', []);
            $this->register_schedule('daily', []);
        } catch (Exception $e) {
            debug_to_file([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'SCHEDULE_SETUP_ERROR');
        }
    }

    public function cleanup_existing_schedule(): void
    {
        // Clear any existing schedules
        $hooks = $this->get_sync_hooks();
        foreach ($hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        }

        // Clear transients and options
        delete_transient('bema_sync_lock');
        wp_cache_delete('bema_sync_status');

        debug_to_file('Cleaned up existing sync schedule', 'SYNC_CLEANUP');
    }

    public function run_bema_crm_custom_sync($args = []): void
    {
        try {
            if (empty($args['campaigns'])) {
                throw new Exception('No campaigns provided for sync');
            }

            // Check if sync is already running
            $current_status = get_option('bema_sync_status', ['status' => 'idle']);
            if ($current_status['status'] === 'running') {
                throw new Exception('Sync is already in progress');
            }

            debug_to_file([
                'starting_custom_sync' => true,
                'campaigns' => $args['campaigns'],
                'settings' => $args['settings'] ?? []
            ], 'CUSTOM_SYNC');

            // Update status to running
            update_option('bema_sync_status', [
                'status' => 'running',
                'start_time' => time(),
                'current_campaign' => null
            ]);

            // Run sync with provided campaigns
            $result = $this->sync_instance->syncAll($args['campaigns']);

            // Update final status
            update_option('bema_sync_status', [
                'status' => $result ? 'completed' : 'failed',
                'end_time' => time()
            ]);
        } catch (Exception $e) {
            update_option('bema_sync_status', [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'end_time' => time()
            ]);

            $this->logger->error('Custom sync failed', [
                'error' => $e->getMessage(),
                'args' => $args
            ]);
        }
    }

    private function prepare_sync_settings(string $frequency, array $options): array
    {
        return array_merge(
            $this->defaultSettings,
            [
                'frequency' => $frequency,
                'start_time' => time(),
                'last_run' => null,
                'status' => 'scheduled'
            ],
            $options
        );
    }

    private function run_sync_job(string $type, array $campaigns, array $settings = []): void
    {
        if (!$this->can_start_sync($type)) {
            return;
        }

        $jobId = uniqid('sync_', true);

        try {
            $this->prepare_sync_environment($settings);
            $this->log_sync_start($type, $jobId, $campaigns);

            $this->healthMonitor->startMonitoring($jobId);
            $this->process_campaigns($campaigns, $settings);

            $this->finalize_sync_job($type, $jobId, true);
        } catch (Exception $e) {
            $this->handle_sync_error($e, $type, $jobId);
            $this->finalize_sync_job($type, $jobId, false);
        }
    }

    // Add these methods to your Sync_Scheduler class
    public function run_bema_crm_hourly_sync($args = []): void
    {
        try {
            $this->run_sync_job('hourly', $args['campaigns'] ?? [], $args['settings'] ?? []);
        } catch (Exception $e) {
            $this->logger->error('Hourly sync failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function run_bema_crm_daily_sync($args = []): void
    {
        try {
            $this->run_sync_job('daily', $args['campaigns'] ?? [], $args['settings'] ?? []);
        } catch (Exception $e) {
            $this->logger->error('Daily sync failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function run_bema_crm_weekly_sync($args = []): void
    {
        try {
            $this->run_sync_job('weekly', $args['campaigns'] ?? [], $args['settings'] ?? []);
        } catch (Exception $e) {
            $this->logger->error('Weekly sync failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function run_bema_crm_retry_sync($args = []): void
    {
        try {
            $this->run_sync_job('retry', $args['campaigns'] ?? [], $args['settings'] ?? []);
        } catch (Exception $e) {
            $this->logger->error('Retry sync failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function process_campaigns(array $campaigns, array $settings): void
    {
        try {
            $batch_processor = new SyncBatchProcessor(
                $this->logger,
                [$this->sync_instance, 'syncAll'],
                function (int $current, int $total, array $metadata = []) {
                    $this->update_progress($current, $total, $metadata);
                },
                function (array $batch = null, Exception $error = null) {
                    $this->handle_batch_failure($batch, $error);
                }
            );

            $batch_processor->setBatchSize($settings['batch_size'] ?? self::BATCH_SIZE);

            foreach ($campaigns as $campaign) {
                $this->process_single_campaign($campaign, $batch_processor);
                $this->manage_memory();
            }
        } catch (Exception $e) {
            $this->logger->error('Campaign processing failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function process_single_campaign($campaign, $batch_processor): void
    {
        $this->healthMonitor->checkCampaign($campaign);

        try {
            $this->statsCollector->startCampaign($campaign);
            $result = $batch_processor->processBatch([$campaign]);
            $this->statsCollector->endCampaign($campaign, $result);

            $this->mark_campaign_success($campaign);
        } catch (Exception $e) {
            $this->handle_campaign_error($campaign, $e);
        }
    }

    private function can_start_sync(string $type): bool
    {
        return !$this->is_sync_running($type) && $this->lockHandler->acquireLock($this->get_lock_key($type));
    }

    private function is_sync_running(string $type): bool
    {
        $status = get_option(self::STATUS_KEY, []);
        return isset($status[$type]) && $status[$type]['status'] === 'running' &&
            (time() - strtotime($status[$type]['last_run'])) < self::MAX_EXECUTION_TIME;
    }

    private function prepare_sync_environment(array $settings): void
    {
        ini_set('memory_limit', $settings['memory_limit'] ?? $this->maxMemoryUsage);
        set_time_limit($settings['timeout'] ?? self::MAX_EXECUTION_TIME);
    }

    private function manage_memory(): void
    {
        if (memory_get_usage(true) > $this->get_memory_limit() * $this->memoryThreshold) {
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            $this->statsCollector->recordMemoryCleanup();
        }
    }

    private function get_memory_limit(): int
    {
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit === '-1')
            return PHP_INT_MAX;

        preg_match('/^(\d+)(.)$/', $memory_limit, $matches);
        if (!$matches)
            return 256 * 1024 * 1024;

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

    public function initialize_monitoring(): void
    {
        try {
            // Initialize health monitoring system
            if (method_exists($this->healthMonitor, 'initializeMonitoring')) {
                $this->healthMonitor->initializeMonitoring();
            }

            // Schedule health check if not already scheduled
            if (!wp_next_scheduled(self::HEALTH_CHECK_HOOK)) {
                wp_schedule_event(
                    time(),
                    'every_five_minutes',
                    self::HEALTH_CHECK_HOOK
                );
            }

            // Clean up any stale locks
            $this->cleanup_stale_locks();

            // Initialize stats collection
            if (method_exists($this->statsCollector, 'initialize')) {
                $this->statsCollector->initialize();
            }

            $this->logger->info('Monitoring system initialized');
        } catch (Exception $e) {
            $this->logger->error('Failed to initialize monitoring', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function perform_health_check(): void
    {
        $this->healthMonitor->performCheck();
        $this->cleanup_stale_locks();
        $this->retry_failed_jobs();
        $this->update_sync_statistics();
    }

    private function cleanup_stale_locks(): void
    {
        $locks = $this->lockHandler->getActiveLocks();
        foreach ($locks as $lock) {
            if (time() - $lock['timestamp'] > self::LOCK_TIMEOUT) {
                $this->lockHandler->releaseLock($lock['key']);
                $this->log_stale_lock_cleanup($lock);
            }
        }
    }

    private function retry_failed_jobs(): void
    {
        $failed_jobs = get_option(self::FAILED_JOBS_KEY, []);
        foreach ($failed_jobs as $campaign => $data) {
            if ($this->should_retry_job($data)) {
                $this->schedule_retry($campaign, $data);
            }
        }
    }

    private function should_retry_job(array $data): bool
    {
        return $data['retries'] < self::MAX_RETRIES &&
            (time() - $data['last_attempt']) > $this->calculate_retry_delay($data['retries']);
    }

    private function calculate_retry_delay(int $retries): int
    {
        return min(300 * pow(2, $retries), 86400); // Max 24 hours
    }

    private function update_sync_statistics(): void
    {
        $this->statsCollector->updateStats();
    }

    public function get_sync_status(): array
    {
        return [
            'status' => get_option(self::STATUS_KEY, []),
            'failed_jobs' => get_option(self::FAILED_JOBS_KEY, []),
            'stats' => get_option(self::STATS_KEY, []),
            'next_runs' => $this->get_next_sync(),
            'health' => $this->healthMonitor->getStatus(),
        ];
    }

    /**
     * Updates the progress of the current sync operation
     */
    public function update_progress(int $current, int $total, array $metadata = []): void
    {
        try {
            $progress = [
                'current' => $current,
                'total' => $total,
                'percentage' => $total > 0 ? round(($current / $total) * 100, 2) : 0,
                'memory_usage' => memory_get_usage(true),
                'timestamp' => current_time('mysql'),
                'metadata' => $metadata
            ];

            update_option('bema_sync_progress', $progress, false);

            $this->logger->debug('Sync progress updated', [
                'progress' => $progress
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to update progress', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function check_and_clear_stale_lock(): bool
    {
        $lock_key = 'bema_sync_lock';
        $lock_time = get_transient($lock_key);

        // If there's a lock, check if it's stale
        if ($lock_time) {
            $current_status = get_option('bema_sync_status', ['status' => 'idle']);
            $lock_age = time() - $lock_time;

            debug_to_file([
                'lock_age' => $lock_age,
                'current_status' => $current_status,
            ], 'LOCK_CHECK');

            // If lock is older than 30 minutes or status is not actually running
            if ($lock_age > 1800 || $current_status['status'] !== 'running') {
                delete_transient($lock_key);
                update_option('bema_sync_status', [
                    'status' => 'idle',
                    'end_time' => time(),
                    'error' => 'Previous sync lock cleared due to timeout'
                ], false);

                debug_to_file('Cleared stale sync lock', 'LOCK_CLEANUP');
                return true;
            }
            return false;
        }
        return true;
    }

    /**
     * Handles batch processing failures
     */
    public function handle_batch_failure(array $batch = null, Exception $error = null): void
    {
        try {
            $failureData = [
                'timestamp' => current_time('mysql'),
                'error' => $error ? $error->getMessage() : 'Unknown error',
                'batch_size' => $batch ? count($batch) : 0,
                'memory_usage' => memory_get_usage(true)
            ];

            // Store failure data
            $failures = get_option('bema_sync_failures', []);
            array_unshift($failures, $failureData);
            $failures = array_slice($failures, 0, 100); // Keep last 100 failures
            update_option('bema_sync_failures', $failures);

            $this->logger->error('Batch failure recorded', [
                'failure' => $failureData
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to handle batch failure', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function log_sync_start(string $type, string $jobId, array $campaigns): void
    {
        $this->logger->info("Starting $type sync", [
            'job_id' => $jobId,
            'campaigns' => $campaigns,
            'memory' => memory_get_usage(true)
        ]);
    }

    private function finalize_sync_job(string $type, string $jobId, bool $success): void
    {
        $this->healthMonitor->stopMonitoring($jobId);
        $this->lockHandler->releaseLock($this->get_lock_key($type));

        $status = $success ? 'completed' : 'failed';
        $this->update_sync_status($type, $status);

        $this->logger->info("$type sync $status", [
            'job_id' => $jobId,
            'memory_peak' => memory_get_peak_usage(true)
        ]);
    }

    public function deactivate_scheduler(): void
    {
        try {
            // Clear all scheduled events
            wp_clear_scheduled_hook('bema_daily_sync');
            wp_clear_scheduled_hook('bema_retry_failed_jobs');

            // Clean up any running sync state
            $this->cleanup_existing_sync();

            $this->logger->info('Scheduler deactivated successfully');
        } catch (Exception $e) {
            // Just log the error but don't throw
            $this->logger->error('Error deactivating scheduler', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function get_sync_hooks(): array
    {
        return [
            self::HOURLY_HOOK,
            self::DAILY_HOOK,
            self::WEEKLY_HOOK,
            self::CUSTOM_HOOK,
            self::RETRY_HOOK
        ];
    }

    private function get_lock_key(string $type): string
    {
        return self::LOCK_KEY_PREFIX . $type;
    }

    public function getMaxRetries(): int
    {
        return self::MAX_RETRIES;
    }

    private function validate_schedule_request(string $frequency, array $campaigns): void
    {
        if (!in_array($frequency, ['hourly', 'daily', 'weekly', 'custom'])) {
            throw new Exception("Invalid frequency: $frequency");
        }
    }

    private function get_hook_for_frequency(string $frequency): string
    {
        $hooks = [
            'hourly' => self::HOURLY_HOOK,
            'daily' => self::DAILY_HOOK,
            'weekly' => self::WEEKLY_HOOK,
            'custom' => self::CUSTOM_HOOK
        ];
        return $hooks[$frequency] ?? self::CUSTOM_HOOK;
    }

    private function clear_existing_schedule(string $hook): void
    {
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
        }
    }

    private function calculate_next_run_time(string $frequency): int
    {
        switch ($frequency) {
            case 'hourly':
                return strtotime('+1 hour');
            case 'daily':
                return strtotime('tomorrow midnight');
            case 'weekly':
                return strtotime('next monday');
            default:
                return time();
        }
    }

    private function log_schedule_creation(string $frequency, array $campaigns, int $timestamp): void
    {
        $this->logger->info('Schedule created', [
            'frequency' => $frequency,
            'campaigns' => $campaigns,
            'next_run' => date('Y-m-d H:i:s', $timestamp)
        ]);
    }

    private function handle_scheduling_error(Exception $e, string $frequency, array $campaigns): void
    {
        $this->logger->error('Schedule creation failed', [
            'frequency' => $frequency,
            'campaigns' => $campaigns,
            'error' => $e->getMessage()
        ]);
    }

    private function handle_sync_error(Exception $e, string $type, string $jobId): void
    {
        $this->logger->error('Sync failed', [
            'type' => $type,
            'job_id' => $jobId,
            'error' => $e->getMessage()
        ]);
    }

    private function mark_campaign_success(string $campaign): void
    {
            
    }

    private function handle_campaign_error(string $campaign, Exception $e): void
    {
        $this->logger->error('Campaign failed', [
            'campaign' => $campaign,
            'error' => $e->getMessage()
        ]);
    }

    private function log_stale_lock_cleanup(array $lock): void
    {
        $this->logger->info('Cleaned up stale lock', [
            'key' => $lock['key'],
            'age' => time() - $lock['timestamp']
        ]);
    }

    private function schedule_retry(string $campaign, array $data): void
    {
        wp_schedule_single_event(
            time() + $this->calculate_retry_delay($data['retries']),
            self::RETRY_HOOK,
            ['campaign' => $campaign]
        );
    }

    private function get_next_sync(): array
    {
        $schedules = [];
        foreach ($this->get_sync_hooks() as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                $schedules[$hook] = [
                    'next_run' => date('Y-m-d H:i:s', $timestamp),
                    'seconds_remaining' => $timestamp - time()
                ];
            }
        }
        return $schedules;
    }

    private function update_sync_status(string $type, string $status, ?string $error = null): void
    {
        $currentStatus = get_option(self::STATUS_KEY, []);
        $currentStatus[$type] = [
            'status' => $status,
            'last_run' => current_time('mysql'),
            'error' => $error
        ];
        update_option(self::STATUS_KEY, $currentStatus);
    }

    public function cancel_sync(): void
    {
        try {
            // Set the stop flag first
            update_option('bema_sync_stop_flag', true, false);

            $currentJobs = $this->get_running_jobs();
            foreach ($currentJobs as $job) {
                wp_clear_scheduled_hook($job['hook'], $job['args'] ?? []);
            }

            // Clean up sync state
            $this->cleanup_existing_sync();

            // Force status update
            update_option(self::STATUS_KEY, [
                'status' => 'stopped',
                'end_time' => time(),
                'error' => null
            ]);

            // Clear all locks and transients
            delete_transient('bema_sync_lock');
            wp_cache_delete('sync_in_progress', 'bema_sync');
            delete_option('bema_current_sync');

            // Abort any pending API requests
            if ($this->sync_instance) {
                $this->sync_instance->stopSync();
            }

            $this->logger->info('Sync cancelled by user');
        } catch (Exception $e) {
            $this->logger->error('Failed to cancel sync', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function get_running_jobs(): array
    {
        $jobs = [];
        foreach ($this->get_sync_hooks() as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                $jobs[] = [
                    'hook' => $hook,
                    'timestamp' => $timestamp
                ];
            }
        }
        return $jobs;
    }
}
