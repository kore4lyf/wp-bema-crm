# System Handlers Documentation

## Overview
The system handlers provide essential functionality for monitoring, locking, and statistics collection in the BEMA CRM synchronization system.

## Components

### 1. Health Monitor
Monitors system health and performance during synchronization operations.

#### Interface
```php
interface Health_Monitor {
    public function startMonitoring(string $jobId): void;
    public function stopMonitoring(string $jobId): void;
    public function performCheck(): void;
    public function getStatus(): array;
    public function checkCampaign(string $campaign): void;
}
```

#### Implementation
```php
class Default_Health_Monitor implements Health_Monitor {
    private $logger;

    public function startMonitoring(string $jobId): void {
        $this->logger->log("Started monitoring job", 'info', [
            'job_id' => $jobId,
            'timestamp' => time()
        ]);
    }

    public function performCheck(): void {
        // Check system resources
        $memory_usage = memory_get_usage(true);
        $load = sys_getloadavg();
        
        // Log status
        $this->logger->log("Health check performed", 'info', [
            'memory_usage' => $memory_usage,
            'load_average' => $load
        ]);
    }
}
```

#### Usage Example
```php
$monitor = new Default_Health_Monitor($logger);
$monitor->startMonitoring('sync_123');

try {
    // Sync operations
    $monitor->checkCampaign('2024_ETB_NBL');
} finally {
    $monitor->stopMonitoring('sync_123');
}
```

### 2. Lock Handler
Manages synchronization locks to prevent concurrent operations.

#### Interface
```php
interface Lock_Handler {
    public function acquireLock(string $key): bool;
    public function releaseLock(string $key): bool;
    public function getActiveLocks(): array;
}
```

#### Implementation
```php
class Default_Lock_Handler implements Lock_Handler {
    public function acquireLock(string $key): bool {
        return set_transient($key, time(), 900); // 15 minutes
    }

    public function releaseLock(string $key): bool {
        return delete_transient($key);
    }

    public function getActiveLocks(): array {
        // Query active locks from database
        return array_map(function ($lock) {
            return [
                'key' => $lock['key'],
                'timestamp' => $lock['timestamp']
            ];
        }, $activeLocks);
    }
}
```

#### Usage Example
```php
$lockHandler = new Default_Lock_Handler();
$lockKey = 'bema_sync_lock_campaign_123';

if ($lockHandler->acquireLock($lockKey)) {
    try {
        // Perform synchronized operation
    } finally {
        $lockHandler->releaseLock($lockKey);
    }
}
```

### 3. Stats Collector
Collects and maintains synchronization statistics.

#### Interface
```php
interface Stats_Collector {
    public function startCampaign(string $campaign): void;
    public function endCampaign(string $campaign, array $result): void;
    public function updateStats(): void;
    public function recordMemoryCleanup(): void;
}
```

#### Implementation
```php
class Default_Stats_Collector implements Stats_Collector {
    private $stats = [];

    public function startCampaign(string $campaign): void {
        $this->stats[$campaign] = [
            'start_time' => time(),
            'status' => 'running'
        ];
    }

    public function endCampaign(string $campaign, array $result): void {
        $this->stats[$campaign] = array_merge(
            $this->stats[$campaign] ?? [],
            [
                'end_time' => time(),
                'status' => 'completed',
                'result' => $result
            ]
        );
    }
}
```

## Integration

### 1. With Sync Process
```php
class Sync_Process {
    private $healthMonitor;
    private $lockHandler;
    private $statsCollector;
    
    public function sync(string $campaign): bool {
        $lockKey = "sync_lock_{$campaign}";
        
        if (!$this->lockHandler->acquireLock($lockKey)) {
            return false;
        }
        
        try {
            $this->healthMonitor->startMonitoring($campaign);
            $this->statsCollector->startCampaign($campaign);
            
            // Sync operations
            
            $this->statsCollector->endCampaign($campaign, $result);
            return true;
        } finally {
            $this->healthMonitor->stopMonitoring($campaign);
            $this->lockHandler->releaseLock($lockKey);
        }
    }
}
```

## Monitoring and Maintenance

### 1. Health Checks
```php
// Regular health check
$monitor->performCheck();

// Get current status
$status = $monitor->getStatus();
```

### 2. Lock Management
```php
// Get active locks
$activeLocks = $lockHandler->getActiveLocks();

// Clean up stale locks
foreach ($activeLocks as $lock) {
    if (time() - $lock['timestamp'] > 3600) {
        $lockHandler->releaseLock($lock['key']);
    }
}
```

### 3. Statistics Collection
```php
// Update stats
$statsCollector->updateStats();

// Record memory cleanup
$statsCollector->recordMemoryCleanup();
```

## Best Practices

### 1. Lock Handling
- Always use try-finally blocks with locks
- Implement timeouts for lock acquisition
- Clean up stale locks regularly
- Monitor lock contention

### 2. Health Monitoring
- Regular system checks
- Resource usage monitoring
- Performance metrics collection
- Alert on threshold violations

### 3. Statistics Collection
- Aggregate data regularly
- Maintain historical data
- Clean up old statistics
- Monitor trends and patterns

## Error Handling

### 1. Lock Errors
```php
try {
    if (!$lockHandler->acquireLock($key)) {
        throw new RuntimeException("Failed to acquire lock");
    }
} catch (Exception $e) {
    // Handle lock acquisition failure
} finally {
    $lockHandler->releaseLock($key);
}
```

### 2. Health Check Errors
```php
try {
    $monitor->performCheck();
} catch (Exception $e) {
    $logger->log("Health check failed", 'error', [
        'error' => $e->getMessage()
    ]);
}
```

### 3. Statistics Errors
```php
try {
    $statsCollector->updateStats();
} catch (Exception $e) {
    $logger->log("Stats update failed", 'error', [
        'error' => $e->getMessage()
    ]);
}
```
