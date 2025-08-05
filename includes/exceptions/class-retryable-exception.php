<?php
namespace Bema\Exceptions;

use Exception;

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
