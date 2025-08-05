<?php
namespace Bema\Exceptions;

use \Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exception class for API-related errors with enhanced error handling
 */
class API_Exception extends Sync_Exception
{
    /** @var string */
    protected $endpoint;

    /** @var string */
    protected $requestMethod;

    /** @var int */
    protected $responseCode;

    /** @var string */
    protected $responseBody;

    /** @var array */
    protected $requestHeaders;

    /** @var array */
    protected $responseHeaders;

    /** @var float */
    protected $requestDuration;

    /** @var int */
    protected $rateLimitRemaining;

    /** @var int */
    protected $rateLimitReset;

    // HTTP status code ranges
    const HTTP_4XX_CLIENT_ERROR = '4XX';
    const HTTP_5XX_SERVER_ERROR = '5XX';

    // Retry configuration for specific status codes
    const RETRYABLE_STATUS_CODES = [
        408, // Request Timeout
        429, // Too Many Requests
        500, // Internal Server Error
        502, // Bad Gateway
        503, // Service Unavailable
        504  // Gateway Timeout
    ];

    /**
     * Enhanced constructor for API exceptions
     * 
     * @param string $message Exception message
     * @param string $endpoint API endpoint
     * @param string $requestMethod HTTP method used
     * @param int $responseCode HTTP response code
     * @param bool $retryable Whether the request can be retried
     * @param array $context Additional context
     * @param int $code Exception code
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        string $endpoint,
        string $requestMethod,
        int $responseCode,
        bool $retryable = true,
        array $context = [],
        int $code = 0,
        Throwable $previous = null
    ) {
        $this->endpoint = $endpoint;
        $this->requestMethod = strtoupper($requestMethod);
        $this->responseCode = $responseCode;
        $this->requestDuration = $context['request_duration'] ?? 0;
        $this->responseBody = $context['response_body'] ?? '';
        $this->requestHeaders = $this->sanitizeHeaders($context['request_headers'] ?? []);
        $this->responseHeaders = $this->sanitizeHeaders($context['response_headers'] ?? []);

        // Extract rate limit information
        $this->rateLimitRemaining = $context['rate_limit_remaining'] ?? -1;
        $this->rateLimitReset = $context['rate_limit_reset'] ?? 0;

        // Determine if the error is retryable based on status code
        $retryable = $retryable && (
            in_array($responseCode, self::RETRYABLE_STATUS_CODES) ||
            $this->isServerError($responseCode)
        );

        // Enhance context with API-specific information
        $context = array_merge($context, [
            'endpoint' => $endpoint,
            'method' => $requestMethod,
            'response_code' => $responseCode,
            'response_body' => $this->truncateResponseBody($this->responseBody),
            'request_duration' => $this->requestDuration,
            'rate_limit_remaining' => $this->rateLimitRemaining,
            'rate_limit_reset' => $this->rateLimitReset,
            'error_type' => $this->determineErrorType($responseCode)
        ]);

        parent::__construct($message, $retryable, $context, $code, $previous);
    }

    /**
     * Get API endpoint
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Get request method
     */
    public function getRequestMethod(): string
    {
        return $this->requestMethod;
    }

    /**
     * Get response code
     */
    public function getResponseCode(): int
    {
        return $this->responseCode;
    }

    /**
     * Get response body
     */
    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    /**
     * Get request headers
     */
    public function getRequestHeaders(): array
    {
        return $this->requestHeaders;
    }

    /**
     * Get response headers
     */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    /**
     * Get request duration
     */
    public function getRequestDuration(): float
    {
        return $this->requestDuration;
    }

    /**
     * Get rate limit remaining
     */
    public function getRateLimitRemaining(): int
    {
        return $this->rateLimitRemaining;
    }

    /**
     * Get rate limit reset timestamp
     */
    public function getRateLimitReset(): int
    {
        return $this->rateLimitReset;
    }

    /**
     * Check if rate limit is exceeded
     */
    public function isRateLimitExceeded(): bool
    {
        return $this->responseCode === 429 || $this->rateLimitRemaining === 0;
    }

    /**
     * Get time until rate limit reset
     */
    public function getTimeUntilReset(): int
    {
        return max(0, $this->rateLimitReset - time());
    }

    /**
     * Check if error is server-side
     */
    private function isServerError(int $statusCode): bool
    {
        return $statusCode >= 500 && $statusCode < 600;
    }

    /**
     * Determine the type of error based on status code
     */
    private function determineErrorType(int $statusCode): string
    {
        if ($statusCode >= 500) {
            return self::HTTP_5XX_SERVER_ERROR;
        }
        return self::HTTP_4XX_CLIENT_ERROR;
    }

    /**
     * Sanitize headers by removing sensitive information
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'api-key', 'cookie'];
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveHeaders)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Truncate response body if too long
     */
    private function truncateResponseBody(string $body, int $maxLength = 1000): string
    {
        if (strlen($body) <= $maxLength) {
            return $body;
        }
        return substr($body, 0, $maxLength) . '... [truncated]';
    }

    /**
     * Get suggested retry delay based on rate limit and response headers
     */
    public function getSuggestedRetryDelay(): int
    {
        if ($this->isRateLimitExceeded()) {
            return $this->getTimeUntilReset() + 1;
        }

        return parent::getNextRetryDelay();
    }

    /**
     * Get detailed error report
     */
    public function getErrorReport(): array
    {
        return array_merge(parent::getErrorDetails(), [
            'endpoint' => $this->endpoint,
            'method' => $this->requestMethod,
            'response_code' => $this->responseCode,
            'request_duration' => $this->requestDuration,
            'rate_limit_remaining' => $this->rateLimitRemaining,
            'rate_limit_reset' => $this->rateLimitReset,
            'error_type' => $this->determineErrorType($this->responseCode)
        ]);
    }
}
