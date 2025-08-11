<?php

namespace Bema\Providers;

use Exception;
use WP_Object_Cache;
use Bema\BemaCRMLogger;
use Bema\Interfaces\Provider_Interface;
use Bema\Exceptions\API_Exception;
use function Bema\debug_to_file;

if (!defined('ABSPATH')) {
    exit;
}

class EDD implements Provider_Interface
{
    private $apiKey;
    private $siteUrl;
    private $token;
    private $logger;
    private $headers;
    private $cache;
    private $wpdb;

    // Performance settings
    private $maxRetries = 3;
    private $retryDelay = 5; // seconds
    private $timeout = 30; // seconds
    private $batchSize = 100;
    private $rateLimitDelay = 1; // 1 second between requests
    private $memoryThreshold = 0.8; // 80% of memory limit

    // Cache settings
    const CACHE_GROUP = 'edd_cache';
    const CACHE_TTL = 3600; // 1 hour
    const MAX_CACHE_SIZE = 10000; // Maximum number of cache entries
    const REQUEST_TIMEOUT = 30;
    const MAX_BATCH_RETRIES = 3;

    public function __construct($apiKey, $token, ?BemaCRMLogger $logger = null)
    {
        try {
            debug_to_file('Constructing EDD with API key present: ' . (!empty($apiKey) ? 'Yes' : 'No'), 'EDD_INIT');
            debug_to_file('Token present: ' . (!empty($token) ? 'Yes' : 'No'), 'EDD_INIT');

            global $wpdb;
            $this->wpdb = $wpdb;

            if (empty($apiKey)) {
                debug_to_file('Warning: Empty EDD credentials provided', 'EDD_INIT');
                $apiKey = '';
                $token = '';
            }

            $this->apiKey = $apiKey;
            $this->token = $token;
            $this->siteUrl = site_url();
            $this->logger = $logger;
            $this->setHeaders();

            debug_to_file('EDD instance constructed successfully', 'EDD_INIT');
        } catch (Exception $e) {
            debug_to_file('Error constructing EDD: ' . $e->getMessage(), 'EDD_ERROR');
        }
    }

    private function setHeaders(): void
    {
        $this->headers = [
            'User-Agent: Bema CRM Sync/1.0',
            'Accept-Encoding: gzip, deflate, br',
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Request-Id: ' . uniqid('edd_', true)
        ];
    }

    private function initializeCache(): void
    {
        $this->cleanupOldCache();
        $this->validateCacheIntegrity();
    }

    private function cleanupOldCache(): void
    {
        $cacheStats = wp_cache_get('cache_stats', self::CACHE_GROUP) ?: ['count' => 0];
        if ($cacheStats['count'] > self::MAX_CACHE_SIZE) {
            wp_cache_flush_group(self::CACHE_GROUP);
            $this->logger->log('Cache cleaned due to size limit', 'info', [
                'previous_size' => $cacheStats['count']
            ]);
        }
    }

    private function validateCacheIntegrity(): void
    {
        try {
            $testKey = 'test_' . uniqid();
            wp_cache_set($testKey, true, self::CACHE_GROUP, 60);
            if (!wp_cache_get($testKey, self::CACHE_GROUP)) {
                throw new Exception('Cache validation failed');
            }
            wp_cache_delete($testKey, self::CACHE_GROUP);
        } catch (Exception $e) {
            $this->logger->log('Cache integrity check failed', 'error', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function validateConnection(): bool
    {
        try {
            // First check if EDD is active
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $edd_active = \is_plugin_active('easy-digital-downloads/easy-digital-downloads.php') ||
                \is_plugin_active('easy-digital-downloads-pro/easy-digital-downloads.php');

            if (!$edd_active) {
                $this->logger?->log('EDD or EDD Pro not active', 'error');
                return false;
            }

            // Basic EDD function check
            if (!function_exists('EDD')) {
                $this->logger?->log('EDD function not available', 'error');
                return false;
            }

            // Instead of querying customers directly, check if EDD is properly initialized
            if (function_exists('edd_get_payment_statuses')) {
                $statuses = edd_get_payment_statuses();
                if (is_array($statuses) && !empty($statuses)) {
                    $this->logger?->log('EDD connection validated successfully', 'info');
                    return true;
                }
            }

            $this->logger?->log('EDD validation failed - core functionality not available', 'error');
            return false;
        } catch (Exception $e) {
            $this->logger?->log('Failed to validate EDD connection', 'error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    private function makeRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $baseParams = [
            'key' => $this->apiKey,
            'token' => $this->token,
            'format' => 'json'
        ];

        // Use direct request to WordPress admin-post.php for better local handling
        $api_url = home_url('index.php');
        $api_url = add_query_arg(['edd-api' => $endpoint], $api_url);
        $request_url = add_query_arg(array_merge($baseParams, $params), $api_url);

        $attempts = 0;
        $lastException = null;

        while ($attempts < $this->maxRetries) {
            try {
                // Use longer timeout for local environment
                $timeout = 30; // 30 seconds fixed timeout for local environment

                debug_to_file([
                    'request_started' => true,
                    'attempt' => $attempts + 1,
                    'url' => $request_url,
                    'timeout' => $timeout
                ], 'EDD_API_REQUEST');

                $args = [
                    'timeout' => $timeout,
                    'sslverify' => false,
                    'headers' => [
                        'Accept' => 'application/json',
                        'Cache-Control' => 'no-cache',
                        'X-Local-Test' => '1'
                    ],
                    'blocking' => true,
                    'decompress' => false,
                    'stream' => false,
                    'local' => true
                ];

                $start_time = microtime(true);
                $response = wp_remote_get($request_url, $args);
                $end_time = microtime(true);
                $duration = round($end_time - $start_time, 2);

                debug_to_file([
                    'request_duration' => $duration,
                    'attempt' => $attempts + 1
                ], 'EDD_API_REQUEST');

                if (is_wp_error($response)) {
                    throw new API_Exception(
                        'WP Error: ' . $response->get_error_message(),
                        $endpoint,
                        $method,
                        500
                    );
                }

                $statusCode = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);

                debug_to_file([
                    'response_received' => true,
                    'status_code' => $statusCode,
                    'response_length' => strlen($body)
                ], 'EDD_API_REQUEST');

                $decodedResponse = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new API_Exception(
                        'Failed to parse JSON response: ' . json_last_error_msg(),
                        $endpoint,
                        $method,
                        500,
                        false
                    );
                }

                return $decodedResponse;
            } catch (Exception $e) {
                $lastException = $e;
                $attempts++;

                debug_to_file([
                    'request_failed' => true,
                    'attempt' => $attempts,
                    'error' => $e->getMessage()
                ], 'EDD_API_REQUEST');

                if ($attempts >= $this->maxRetries) {
                    break;
                }

                // Use short delay between retries
                usleep(500000); // 0.5 second delay
            }
        }

        throw new API_Exception(
            "EDD API request failed after {$this->maxRetries} attempts: {$lastException->getMessage()}",
            $endpoint,
            $method,
            500,
            false
        );
    }

    private function parseHttpHeaders(array $headers): array
    {
        $parsed = [];
        foreach ($headers as $header) {
            if (preg_match('/HTTP\/[\d.]+\s+(\d+)/', $header, $matches)) {
                $parsed['response_code'] = (int) $matches[1];
                continue;
            }

            if (strpos($header, ':') !== false) {
                [$key, $value] = explode(':', $header, 2);
                $parsed[strtolower(trim($key))] = trim($value);
            }
        }
        return $parsed;
    }

    public function getSubscribers($status = 'active'): array
    {
        $cacheKey = 'edd_subscribers_' . md5($status);
        $cachedResult = wp_cache_get($cacheKey, self::CACHE_GROUP);

        if ($cachedResult !== false) {
            return $cachedResult;
        }

        try {
            $allCustomers = [];
            $page = 1;
            $hasMore = true;
            $totalProcessed = 0;

            while ($hasMore) {
                $response = $this->makeRequest('customers', [
                    'page' => $page,
                    'number' => $this->batchSize
                ]);

                if (empty($response['customers'])) {
                    break;
                }

                foreach ($response['customers'] as $customer) {
                    $allCustomers[] = [
                        'name' => $customer['first_name'] . ' ' . $customer['last_name'] ,
                        'email' => $customer['info']['email'],
                        'id' => $customer['info']['user_id'],
                        'purchase_count' => $customer['stats']['total_purchases'],
                        'purchase_value' => $customer['stats']['total_spent'],
                        'date_created' => $customer['info']['date_created']
                    ];

                    $totalProcessed++;
                }

                $page++;
                $hasMore = !empty($response['customers']);

                // Memory management
                if ($page % 10 === 0) {
                    $this->manageMemory();
                    $this->logger->log('Batch processing progress', 'debug', [
                        'processed' => $totalProcessed,
                        'current_page' => $page
                    ]);
                }

                // Rate limiting
                usleep($this->rateLimitDelay * 1000000);
            }

            wp_cache_set($cacheKey, $allCustomers, self::CACHE_GROUP, self::CACHE_TTL);

            $this->logger->log('Subscribers fetched successfully', 'info', [
                'total_subscribers' => count($allCustomers),
                'pages_processed' => $page - 1
            ]);

            return $allCustomers;
        } catch (Exception $e) {
            $this->logger->log('Failed to get EDD customers', 'error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    public function getSales(?string $product = null, int $page = 1, int $number = 100): array
    {
        $cacheKey = 'edd_sales_' . md5($product . $page . $number);
        $cachedResult = wp_cache_get($cacheKey, self::CACHE_GROUP);

        if ($cachedResult !== false) {
            return $cachedResult;
        }

        try {
            $response = $this->makeRequest('sales', [
                'product' => $product,
                'page' => $page,
                'number' => $number
            ]);

            if (!isset($response['sales'])) {
                $this->logger->log('No sales data found', 'warning', [
                    'product' => $product,
                    'page' => $page
                ]);
                return ['edd_emails' => [], 'sales_data' => []];
            }

            $salesData = [
                'edd_emails' => [],
                'sales_data' => []
            ];

            foreach ($response['sales'] as $sale) {
                if ($sale['status'] !== 'complete') {
                    continue;
                }

                if ($product && !$this->saleContainsProduct($sale, $product)) {
                    continue;
                }

                $salesData['edd_emails'][] = $sale['email'];
                $salesData['sales_data'][] = [
                    'email' => $sale['email'],
                    'purchase_amount' => $sale['total'],
                    'purchase_date' => $sale['date'],
                    'products' => array_column($sale['products'], 'name'),
                    'transaction_id' => $sale['ID'],
                    'status' => $sale['status']
                ];
            }

            wp_cache_set($cacheKey, $salesData, self::CACHE_GROUP, self::CACHE_TTL);

            $this->logger->log('Sales data fetched successfully', 'info', [
                'total_sales' => count($salesData['sales_data']),
                'product' => $product ?? 'all'
            ]);

            return $salesData;
        } catch (Exception $e) {
            $this->logger->log('Failed to get EDD sales', 'error', [
                'error' => $e->getMessage(),
                'product' => $product,
                'page' => $page
            ]);
            return ['edd_emails' => [], 'sales_data' => []];
        }
    }

    private function saleContainsProduct(array $sale, string $product): bool
    {
        foreach ($sale['products'] as $purchasedProduct) {
            if (strcasecmp($purchasedProduct['name'], $product) === 0) {
                return true;
            }
        }
        return false;
    }

    public function getSalesBatch(array $params = []): \Generator
    {
        $page = 1;
        $hasMore = true;
        $totalProcessed = 0;

        while ($hasMore) {
            $params['page'] = $page;
            $params['number'] = $this->batchSize;

            try {
                $batch = $this->getSales(
                    $params['product'] ?? null,
                    $page,
                    $this->batchSize
                );

                if (empty($batch['sales_data'])) {
                    $hasMore = false;
                } else {
                    $totalProcessed += count($batch['sales_data']);
                    $this->logger->log('Batch processing progress', 'debug', [
                        'processed' => $totalProcessed,
                        'current_page' => $page
                    ]);

                    yield $batch;
                    $page++;
                }

                // Rate limiting
                usleep($this->rateLimitDelay * 1000000);

                // Memory management
                if ($page % 10 === 0) {
                    $this->manageMemory();
                }
            } catch (Exception $e) {
                $this->logger->log('Failed to get sales batch', 'error', [
                    'page' => $page,
                    'error' => $e->getMessage()
                ]);
                $hasMore = false;
            }
        }
    }

    private function manageMemory(): void
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimitInBytes();

        if ($memoryUsage > ($memoryLimit * $this->memoryThreshold)) {
            $this->logger->log('Memory cleanup triggered', 'warning', [
                'usage' => $this->formatBytes($memoryUsage),
                'limit' => $this->formatBytes($memoryLimit)
            ]);

            if (function_exists('gc_collect_cycles')) {
                $collected = gc_collect_cycles();
                $this->logger->log('Garbage collection completed', 'debug', [
                    'collected' => $collected
                ]);
            }

            $this->cache->flush();
        }
    }

    private function errorHandler($errno, $errstr, $errfile, $errline): bool
    {
        $this->logger->log('PHP Error in EDD class', 'error', [
            'errno' => $errno,
            'error' => $errstr,
            'file' => $errfile,
            'line' => $errline
        ]);
        return true;
    }

    private function getMemoryLimitInBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1')
            return PHP_INT_MAX;

        preg_match('/^(\d+)(.)$/', $memoryLimit, $matches);
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

        return $value;
    }

    private function formatBytes($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    /**
     * get_albums: All products/albums in EDD
     * 
     * @return array
     */
    public function get_albums (): array {
        try {
            $response = $this->makeRequest("products");

            if (isset($response['products'])) {
                $this->logger->log('Products fetched successfully', 'info', [
                    'total_products' => count($response['products']),
                    'product' => "All Products"
                ]);

                return $response['products'];
            }

            return [];
        } catch (Exception $e) {
            $this->logger->log('Failed to fetch EDD Products/Albums', 'error', [
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    public function updateSubscriber($id, $data): bool
    {
        try {
            $updateData = array_intersect_key($data, [
                'email' => true,
                'first_name' => true,
                'last_name' => true
            ]);

            if (empty($updateData)) {
                return false;
            }

            $response = $this->makeRequest("customers/{$id}", $updateData, 'PUT');

            if (isset($response['customer'])) {
                $this->invalidateCustomerCache($id);
                $this->logger->log('Customer updated successfully', 'info', [
                    'customer_id' => $id
                ]);
                return true;
            }

            return false;
        } catch (Exception $e) {
            $this->logger->log('Failed to update EDD customer', 'error', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function findProductByNamePattern(string $artist, string $product): ?int
    {
        try {
            debug_to_file([
                'searching_for_product' => true,
                'artist' => $artist,
                'product' => $product
            ], 'EDD_PRODUCT_SEARCH');

            // Convert product code to actual title
            $productTitle = '';
            switch ($product) {
                case 'NBL':
                    $productTitle = 'No Better Love';
                    break;
                case 'EOE':
                    $productTitle = 'Elements of Earth';
                    break;
                default:
                    $productTitle = $product;
            }

            // Search directly in wp_posts table
            $query = $this->wpdb->prepare(
                "SELECT ID FROM {$this->wpdb->posts} 
            WHERE post_type = 'download' 
            AND post_title LIKE %s",
                $productTitle
            );

            $productId = $this->wpdb->get_var($query);

            debug_to_file([
                'product_search_results' => [
                    'product_code' => $product,
                    'product_title' => $productTitle,
                    'found_id' => $productId
                ]
            ], 'EDD_PRODUCT_SEARCH');

            return $productId ? (int)$productId : null;
        } catch (Exception $e) {
            $this->logger->log('Failed to find EDD product', 'error', [
                'artist' => $artist,
                'product' => $product,
                'error' => $e->getMessage()
            ]);

            debug_to_file([
                'product_search_failed' => true,
                'error' => $e->getMessage()
            ], 'EDD_PRODUCT_SEARCH');

            return null;
        }
    }

    public function hasUserPurchasedProduct(int $user_id, int $product_id)
    {
        try {
            debug_to_file([
                'checking_purchase' => 1,
                'user_id' => $user_id,
                'product_id' => $product_id
            ], 'EDD_PURCHASE_CHECK');


            $has_purchased =  edd_has_user_purchased( absint($user_id), $product_id );

            debug_to_file([
                'purchase_check_result' => [
                    'user_id' => $user_id,
                    'product_id' => $product_id,
                    'has_purchased' => $has_purchased
                ]
            ], 'EDD_PURCHASE_CHECK');

            return $has_purchased;
        } catch (Exception $e) {
            $this->logger->log('Failed to check purchase status', 'error', [
                'user_id' => $user_id,
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function addOrUpdateSubscriber(array $data): string
    {
        $this->logger->log('Method not supported in EDD', 'warning');
        return '0';
    }

    public function addSubscriberToGroup($subscriberId, $groupId): bool
    {
        $this->logger->log('Method not supported in EDD', 'warning');
        return true;
    }

    public function removeSubscriberFromGroup($subscriberId, $groupId): bool
    {
        $this->logger->log('Method not supported in EDD', 'warning');
        return true;
    }

    public function getGroups(): array
    {
        return [];
    }

    private function invalidateCustomerCache($customerId): void
    {
        $pattern = 'edd_*';
        wp_cache_delete($pattern, self::CACHE_GROUP);
        $this->logger->log('Customer cache invalidated', 'debug', [
            'customer_id' => $customerId
        ]);
    }

    public function setBatchSize(int $size): void
    {
        $this->batchSize = max(1, min($size, 1000));
        $this->logger->log('Batch size updated', 'debug', [
            'new_size' => $this->batchSize
        ]);
    }

    public function test_connection(): bool
    {
        try {
            // First check if EDD/EDD Pro is active
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $edd_active = \is_plugin_active('easy-digital-downloads/easy-digital-downloads.php') ||
                \is_plugin_active('easy-digital-downloads-pro/easy-digital-downloads.php');

            if (!$edd_active) {
                debug_to_file('EDD or EDD Pro not active', 'API_TEST');
                return false;
            }

            if (empty($this->apiKey) || empty($this->token)) {
                debug_to_file('EDD API credentials missing', 'API_TEST');
                return false;
            }

            // Try up to 3 times with different endpoints
            $endpoints = [
                'info',             // Try orders endpoint next
                'stats',         // Try stats as backup
                'products',      // Try product endpoint   
                'customers'      // Try customers as last resort
            ];

            $attempts = 0;
            $max_attempts = 3;

            foreach ($endpoints as $endpoint) {
                if ($attempts >= $max_attempts)
                    break;

                $api_params = [
                    'key' => $this->apiKey,
                    'token' => $this->token,
                    'format' => 'json'
                ];

                // Use direct request to WordPress index.php
                $api_url = home_url('index.php');
                $api_url = add_query_arg(['edd-api' => $endpoint], $api_url);
                $test_url = add_query_arg($api_params, $api_url);

                debug_to_file("Testing EDD API URL (attempt " . ($attempts + 1) . "): " . $test_url, 'API_TEST');

                // Progressively increase timeout with each attempt
                $timeout = 5 + ($attempts * 2);

                $args = [
                    'timeout' => $timeout,
                    'sslverify' => false,
                    'headers' => [
                        'Accept' => 'application/json',
                        'Cache-Control' => 'no-cache',
                        'X-Local-Test' => '1'
                    ],
                    'blocking' => true,
                    'decompress' => false,
                    'stream' => false,
                    'local' => true
                ];

                $start_time = microtime(true);
                debug_to_file("Starting API request with {$timeout}s timeout...", 'API_TEST');

                $response = wp_remote_get($test_url, $args);

                $end_time = microtime(true);
                $duration = round($end_time - $start_time, 2);
                debug_to_file("API request took {$duration} seconds", 'API_TEST');

                if (!is_wp_error($response)) {
                    $code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);

                    debug_to_file('EDD API response details:', 'API_TEST');
                    debug_to_file('Status code: ' . $code, 'API_TEST');
                    debug_to_file('Response body: ' . substr($body, 0, 500), 'API_TEST');

                    // If we get any valid response, consider it a success
                    if ($code >= 200 && $code < 300) {
                        return true;
                    }
                }

                debug_to_file('Attempt ' . ($attempts + 1) . ' failed, trying next endpoint', 'API_TEST');
                $attempts++;

                // Short delay between attempts
                if ($attempts < count($endpoints)) {
                    usleep(500000); // 0.5 second delay
                }
            }

            debug_to_file('All attempts failed', 'API_TEST');
            return false;
        } catch (Exception $e) {
            debug_to_file('EDD test_connection exception: ' . $e->getMessage(), 'API_TEST');
            debug_to_file('Stack trace: ' . $e->getTraceAsString(), 'API_TEST');
            return false;
        }
    }
}