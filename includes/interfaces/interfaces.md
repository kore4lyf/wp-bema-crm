# Interface System Documentation

## Overview
The BEMA CRM system uses a set of interfaces to define core contracts for data providers, validation, monitoring, and synchronization management.

## Core Interfaces

### 1. Provider Interface
Defines contract for data providers (EDD, MailerLite).

```php
interface Provider_Interface {
    public function validateConnection(): bool;
    public function getSubscribers($status = 'active'): array;
    public function updateSubscriber($id, $data): bool;
    public function addSubscriberToGroup($subscriberId, $groupId): bool;
    public function removeSubscriberFromGroup($subscriberId, $groupId): bool;
    public function getGroups(): array;
    public function addOrUpdateSubscriber(array $data): string;
}
```

#### Key Responsibilities:
- Connection validation
- Subscriber management
- Group management
- Data synchronization

#### Implementation Requirements:
```php
class ExampleProvider implements Provider_Interface {
    public function validateConnection(): bool {
        // Must implement connection testing
        // Return true only if connection is valid
    }
    
    public function getSubscribers($status = 'active'): array {
        // Must handle pagination
        // Must implement error handling
        // Must return consistent data structure
        return [
            'id' => string,
            'email' => string,
            'status' => string,
            'groups' => array
        ];
    }
}
```

### 2. Validator Interface
Defines contract for data validation.

```php
interface Validator_Interface {
    public function validate($data): bool;
    public function getErrors(): array;
    public function clearErrors(): void;
    public function addError(string $error): void;
}
```

#### Key Responsibilities:
- Data validation
- Error collection
- Error reporting
- Validation state management

#### Implementation Requirements:
```php
class ExampleValidator implements Validator_Interface {
    protected $errors = [];
    
    public function validate($data): bool {
        // Must clear previous errors
        $this->clearErrors();
        
        // Must implement comprehensive validation
        // Must collect all relevant errors
        // Must return false if any validation fails
        
        return empty($this->errors);
    }
}
```

### 3. Health Monitor Interface
Defines contract for system health monitoring.

```php
interface Health_Monitor {
    public function startMonitoring(string $jobId): void;
    public function stopMonitoring(string $jobId): void;
    public function performCheck(): void;
    public function getStatus(): array;
    public function checkCampaign(string $campaign): void;
}
```

#### Key Responsibilities:
- Job monitoring
- Health checks
- Status reporting
- Campaign monitoring

#### Implementation Requirements:
```php
class ExampleHealthMonitor implements Health_Monitor {
    public function performCheck(): void {
        // Must check system resources
        // Must verify critical services
        // Must log health status
        // Must trigger alerts if needed
    }
    
    public function getStatus(): array {
        return [
            'memory_usage' => float,
            'load_average' => float,
            'job_status' => string,
            'active_processes' => int
        ];
    }
}
```

### 4. Lock Handler Interface
Defines contract for synchronization locks.

```php
interface Lock_Handler {
    public function acquireLock(string $key): bool;
    public function releaseLock(string $key): bool;
    public function getActiveLocks(): array;
}
```

#### Key Responsibilities:
- Lock management
- Concurrency control
- Lock monitoring
- Lock cleanup

#### Implementation Requirements:
```php
class ExampleLockHandler implements Lock_Handler {
    public function acquireLock(string $key): bool {
        // Must implement atomic lock acquisition
        // Must handle timeouts
        // Must prevent race conditions
    }
    
    public function getActiveLocks(): array {
        return [
            [
                'key' => string,
                'timestamp' => int,
                'owner' => string
            ]
        ];
    }
}
```

### 5. Stats Collector Interface
Defines contract for statistics collection.

```php
interface Stats_Collector {
    public function startCampaign(string $campaign): void;
    public function endCampaign(string $campaign, array $result): void;
    public function updateStats(): void;
    public function recordMemoryCleanup(): void;
}
```

#### Key Responsibilities:
- Campaign tracking
- Performance monitoring
- Resource tracking
- Statistics aggregation

#### Implementation Requirements:
```php
class ExampleStatsCollector implements Stats_Collector {
    public function startCampaign(string $campaign): void {
        // Must record start time
        // Must initialize metrics
        // Must track resource baseline
    }
    
    public function endCampaign(string $campaign, array $result): void {
        // Must calculate duration
        // Must aggregate results
        // Must update statistics
    }
}
```

## Integration Guidelines

### 1. Error Handling
All interfaces should:
- Use appropriate exception types
- Provide detailed error context
- Maintain audit trails
- Support debugging

### 2. Performance Requirements
Implementations must:
- Handle large datasets
- Manage memory efficiently
- Implement timeouts
- Support concurrent operations

### 3. Data Integrity
All implementations must:
- Validate input data
- Maintain consistency
- Handle edge cases
- Prevent data corruption

## Implementation Best Practices

### 1. Provider Implementation
```php
// Implement robust error handling
try {
    $result = $provider->updateSubscriber($id, $data);
} catch (Provider_Exception $e) {
    // Log error with context
    // Attempt recovery
    // Maintain data consistency
}
```

### 2. Validation Implementation
```php
// Implement comprehensive validation
public function validate($data): bool {
    $this->clearErrors();
    
    // Type validation
    if (!is_array($data)) {
        $this->addError('Invalid data type');
        return false;
    }
    
    // Structure validation
    if (!$this->validateStructure($data)) {
        return false;
    }
    
    // Business rule validation
    return $this->validateBusinessRules($data);
}
```

### 3. Health Monitoring Implementation
```php
// Implement thorough health checks
public function performCheck(): void {
    // Check memory usage
    if (memory_get_usage(true) > $this->memoryThreshold) {
        $this->triggerAlert('Memory threshold exceeded');
    }
    
    // Check system load
    if ($this->isSystemOverloaded()) {
        $this->pauseProcessing();
    }
}
```

## Testing Requirements

Each interface implementation should include:
1. Unit tests for all methods
2. Integration tests for system interaction
3. Performance tests for scalability
4. Error handling tests
5. Edge case coverage

## Monitoring and Maintenance

### 1. Health Checks
```php
// Regular health monitoring
$monitor->performCheck();
$status = $monitor->getStatus();

if ($status['health_score'] < $threshold) {
    // Take corrective action
}
```

### 2. Lock Management
```php
// Lock cleanup
foreach ($lockHandler->getActiveLocks() as $lock) {
    if (isStale($lock)) {
        $lockHandler->releaseLock($lock['key']);
    }
}
```

### 3. Statistics Collection
```php
// Regular stats updates
$collector->updateStats();

// Memory management
$collector->recordMemoryCleanup();
```
