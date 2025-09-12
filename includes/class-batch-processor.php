<?php

namespace Bema;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class SyncBatchProcessor
{
    private $batch_size = 1000;
    private $logger;
    private $retry_attempts = 3;
    private $retry_delay = 300;
    private $processor;
    private $maxMemoryUsage = '256M';
    private $memoryThreshold = 0.8;
    private $progressCallback;
    private $failureCallback;
    private $lastProcessedId = 0;
    private $processedCount = 0;
    private $failedItems = [];

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const BATCH_LOG_KEY = 'bema_batch_processing_log';
    const FAILURE_THRESHOLD = 0.2; // 20% failure rate threshold
    const MIN_MEMORY_REQUIRED = 64 * 1024 * 1024; // 64MB

    public function __construct(
        callable $processor,
        ?callable $progressCallback = null,
        ?callable $failureCallback = null,
        ?Bema_CRM_Logger $logger = null
    ) {
        $this->logger = $logger ?? Bema_CRM_Logger::create('batch-processor');
        $this->processor = $processor;
        $this->progressCallback = $progressCallback;
        $this->failureCallback = $failureCallback;
        $this->initializeMemoryLimit();
        $this->loadProcessingState();
    }

    private function initializeMemoryLimit(): void
    {
        $currentLimit = $this->getMemoryLimitInBytes();
        if ($currentLimit < self::MIN_MEMORY_REQUIRED) {
            throw new Exception('Insufficient memory available for batch processing');
        }
        $this->maxMemoryUsage = $currentLimit;
        ini_set('memory_limit', $this->formatBytes($this->maxMemoryUsage));
    }

    public function processBatch(array $items): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'retried' => 0,
            'skipped' => 0,
            'total_processed' => 0,
            'memory_peak' => 0
        ];

        try {
            $this->validateBatchData($items);
            $batches = $this->prepareBatches($items);

            foreach ($batches as $index => $batch) {
                // Check for stop flag
                if (get_option('bema_sync_stop_flag')) {
                    throw new Exception('Sync stopped by user');
                }

                $this->processBatchWithErrorHandling($batch, $results, $index, count($batches));
                $this->checkMemoryAndFailureThresholds($results);
                $this->saveProcessingState($results);
            }
        } catch (Exception $e) {
            $this->handleBatchError($e, $results);
        } finally {
            $this->finalizeProcessing($results);
        }

        return $results;
    }

    private function processBatchWithErrorHandling(array $batch, array &$results, int $batchIndex, int $totalBatches): void
    {
        try {
            $batchStartTime = microtime(true);
            $this->processBatchWithRetry($batch, $results);
            $this->updateProgress($batchIndex + 1, $totalBatches);

            $batchDuration = microtime(true) - $batchStartTime;
            $this->logger->info('Batch processed successfully', [
                'batch_size' => count($batch),
                'duration' => round($batchDuration, 2),
                'memory_used' => $this->formatBytes(memory_get_usage(true))
            ]);
        } catch (Exception $e) {
            throw new Exception("Batch processing failed: " . $e->getMessage());
        }
    }

    private function processBatchWithRetry(array $batch, array &$results): void
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->retry_attempts) {
            try {
                $this->processItems($batch, $results);
                return;
            } catch (Exception $e) {
                $lastError = $e;
                $attempt++;
                $results['retried'] += count($batch);

                if ($attempt < $this->retry_attempts) {
                    $retryDelay = $this->calculateRetryDelay($attempt);
                    $this->logger->warning('Retrying batch', [
                        'attempt' => $attempt,
                        'delay' => $retryDelay,
                        'error' => $e->getMessage()
                    ]);
                    sleep($retryDelay);
                }
            }
        }

        $this->handleBatchFailure($batch, $lastError, $results);
    }

    private function processItems(array $items, array &$results): void
    {
        foreach ($items as $item) {
            try {
                if ($this->shouldProcessItem($item)) {
                    $this->processItemSafely($item);
                    $results['success']++;
                } else {
                    $results['skipped']++;
                }
            } catch (Exception $e) {
                $this->failedItems[] = [
                    'item' => $item,
                    'error' => $e->getMessage(),
                    'timestamp' => time()
                ];
                $results['failed']++;
                throw $e;
            }
            $results['total_processed']++;
            $this->processedCount++;
        }
    }

    private function shouldProcessItem($item): bool
    {
        return !isset($item['id']) || $item['id'] > $this->lastProcessedId;
    }

    private function processItemSafely($item): void
    {
        if ($this->isMemoryExhausted()) {
            $this->manageMemory();
        }

        call_user_func($this->processor, $item);

        if (isset($item['id'])) {
            $this->lastProcessedId = max($this->lastProcessedId, $item['id']);
        }
    }

    private function isMemoryExhausted(): bool
    {
        return memory_get_usage(true) > ($this->maxMemoryUsage * $this->memoryThreshold);
    }

    private function manageMemory(): void
    {
        if (function_exists('gc_collect_cycles')) {
            $collected = gc_collect_cycles();
            $this->logger->debug('Memory cleanup performed', [
                'collected' => $collected,
                'memory_before' => $this->formatBytes(memory_get_usage(true))
            ]);
        }
    }

    private function checkMemoryAndFailureThresholds(array $results): void
    {
        $memoryUsage = memory_get_usage(true);
        $failureRate = $results['total_processed'] > 0
            ? $results['failed'] / $results['total_processed']
            : 0;

        if ($memoryUsage > ($this->maxMemoryUsage * $this->memoryThreshold)) {
            throw new Exception('Memory threshold exceeded');
        }

        if ($failureRate > self::FAILURE_THRESHOLD) {
            throw new Exception('Failure rate threshold exceeded');
        }
    }

    private function calculateRetryDelay(int $attempt): int
    {
        return min($this->retry_delay * pow(2, $attempt - 1), 3600);
    }

    private function updateProgress(int $current, int $total): void
    {
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, $current, $total, [
                'processed' => $this->processedCount,
                'memory_usage' => memory_get_usage(true),
                'last_id' => $this->lastProcessedId
            ]);
        }
    }

    private function handleBatchFailure(array $batch, Exception $error, array &$results): void
    {
        $results['failed'] += count($batch);

        if ($this->failureCallback) {
            call_user_func($this->failureCallback, $batch, $error);
        }

        throw new Exception("Batch failed after {$this->retry_attempts} attempts: " . $error->getMessage());
    }

    private function handleBatchError(Exception $e, array &$results): void
    {
        $this->logger->error('Batch processing error', [
            'error' => $e->getMessage(),
            'results' => $results
        ]);

        if ($this->failureCallback) {
            call_user_func($this->failureCallback, null, $e);
        }
    }

    private function saveProcessingState(array $results): void
    {
        update_option('bema_batch_processor_state', [
            'last_processed_id' => $this->lastProcessedId,
            'processed_count' => $this->processedCount,
            'failed_items' => array_slice($this->failedItems, -100),
            'last_results' => $results,
            'timestamp' => time()
        ]);
    }

    private function loadProcessingState(): void
    {
        $state = get_option('bema_batch_processor_state', []);
        $this->lastProcessedId = $state['last_processed_id'] ?? 0;
        $this->processedCount = $state['processed_count'] ?? 0;
        $this->failedItems = $state['failed_items'] ?? [];
    }

    private function finalizeProcessing(array $results): void
    {
        $results['memory_peak'] = memory_get_peak_usage(true);

        $this->logger->info('Batch processing completed', [
            'results' => $results,
            'memory_peak' => $this->formatBytes($results['memory_peak'])
        ]);

        $this->saveProcessingState($results);
    }

    private function validateBatchData(array $items): void
    {
        if (empty($items)) {
            throw new Exception('Empty batch provided');
        }
    }

    private function prepareBatches(array $items): array
    {
        return array_chunk($items, $this->batch_size);
    }

    private function getMemoryLimitInBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') return PHP_INT_MAX;

        preg_match('/^(\d+)(.)$/', $memoryLimit, $matches);
        if (!$matches) return 256 * 1024 * 1024;

        $value = (int)$matches[1];
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

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    public function setBatchSize(int $size): void
    {
        $this->batch_size = max(1, min($size, 10000));
    }

    public function setRetryAttempts(int $attempts): void
    {
        $this->retry_attempts = max(1, $attempts);
    }

    public function setRetryDelay(int $delay): void
    {
        $this->retry_delay = max(1, $delay);
    }

    public function setMemoryThreshold(float $threshold): void
    {
        $this->memoryThreshold = max(0.1, min(0.9, $threshold));
    }

    public function getFailedItems(): array
    {
        return $this->failedItems;
    }
}
