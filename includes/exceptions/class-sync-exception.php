<?php
namespace Bema\Exceptions;

use \Throwable;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base exception class for sync operations with enhanced error handling
 */
class Sync_Exception extends Base_Exception
{
    /** @var bool */
    protected $retryable;

    /** @var array */
    protected $context = [];

    /** @var int */
    protected $retryCount = 0;

    /** @var int */
    protected $maxRetries = 3;

    /** @var float */
    protected $lastRetryTimestamp;

    /** @var array */
    protected $retryDelays = [1, 5, 15]; // Delays in seconds for each retry attempt

    /**
     * Constructor with enhanced context handling
     * 
     * @param string $message Exception message
     * @param bool $retryable Whether this operation can be retried
     * @param array $context Additional context for the error
     * @param int $code Exception code
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        bool $retryable = false,
        array $context = [],
        int $code = 0,
        Throwable $previous = null
    ) {
        $this->retryable = $retryable;
        $this->context = $this->sanitizeContext($context);
        $this->lastRetryTimestamp = microtime(true);

        // Add stack trace to context
        $this->context['stack_trace'] = $this->getTraceAsString();
        $this->context['timestamp'] = date('Y-m-d H:i:s');

        parent::__construct($message, $code, $previous);
    }

    /**
     * Check if the operation can be retried
     */
    public function isRetryable(): bool
    {
        return $this->retryable && $this->retryCount < $this->maxRetries;
    }

    /**
     * Get number of retries performed
     */
    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    /**
     * Increment retry count and update timestamp
     */
    public function incrementRetryCount(): void
    {
        $this->retryCount++;
        $this->lastRetryTimestamp = microtime(true);
    }

    /**
     * Get the delay for the next retry attempt
     */
    public function getNextRetryDelay(): int
    {
        return $this->retryDelays[min($this->retryCount, count($this->retryDelays) - 1)];
    }

    /**
     * Get time since last retry
     */
    public function getTimeSinceLastRetry(): float
    {
        return microtime(true) - $this->lastRetryTimestamp;
    }

    /**
     * Get error context
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Add additional context
     */
    public function addContext(array $additionalContext): void
    {
        $this->context = array_merge(
            $this->context,
            $this->sanitizeContext($additionalContext)
        );
    }

    /**
     * Get formatted error details
     */
    public function getErrorDetails(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'retryable' => $this->retryable,
            'retry_count' => $this->retryCount,
            'context' => $this->context,
            'stack_trace' => $this->getTraceAsString()
        ];
    }

    /**
     * Sanitize context data
     */
    protected function sanitizeContext(array $context): array
    {
        $sanitized = [];
        foreach ($context as $key => $value) {
            // Remove sensitive information
            if (in_array(strtolower($key), ['password', 'api_key', 'token', 'secret'])) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            // Handle nested arrays
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value);
                continue;
            }

            // Truncate long values
            if (is_string($value) && strlen($value) > 1000) {
                $sanitized[$key] = substr($value, 0, 1000) . '... [truncated]';
                continue;
            }

            $sanitized[$key] = $value;
        }
        return $sanitized;
    }

    /**
     * Set maximum number of retries
     */
    public function setMaxRetries(int $maxRetries): void
    {
        $this->maxRetries = max(0, $maxRetries);
    }

    /**
     * Set retry delays
     */
    public function setRetryDelays(array $delays): void
    {
        $this->retryDelays = array_map('abs', $delays);
    }
}
