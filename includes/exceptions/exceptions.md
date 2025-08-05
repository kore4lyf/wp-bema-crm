# BEMA CRM Exception System Documentation

## Overview
This documentation covers the exception hierarchy and handling system for the BEMA CRM synchronization system.

## Exception Types

### 1. Base Exception
The foundation of the exception hierarchy.
```php
class Base_Exception extends Exception {}
```

### 2. Database Exception
Handles database-specific errors with detailed context and retry logic.

```php
// Error type constants
const ERROR_TYPE_DEADLOCK = 'deadlock';
const ERROR_TYPE_TIMEOUT = 'timeout';
const ERROR_TYPE_CONNECTION = 'connection';
const ERROR_TYPE_CONSTRAINT = 'constraint';
const ERROR_TYPE_SYNTAX = 'syntax';

// Usage example
try {
    // Database operation
} catch (Database_Exception $e) {
    if ($e->isDeadlockError()) {
        $retryDelay = $e->getSuggestedRetryDelay();
        // Handle deadlock
    }
    
    if ($e->isTimeoutError()) {
        // Handle timeout
    }
    
    // Log detailed database error
    $logger->log($e->getMessage(), 'error', $e->getErrorReport());
}
```

Key Features:
- Retryable error detection
- Deadlock handling
- Timeout management
- Query sanitization
- Detailed error reporting

Error Codes:
```php
const RETRYABLE_ERROR_CODES = [
    1205, // Lock wait timeout
    1213, // Deadlock
    2006, // Server gone away
    2013, // Lost connection
    2014, // Commands out of sync
    2034  // Communication timeout
];
```

Error Reporting Format:
```php
[
    'query' => 'SELECT * FROM table...',
    'table' => 'affected_table',
    'error_code' => '1213',
    'error_type' => 'deadlock',
    'query_duration' => 0.5,
    'affected_rows' => 0,
    'is_deadlock' => true,
    'is_timeout' => false
]
```

### 3. Sync Exception
Handles basic error scenarios with retry capabilities.

```php
try {
    // Sync operation
} catch (Sync_Exception $e) {
    if ($e->isRetryable()) {
        // Implement retry logic
        $delay = $e->getNextRetryDelay();
        $e->incrementRetryCount();
    }
    
    // Log error details
    $logger->log($e->getMessage(), 'error', $e->getErrorDetails());
}
```

### 4. API Exception
Handles API-specific errors including rate limits.

```php
catch (API_Exception $e) {
    if ($e->isRateLimitExceeded()) {
        $waitTime = $e->getTimeUntilReset();
        // Handle rate limit
    }
    
    $retryDelay = $e->getSuggestedRetryDelay();
    // Implement retry with suggested delay
}
```

### 5. Validation Exception
Handles data validation errors.

```php
catch (Validation_Exception $e) {
    $errors = $e->getValidationErrors();
    $summary = $e->getErrorSummary();
    
    // Log validation failures
    $logger->log('Validation failed', 'error', $summary);
}
```

## Integration Examples

### With API Clients
```php
class API_Client {
    private $logger;
    
    protected function makeRequest($endpoint, $method) {
        try {
            // API request
        } catch (Exception $e) {
            throw new API_Exception(
                $e->getMessage(),
                $endpoint,
                $method,
                $responseCode,
                true,
                $this->getRequestContext()
            );
        }
    }
}
```

## Best Practices

1. Always include detailed context when throwing exceptions
2. Implement proper retry mechanisms for retryable exceptions
3. Use appropriate exception types for different error scenarios
4. Handle nested exceptions properly
5. Include proper error codes and messages
6. Implement proper cleanup in catch blocks
