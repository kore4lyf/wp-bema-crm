<?php

namespace Bema;

use Exception;
use Bema\Exceptions\Database_Exception;

if (!defined('ABSPATH')) {
    exit;
}

class Database_Manager
{
    private $wpdb;
    private $logger;
    private $tables;
    private $inTransaction = false;
    private $batchSize = 1000;
    private $cache;
    private $cacheEnabled = true;
    private $maxRetries = 3;
    private $retryDelay = 1;
    private $transactionTimeout = 30;
    private $deadlockRetries = 3;

    // Constants
    const CACHE_GROUP = 'bema_db_cache';
    const CACHE_TTL = 3600; // 1 hour
    const MAX_PACKET_SIZE = 1048576; // 1MB (default MySQL max_allowed_packet)
    const MAX_CACHE_SIZE = 10000;
    const LOCK_TIMEOUT = 10; // seconds
    const DEADLOCK_RETRY_DELAY = 1; // seconds

    public function __construct($wpdb, BemaCRMLogger $logger)
    {
        $this->wpdb = $wpdb;
        $this->logger = $logger;

        // Set error handler
        set_error_handler([$this, 'errorHandler']);

        $this->initializeTables();
        $this->initializeCache();

        // Set transaction isolation level
        $this->setTransactionIsolationLevel();
    }

    private function initializeTables(): void
    {
        $this->tables = [
            'crm' => $this->wpdb->prefix . 'bemacrmmeta',
            'subscribers' => $this->wpdb->prefix . 'subscribers',
            'sync_logs' => $this->wpdb->prefix . 'sync_logs'
        ];

        // Validate tables exist
        foreach ($this->tables as $name => $table) {
            if (!$this->tableExists($table)) {
                $this->logger->log("Table {$table} does not exist", 'error');
                throw new Database_Exception("Required table {$table} does not exist");
            }
        }
    }

    private function tableExists(string $table): bool
    {
        return $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table
            )
        ) !== null;
    }

    private function initializeCache(): void
    {
        $this->cache = wp_cache_get_multiple(['enabled', 'ttl'], self::CACHE_GROUP);
        if (empty($this->cache)) {
            wp_cache_set('enabled', true, self::CACHE_GROUP);
            wp_cache_set('ttl', self::CACHE_TTL, self::CACHE_GROUP);
        }

        $this->validateCacheIntegrity();
    }

    private function validateCacheIntegrity(): void
    {
        try {
            $testKey = 'test_' . uniqid();
            wp_cache_set($testKey, true, self::CACHE_GROUP, 60);
            if (!wp_cache_get($testKey, self::CACHE_GROUP)) {
                throw new Database_Exception('Cache validation failed');
            }
            wp_cache_delete($testKey, self::CACHE_GROUP);
        } catch (Exception $e) {
            $this->logger->log('Cache integrity check failed', 'error', [
                'error' => $e->getMessage()
            ]);
            $this->disableCache();
        }
    }

    /**
     * Update subscriber tier and related information
     * @param string $email Subscriber email
     * @param string $campaign Campaign code
     * @param string $tier New tier
     * @return bool
     */
    public function updateSubscriberTier(string $email, string $campaign, string $tier): bool
    {
        try {
            // Update in table
            $result = $this->wpdb->update(
                $this->wpdb->prefix . 'bemacrmmeta',
                [
                    'tier' => $tier,
                    'date_modified' => current_time('mysql'),
                    'campaign' => $campaign,
                    'sync_status' => 'tier_updated'
                ],
                ['subscriber' => $email],
                ['%s', '%s', '%s', '%s'], // value formats
                ['%s'] // where format
            );

            // Also update subscribers table
            $this->wpdb->update(
                $this->wpdb->prefix . 'subscribers',
                [
                    'tier' => $tier,
                    'last_sync' => current_time('mysql')
                ],
                ['email' => $email],
                ['%s', '%s'],
                ['%s']
            );

            // Log the tier update
            $this->logTierUpdate($email, $campaign, $tier);

            return $result !== false;
        } catch (Exception $e) {
            $this->logger->log('Failed to update subscriber tier', 'error', [
                'email' => $email,
                'campaign' => $campaign,
                'tier' => $tier,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Log tier update in sync_logs table
     */
    private function logTierUpdate(string $email, string $campaign, string $tier): void
    {
        try {
            $this->wpdb->insert(
                $this->wpdb->prefix . 'sync_logs',
                [
                    'operation' => 'tier_update',
                    'campaign' => $campaign,
                    'status' => 'completed',
                    'data' => wp_json_encode([
                        'email' => $email,
                        'new_tier' => $tier,
                        'timestamp' => current_time('mysql')
                    ])
                ],
                ['%s', '%s', '%s', '%s']
            );
        } catch (Exception $e) {
            $this->logger->log('Failed to log tier update', 'error', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update subscriber group mapping
     */
    public function updateSubscriberGroup(string $email, string $groupId, string $campaign): bool
    {
        try {
            $data = [
                'subscriber' => $email,
                'mailerlite_group_id' => $groupId,
                'campaign' => $campaign,
                'date_modified' => current_time('mysql')
            ];

            // Update or insert
            $existing = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->wpdb->prefix}bemacrmmeta 
                WHERE subscriber = %s AND campaign = %s",
                    $email,
                    $campaign
                )
            );

            if ($existing) {
                $result = $this->wpdb->update(
                    $this->wpdb->prefix . 'bemacrmmeta',
                    $data,
                    [
                        'subscriber' => $email,
                        'campaign' => $campaign
                    ]
                );
            } else {
                $data['date_added'] = current_time('mysql');
                $result = $this->wpdb->insert(
                    $this->wpdb->prefix . 'bemacrmmeta',
                    $data
                );
            }

            if ($result === false) {
                throw new Exception($this->wpdb->last_error);
            }

            $this->logger->log('Group mapping updated', 'info', [
                'email' => $email,
                'group_id' => $groupId,
                'campaign' => $campaign
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->log('Failed to update group mapping', 'error', [
                'email' => $email,
                'group_id' => $groupId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    // In class.database-manager.php
    public function logTransition(string $email, string $fromCampaign, string $toCampaign, string $fromTier): int
    {
        $this->wpdb->insert(
            $this->wpdb->prefix . 'transitions',
            [
                'email' => $email,
                'from_campaign' => $fromCampaign,
                'to_campaign' => $toCampaign,
                'from_tier' => $fromTier,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ]
        );
        return $this->wpdb->insert_id;
    }

    public function updateTransitionAttempt(int $transitionId, int $attempts): void
    {
        $this->wpdb->update(
            $this->wpdb->prefix . 'transitions',
            [
                'attempts' => $attempts,
                'last_attempt' => current_time('mysql')
            ],
            ['id' => $transitionId]
        );
    }

    public function completeTransition(int $transitionId, array $data): void
    {
        $this->wpdb->update(
            $this->wpdb->prefix . 'transitions',
            [
                'to_tier' => $data['to_tier'],
                'status' => $data['status'],
                'completed_at' => $data['completed_at']
            ],
            ['id' => $transitionId]
        );
    }

    public function updateTransition(int $transitionId, array $data): void
    {
        $this->wpdb->update(
            $this->wpdb->prefix . 'transitions',
            $data,
            ['id' => $transitionId]
        );
    }

    /**
     * Update subscriber purchase status for a specific campaign
     */
    public function updateSubscriberPurchaseStatus(string $email, string $campaign_code, bool $has_purchased): bool
    {
        try {
            $result = $this->wpdb->update(
                $this->wpdb->prefix . 'bemacrmmeta',
                [
                    'subscriber' => $email,
                    'campaign' => $campaign_code,
                    'purchase_indicator' => $has_purchased ? 1 : 0
                ],
                [
                    'subscriber' => $email,
                    'campaign' => $campaign_code
                ],
                [
                    '%s', // subscriber
                    '%s', // campaign
                    '%d'  // purchase_indicator
                ],
                [
                    '%s', // where subscriber
                    '%s'  // where campaign
                ]
            );

            if ($result === false) {
                throw new Exception($this->wpdb->last_error);
            }

            $this->logger->log('Purchase status updated in database', 'info', [
                'email' => $email,
                'campaign' => $campaign_code,
                'purchased' => $has_purchased
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->log('Failed to update purchase status', 'error', [
                'email' => $email,
                'campaign' => $campaign_code,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function setTransactionIsolationLevel(): void
    {
        try {
            $this->wpdb->query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
            $this->logger->log('Transaction isolation level set', 'debug');
        } catch (Exception $e) {
            $this->logger->log('Failed to set transaction isolation level', 'warning', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function beginTransaction(): void
    {
        if ($this->inTransaction) {
            throw new Database_Exception('Transaction already in progress');
        }

        try {
            // Set wait timeout
            $this->wpdb->query("SET SESSION wait_timeout = " . ($this->transactionTimeout + 5));

            // Acquire lock
            $lockResult = $this->wpdb->get_var("SELECT GET_LOCK('bema_sync_lock', " . self::LOCK_TIMEOUT . ")");
            if ($lockResult != 1) {
                throw new Database_Exception('Failed to acquire database lock');
            }

            $this->wpdb->query('START TRANSACTION');
            $this->inTransaction = true;

            $this->logger->log('Database transaction started', 'info', [
                'connection_id' => $this->wpdb->get_var("SELECT CONNECTION_ID()")
            ]);
        } catch (Exception $e) {
            $this->logger->log('Failed to begin transaction', 'error', [
                'error' => $e->getMessage()
            ]);
            throw new Database_Exception('Failed to begin transaction: ' . $e->getMessage());
        }
    }

    public function beginTransactionWithRetry(int $maxRetries = 3): void
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxRetries) {
            try {
                $this->beginTransaction();
                return;
            } catch (Exception $e) {
                $lastError = $e;
                $attempt++;

                $this->logger->log('Retrying transaction start', 'warning', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage()
                ]);

                if ($attempt < $maxRetries) {
                    sleep($this->retryDelay * $attempt);
                }
            }
        }

        throw new Database_Exception(
            "Failed to begin transaction after {$maxRetries} attempts: {$lastError->getMessage()}"
        );
    }

    public function commit(): void
    {
        if (!$this->inTransaction) {
            throw new Database_Exception('No transaction to commit');
        }

        try {
            $this->wpdb->query('COMMIT');
            $this->wpdb->query("SELECT RELEASE_LOCK('bema_sync_lock')");
            $this->inTransaction = false;

            $this->logger->log('Database transaction committed', 'info', [
                'connection_id' => $this->wpdb->get_var("SELECT CONNECTION_ID()")
            ]);
        } catch (Exception $e) {
            $this->logger->log('Failed to commit transaction', 'error', [
                'error' => $e->getMessage()
            ]);
            throw new Database_Exception('Failed to commit transaction: ' . $e->getMessage());
        }
    }

    public function rollback(): void
    {
        if (!$this->inTransaction) {
            throw new Database_Exception('No transaction to rollback');
        }

        try {
            $this->wpdb->query('ROLLBACK');
            $this->wpdb->query("SELECT RELEASE_LOCK('bema_sync_lock')");
            $this->inTransaction = false;

            $this->logger->log('Database transaction rolled back', 'info', [
                'connection_id' => $this->wpdb->get_var("SELECT CONNECTION_ID()")
            ]);
        } catch (Exception $e) {
            $this->logger->log('Failed to rollback transaction', 'error', [
                'error' => $e->getMessage()
            ]);
            throw new Database_Exception('Failed to rollback transaction: ' . $e->getMessage());
        }
    }

    public function batchUpdateSubscribers(array $subscribers): int
    {
        if (!$this->inTransaction) {
            throw new Database_Exception("Batch update must be performed within a transaction");
        }

        $totalUpdated = 0;
        $batches = array_chunk($subscribers, $this->batchSize);

        foreach ($batches as $batchIndex => $batch) {
            try {
                $result = $this->processBatch($batch, $batchIndex);
                $totalUpdated += $result;

                $this->logger->log('Batch processed successfully', 'debug', [
                    'batch_index' => $batchIndex,
                    'processed' => $result,
                    'total_updated' => $totalUpdated
                ]);

                // Memory management
                if ($batchIndex % 5 === 0) {
                    $this->manageMemory();
                }
            } catch (Exception $e) {
                $this->logger->log('Batch processing failed', 'error', [
                    'batch_index' => $batchIndex,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        return $totalUpdated;
    }

    public function optimize_tables(): void
    {
        try {
            // Analyze tables
            $tables = [
                $this->wpdb->prefix . 'bemacrmmeta',
                $this->wpdb->prefix . 'sync_logs'
            ];

            foreach ($tables as $table) {
                $this->wpdb->query("ANALYZE TABLE $table");
                $this->wpdb->query("OPTIMIZE TABLE $table");
            }

            // Update table statistics
            $this->wpdb->query("
            UPDATE mysql.innodb_table_stats SET
            n_rows = (SELECT COUNT(*) FROM {$this->wpdb->prefix}bemacrmmeta)
            WHERE table_name = '{$this->wpdb->prefix}bemacrmmeta'
        ");

            // Set session variables for better performance
            $this->wpdb->query("SET SESSION sql_mode=''");
            $this->wpdb->query("SET SESSION transaction_isolation='READ-COMMITTED'");

            $this->logger->log('Database tables optimized', 'info');
        } catch (Exception $e) {
            $this->logger->log('Failed to optimize tables', 'error', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function processBatch(array $batch, int $batchIndex): int
    {
        $values = [];
        $placeholders = [];
        $updates = [];
        $processedCount = 0;

        foreach ($batch as $subscriber) {
            if (!isset($subscriber['bema_id'])) {
                $this->logger->log('Invalid subscriber data', 'warning', [
                    'batch_index' => $batchIndex,
                    'data' => $subscriber
                ]);
                continue;
            }

            $sanitizedData = $this->sanitizeData($subscriber);
            $placeholder = $this->buildPlaceholder($sanitizedData);
            $values = array_merge($values, array_values($sanitizedData));
            $placeholders[] = $placeholder;

            foreach ($sanitizedData as $key => $value) {
                $updates[] = "{$key} = VALUES({$key})";
            }

            $processedCount++;
        }

        if (empty($placeholders)) {
            return 0;
        }

        return $this->executeUpdate($values, $placeholders, array_unique($updates));
    }

    private function executeUpdate(array $values, array $placeholders, array $updates): int
    {
        $query = $this->wpdb->prepare(
            "INSERT INTO {$this->tables['crm']} 
            (" . implode(',', array_keys($values)) . ") 
            VALUES " . implode(',', $placeholders) . "
            ON DUPLICATE KEY UPDATE " . implode(',', $updates),
            $values
        );

        try {
            $retries = 0;
            $success = false;

            while (!$success && $retries < $this->deadlockRetries) {
                $result = $this->wpdb->query($query);

                if ($result === false) {
                    if ($this->isDeadlockError()) {
                        $retries++;
                        sleep(self::DEADLOCK_RETRY_DELAY);
                        continue;
                    }
                    throw new Database_Exception("Update failed: " . $this->wpdb->last_error);
                }

                $success = true;
            }

            if (!$success) {
                throw new Database_Exception("Max deadlock retries reached");
            }

            return $this->wpdb->rows_affected;
        } catch (Exception $e) {
            throw new Database_Exception(
                "Failed to execute update: " . $e->getMessage(),
                $query
            );
        }
    }

    public function emailExists(string $email): bool
    {
        $cacheKey = 'email_exists_' . md5($email);

        if ($this->cacheEnabled) {
            $cached = wp_cache_get($cacheKey, self::CACHE_GROUP);
            if ($cached !== false) {
                return (bool) $cached;
            }
        }

        try {
            $query = $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tables['crm']} WHERE subscriber = %s",
                $email
            );

            $exists = (int) $this->wpdb->get_var($query) > 0;

            if ($this->cacheEnabled) {
                wp_cache_set($cacheKey, $exists, self::CACHE_GROUP, self::CACHE_TTL);
            }

            return $exists;
        } catch (Exception $e) {
            $this->logger->log(
                "Error checking email existence",
                'error',
                ['email' => $email, 'error' => $e->getMessage()]
            );
            return false;
        }
    }

    public function getSubscriberByEmail(string $email): ?array
    {
        $cacheKey = 'subscriber_email_' . md5($email);

        if ($this->cacheEnabled) {
            $cached = wp_cache_get($cacheKey, self::CACHE_GROUP);
            if ($cached !== false) {
                return $cached;
            }
        }

        try {
            $query = $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['crm']} 
                WHERE subscriber = %s 
                ORDER BY date_added DESC 
                LIMIT 1",
                $email
            );

            $result = $this->wpdb->get_row($query, ARRAY_A);

            if ($result && $this->cacheEnabled) {
                wp_cache_set($cacheKey, $result, self::CACHE_GROUP, self::CACHE_TTL);
            }

            return $result ?: null;
        } catch (Exception $e) {
            $this->logger->log('Failed to fetch subscriber by email', 'error', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            throw new Database_Exception(
                "Database error fetching subscriber: {$e->getMessage()}",
                $query
            );
        }
    }

    public function getSubscriberById(int $subscriberId): ?array
    {
        $cacheKey = 'subscriber_' . $subscriberId;

        if ($this->cacheEnabled) {
            $cached = wp_cache_get($cacheKey, self::CACHE_GROUP);
            if ($cached !== false) {
                return $cached;
            }
        }

        try {
            $query = $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['crm']} WHERE bema_id = %d",
                $subscriberId
            );

            $result = $this->wpdb->get_row($query, ARRAY_A);

            if ($result && $this->cacheEnabled) {
                wp_cache_set($cacheKey, $result, self::CACHE_GROUP, self::CACHE_TTL);
            }

            $this->logger->log('Fetched subscriber by ID', 'debug', [
                'id' => $subscriberId,
                'found' => !empty($result)
            ]);

            return $result ?: null;
        } catch (Exception $e) {
            $this->logger->log('Failed to fetch subscriber by ID', 'error', [
                'id' => $subscriberId,
                'error' => $e->getMessage()
            ]);
            throw new Database_Exception(
                "Failed to fetch subscriber by ID: {$subscriberId}",
                $query
            );
        }
    }

    public function addSubscriber(array $subscriberData): int
    {
        try {
            $sanitizedData = $this->sanitizeData($subscriberData);

            $result = $this->wpdb->insert(
                $this->tables['crm'],
                $sanitizedData,
                $this->getDataFormats($sanitizedData)
            );

            if ($result === false) {
                throw new Database_Exception(
                    'Failed to add subscriber: ' . $this->wpdb->last_error,
                    $this->wpdb->last_query
                );
            }

            $insertId = $this->wpdb->insert_id;

            $this->logger->log('Added new subscriber', 'info', [
                'id' => $insertId,
                'email' => $subscriberData['subscriber'] ?? 'unknown'
            ]);

            $this->updateSubscriberCache($insertId, $sanitizedData);

            return $insertId;
        } catch (Exception $e) {
            $this->logger->log('Failed to add subscriber', 'error', [
                'data' => $subscriberData,
                'error' => $e->getMessage()
            ]);
            throw new Database_Exception(
                "Database error adding subscriber: {$e->getMessage()}",
                $this->wpdb->last_query
            );
        }
    }

    public function updateSubscriber(int $subscriberId, array $data): bool
    {
        try {
            $sanitizedData = $this->sanitizeData($data);

            $result = $this->wpdb->update(
                $this->tables['crm'],
                $sanitizedData,
                ['bema_id' => $subscriberId],
                $this->getDataFormats($sanitizedData),
                ['%d']
            );

            if ($result === false) {
                throw new Database_Exception(
                    "Failed to update subscriber: {$subscriberId}",
                    $this->wpdb->last_query
                );
            }

            $this->logger->log('Updated subscriber', 'info', [
                'id' => $subscriberId,
                'fields_updated' => array_keys($sanitizedData)
            ]);

            $this->invalidateSubscriberCache($subscriberId);

            return true;
        } catch (Exception $e) {
            $this->logger->log('Failed to update subscriber', 'error', [
                'id' => $subscriberId,
                'error' => $e->getMessage()
            ]);
            throw new Database_Exception(
                "Database error updating subscriber: {$e->getMessage()}",
                $this->wpdb->last_query
            );
        }
    }

    private function updateSubscriberCache(int $subscriberId, array $data): void
    {
        if (!$this->cacheEnabled)
            return;

        $cacheKey = 'subscriber_' . $subscriberId;
        wp_cache_set($cacheKey, $data, self::CACHE_GROUP, self::CACHE_TTL);

        if (isset($data['subscriber'])) {
            $emailCacheKey = 'subscriber_email_' . md5($data['subscriber']);
            wp_cache_set($emailCacheKey, $data, self::CACHE_GROUP, self::CACHE_TTL);
        }

        $this->logger->log('Cache updated for subscriber', 'debug', [
            'id' => $subscriberId
        ]);
    }

    private function invalidateSubscriberCache(int $subscriberId): void
    {
        if (!$this->cacheEnabled)
            return;

        $subscriber = $this->getSubscriberById($subscriberId);
        if ($subscriber && isset($subscriber['subscriber'])) {
            wp_cache_delete('subscriber_email_' . md5($subscriber['subscriber']), self::CACHE_GROUP);
        }
        wp_cache_delete('subscriber_' . $subscriberId, self::CACHE_GROUP);

        $this->logger->log('Cache invalidated for subscriber', 'debug', [
            'id' => $subscriberId
        ]);
    }

    private function buildPlaceholder(array $data): string
    {
        return '(' . implode(',', array_fill(0, count($data), '%s')) . ')';
    }

    private function sanitizeData(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = sanitize_text_field($value);
            } elseif (is_numeric($value)) {
                $sanitized[$key] = floatval($value);
            } elseif (is_bool($value)) {
                $sanitized[$key] = (bool) $value;
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    private function getDataFormats(array $data): array
    {
        $formats = [];
        foreach ($data as $value) {
            if (is_int($value)) {
                $formats[] = '%d';
            } elseif (is_float($value)) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }
        return $formats;
    }

    private function isDeadlockError(): bool
    {
        return strpos($this->wpdb->last_error, 'deadlock') !== false;
    }

    private function manageMemory(): void
    {
        if (memory_get_usage(true) > $this->getMemoryThreshold()) {
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            $this->clearCache();
        }
    }

    private function getMemoryThreshold(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1')
            return PHP_INT_MAX;

        preg_match('/^(\d+)(.)$/', $limit, $matches);
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

        return (int) ($value * 0.8); // 80% of memory limit
    }

    public function errorHandler($errno, $errstr, $errfile, $errline): void
    {
        if (!(error_reporting() & $errno)) {
            return;
        }

        $this->logger?->log("Database Error: {$errstr}", 'error', [
            'errno' => $errno,
            'file' => $errfile,
            'line' => $errline
        ]);
    }

    public function enableCache(): void
    {
        $this->cacheEnabled = true;
        wp_cache_set('enabled', true, self::CACHE_GROUP);
    }

    public function disableCache(): void
    {
        $this->cacheEnabled = false;
        wp_cache_set('enabled', false, self::CACHE_GROUP);
    }

    public function clearCache(): void
    {
        wp_cache_flush();
    }

    public function setBatchSize(int $size): void
    {
        $this->batchSize = max(1, min($size, 10000));
    }

    public function optimizeTable(string $table): void
    {
        if (!isset($this->tables[$table])) {
            throw new Database_Exception("Invalid table: {$table}");
        }

        try {
            $this->wpdb->query("OPTIMIZE TABLE {$this->tables[$table]}");
            $this->logger->log('Table optimized', 'info', ['table' => $table]);
        } catch (Exception $e) {
            $this->logger->log('Failed to optimize table', 'error', [
                'table' => $table,
                'error' => $e->getMessage()
            ]);
        }
    }
}
