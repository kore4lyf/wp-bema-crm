<?php
namespace Bema\Exceptions;

use \Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exception class for database-related errors with enhanced error handling
 */
class Database_Exception extends Sync_Exception
{
    /** @var string */
    protected $query;

    /** @var array */
    protected $queryParams;

    /** @var string */
    protected $tableInvolved;

    /** @var string */
    protected $errorCode;

    /** @var float */
    protected $queryDuration;

    /** @var bool */
    protected $isDeadlock;

    /** @var bool */
    protected $isTimeout;

    /** @var int */
    protected $affectedRows;

    // Database error codes that are typically retryable
    const RETRYABLE_ERROR_CODES = [
        1205, // Lock wait timeout exceeded
        1213, // Deadlock found when trying to get lock
        2006, // MySQL server has gone away
        2013, // Lost connection to MySQL server during query
        2014, // Commands out of sync
        2034  // Got timeout reading communication packets
    ];

    // Error type constants
    const ERROR_TYPE_DEADLOCK = 'deadlock';
    const ERROR_TYPE_TIMEOUT = 'timeout';
    const ERROR_TYPE_CONNECTION = 'connection';
    const ERROR_TYPE_CONSTRAINT = 'constraint';
    const ERROR_TYPE_SYNTAX = 'syntax';
    const ERROR_TYPE_UNKNOWN = 'unknown';

    /**
     * Enhanced constructor for database exceptions
     * 
     * @param string $message Exception message
     * @param string $query SQL query that caused the error
     * @param array $queryParams Query parameters
     * @param string $tableInvolved Table involved in the query
     * @param string $errorCode MySQL error code
     * @param bool $retryable Whether the operation can be retried
     * @param array $context Additional context
     * @param int $code Exception code
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        string $query = '',
        array $queryParams = [],
        string $tableInvolved = '',
        string $errorCode = '',
        bool $retryable = false,
        array $context = [],
        int $code = 0,
        Throwable $previous = null
    ) {
        $this->query = $query;
        $this->queryParams = $this->sanitizeQueryParams($queryParams);
        $this->tableInvolved = $tableInvolved;
        $this->errorCode = $errorCode;
        $this->queryDuration = $context['query_duration'] ?? 0;
        $this->affectedRows = $context['affected_rows'] ?? 0;

        // Determine if this is a deadlock or timeout
        $this->isDeadlock = $this->checkDeadlockError($errorCode);
        $this->isTimeout = $this->checkTimeoutError($errorCode);

        // Auto-determine if error should be retryable
        if ($retryable === false) {
            $retryable = $this->isRetryableError($errorCode);
        }

        // Enhance context with database-specific information
        $context = array_merge($context, [
            'query' => $this->sanitizeQuery($query),
            'query_params' => $this->queryParams,
            'table' => $tableInvolved,
            'error_code' => $errorCode,
            'error_type' => $this->determineErrorType($errorCode),
            'query_duration' => $this->queryDuration,
            'affected_rows' => $this->affectedRows,
            'is_deadlock' => $this->isDeadlock,
            'is_timeout' => $this->isTimeout
        ]);

        parent::__construct($message, $retryable, $context, $code, $previous);
    }

    /**
     * Get the SQL query
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Get query parameters
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * Get involved table name
     */
    public function getTableInvolved(): string
    {
        return $this->tableInvolved;
    }

    /**
     * Get MySQL error code
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Get query duration
     */
    public function getQueryDuration(): float
    {
        return $this->queryDuration;
    }

    /**
     * Check if error is a deadlock
     */
    public function isDeadlockError(): bool
    {
        return $this->isDeadlock;
    }

    /**
     * Check if error is a timeout
     */
    public function isTimeoutError(): bool
    {
        return $this->isTimeout;
    }

    /**
     * Get number of affected rows
     */
    public function getAffectedRows(): int
    {
        return $this->affectedRows;
    }

    /**
     * Determine if the error code is retryable
     */
    public function isRetryableError(string $errorCode): bool
    {
        return in_array((int)$errorCode, self::RETRYABLE_ERROR_CODES);
    }

    /**
     * Check if error code indicates deadlock
     */
    private function checkDeadlockError(string $errorCode): bool
    {
        return (int)$errorCode === 1213;
    }

    /**
     * Check if error code indicates timeout
     */
    private function checkTimeoutError(string $errorCode): bool
    {
        return in_array((int)$errorCode, [1205, 2034]);
    }

    /**
     * Determine the type of database error
     */
    private function determineErrorType(string $errorCode): string
    {
        $errorCode = (int)$errorCode;

        if ($this->checkDeadlockError($errorCode)) {
            return self::ERROR_TYPE_DEADLOCK;
        }

        if ($this->checkTimeoutError($errorCode)) {
            return self::ERROR_TYPE_TIMEOUT;
        }

        switch ($errorCode) {
            case 1062: // Duplicate entry
            case 1451: // Cannot delete or update a parent row (foreign key constraint)
            case 1452: // Cannot add or update a child row (foreign key constraint)
                return self::ERROR_TYPE_CONSTRAINT;

            case 1064: // SQL syntax error
                return self::ERROR_TYPE_SYNTAX;

            case 2002: // Connection refused
            case 2006: // MySQL server has gone away
            case 2013: // Lost connection
                return self::ERROR_TYPE_CONNECTION;

            default:
                return self::ERROR_TYPE_UNKNOWN;
        }
    }

    /**
     * Sanitize SQL query by removing sensitive information
     */
    private function sanitizeQuery(string $query): string
    {
        // Truncate long queries
        if (strlen($query) > 1000) {
            return substr($query, 0, 1000) . '... [truncated]';
        }
        return $query;
    }

    /**
     * Sanitize query parameters
     */
    private function sanitizeQueryParams(array $params): array
    {
        $sanitized = [];
        foreach ($params as $key => $value) {
            if (preg_match('/(password|secret|key|token)/i', $key)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    /**
     * Get suggested retry delay based on error type
     */
    public function getSuggestedRetryDelay(): int
    {
        if ($this->isDeadlock) {
            return rand(1, 3); // Random delay between 1-3 seconds for deadlocks
        }

        if ($this->isTimeout) {
            return $this->getRetryCount() * 5; // Increasing delay for timeouts
        }

        return parent::getNextRetryDelay();
    }

    /**
     * Get detailed error report
     */
    public function getErrorReport(): array
    {
        return array_merge(parent::getErrorDetails(), [
            'query' => $this->sanitizeQuery($this->query),
            'table' => $this->tableInvolved,
            'error_code' => $this->errorCode,
            'error_type' => $this->determineErrorType($this->errorCode),
            'query_duration' => $this->queryDuration,
            'affected_rows' => $this->affectedRows,
            'is_deadlock' => $this->isDeadlock,
            'is_timeout' => $this->isTimeout
        ]);
    }
}
