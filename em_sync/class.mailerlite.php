<?php

namespace Bema\Providers;

use Exception;
use WP_Object_Cache;
use Bema\BemaCRMLogger;
use Bema\Interfaces\Provider_Interface;
use Bema\Exceptions\API_Exception;
use Bema\Group_Database_Manager;
use Bema\Field_Database_Manager;
use function Bema\debug_to_file;

if (!defined('ABSPATH')) {
    exit;
}

class MailerLite implements Provider_Interface
{
    private $apiKey;
    private $headers;
    private $logger;
    private $cache;
    private $baseUrl = 'https://connect.mailerlite.com/api';
    private $maxRetries = 3;
    private $retryDelay = 5; // seconds
    private $rateLimitDelay = 1; // 1 second between requests
    private $batchSize = 100;
    private $timeout = 30;
    private $rateLimitRemaining = null;
    private $rateLimitReset = null;

    // Cache settings
    const CACHE_GROUP = 'mailerlite_cache';
    const CACHE_TTL = 3600; // 1 hour
    const RATE_LIMIT_KEY = 'mailerlite_rate_limit';

    public function __construct($apiKey, ?BemaCRMLogger $logger = null)
    {
        try {
            debug_to_file('Constructing MailerLite with API key present: ' . (!empty($apiKey) ? 'Yes' : 'No'), 'ML_INIT');

            if (empty($apiKey)) {
                debug_to_file('Warning: Empty MailerLite API key provided', 'ML_INIT');
                $apiKey = '';
            }

            $this->apiKey = $apiKey;
            $this->group_db_manager = new Group_Database_Manager($logger);
            $this->field_db_manager = new Field_Database_Manager($logger);
            $this->logger = $logger;
            $this->setHeaders();
            $this->initializeRateLimit();

            debug_to_file('MailerLite instance constructed successfully', 'ML_INIT');
        } catch (Exception $e) {
            debug_to_file('Error constructing MailerLite: ' . $e->getMessage(), 'ML_ERROR');
        }
    }

    private function setHeaders(): void
    {
        $this->headers = [
            'User-Agent: Bema CRM Sync/1.0',
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'X-Request-Id: ' . uniqid('bema_', true)
        ];
    }

    private function initializeRateLimit(): void
    {
        $rateLimit = wp_cache_get(self::RATE_LIMIT_KEY, self::CACHE_GROUP);
        if ($rateLimit) {
            $this->rateLimitRemaining = $rateLimit['remaining'];
            $this->rateLimitReset = $rateLimit['reset'];
        }
    }

    private function updateRateLimit(array $headers): void
    {
        if (isset($headers['x-ratelimit-remaining'])) {
            $this->rateLimitRemaining = (int) $headers['x-ratelimit-remaining'];
            $this->rateLimitReset = isset($headers['x-ratelimit-reset']) ?
                (int) $headers['x-ratelimit-reset'] : (time() + 60);

            wp_cache_set(
                self::RATE_LIMIT_KEY,
                [
                    'remaining' => $this->rateLimitRemaining,
                    'reset' => $this->rateLimitReset
                ],
                self::CACHE_GROUP,
                60
            );
        }
    }

    private function waitForRateLimit(): void
    {
        if ($this->rateLimitRemaining !== null && $this->rateLimitRemaining < 1) {
            $waitTime = max(0, $this->rateLimitReset - time());
            if ($waitTime > 0) {
                $this->logger->log("Rate limit reached, waiting {$waitTime} seconds", 'warning');
                sleep($waitTime);
            }
        }
        usleep($this->rateLimitDelay * 1000000);
    }

    private function makeRequest(string $endpoint, string $method, ?array $data = null): array
    {
        if (empty($this->apiKey)) {
            throw new API_Exception(
                'API key is required for MailerLite requests',
                $endpoint,
                $method,
                401
            );
        }

        debug_to_file([
            'request_started' => true,
            'endpoint' => $endpoint,
            'method' => $method
        ], 'ML_API_REQUEST');

        $url = "{$this->baseUrl}/{$endpoint}";
        $attempts = 0;
        $lastException = null;

        while ($attempts < $this->maxRetries) {
            try {
                $this->waitForRateLimit();

                debug_to_file([
                    'making_request' => true,
                    'attempt' => $attempts + 1,
                    'url' => $url
                ], 'ML_API_REQUEST');

                $ch = curl_init($url);

                $headers = [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json'
                ];

                $options = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false
                ];

                if ($data !== null) {
                    $options[CURLOPT_POSTFIELDS] = json_encode($data);
                }

                curl_setopt_array($ch, $options);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                debug_to_file([
                    'response_received' => true,
                    'status_code' => $httpCode,
                    'response_length' => strlen($response)
                ], 'ML_API_REQUEST');

                if ($response === false) {
                    throw new API_Exception(
                        'cURL error: ' . $error,
                        $endpoint,
                        $method,
                        $httpCode
                    );
                }

                if ($httpCode === 204) {
                    // MailerLite returns 204 with an empty body for successful DELETE.
                    // Return an empty array or null to signal success.
                    return [];
                }

                $decoded = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new API_Exception(
                        'Invalid JSON response: ' . json_last_error_msg(),
                        $endpoint,
                        $method,
                        $httpCode
                    );
                }

                if ($httpCode >= 400) {
                    $errorMessage = isset($decoded['message']) ? $decoded['message'] : 'Unknown error';
                    throw new API_Exception(
                        "API error ({$httpCode}): {$errorMessage}",
                        $endpoint,
                        $method,
                        $httpCode
                    );
                }

                return $decoded;
            } catch (Exception $e) {
                $lastException = $e;
                $attempts++;

                debug_to_file([
                    'request_failed' => true,
                    'attempt' => $attempts,
                    'error' => $e->getMessage()
                ], 'ML_API_REQUEST');

                if ($attempts >= $this->maxRetries) {
                    break;
                }

                sleep($this->retryDelay * $attempts);
            }
        }

        throw new API_Exception(
            "Failed after {$this->maxRetries} attempts: " . $lastException->getMessage(),
            $endpoint,
            $method,
            500
        );
    }

    /**
     * Validate connection to MailerLite API
     * @return bool
     * @throws API_Exception
     */
    public function validateConnection(): bool
    {
        try {
            debug_to_file('Testing MailerLite connection', 'ML_VALIDATE');

            if (empty($this->apiKey)) {
                debug_to_file('MailerLite API key not provided', 'ML_VALIDATE');
                throw new API_Exception(
                    'MailerLite API key not provided',
                    'validation',
                    'GET',
                    401
                );
            }

            // Try to get groups as a simple validation test
            $response = $this->makeRequest(
                'groups?limit=1',
                'GET'
            );

            debug_to_file([
                'validation_response' => $response,
                'validation_successful' => true
            ], 'ML_VALIDATE');

            return true;
        } catch (Exception $e) {
            debug_to_file([
                'validation_failed' => true,
                'error' => $e->getMessage()
            ], 'ML_VALIDATE');

            throw new API_Exception(
                'MailerLite API validation failed: ' . $e->getMessage(),
                'validation',
                'GET',
                500
            );
        }
    }

    /**
     * Fetches a single subscriber from MailerLite by their ID or email.
     *
     * @param string|int $identifier The subscriber's ID or email address.
     * @return array The subscriber data, including fields, status, etc.
     * @throws Exception Throws an exception if the identifier is missing or the API call fails.
     */
    public function getSubscriber(string|int $identifier): array
    {
        try {
            if (empty($identifier)) {
                $this->logger->log('Fetch failed: Missing subscriber identifier', 'error');
                throw new Exception('Missing subscriber identifier for fetch operation.');
            }

            $this->logger->log('Attempting to fetch single subscriber', 'debug', ['identifier' => $identifier]);

            // The MailerLite API uses the same endpoint for ID and email
            $response = $this->makeRequest("subscribers/{$identifier}", 'GET');

            // Check if the response was successful and contains data
            if (isset($response['data'])) {
                $subscriberData = $response['data'];
                $this->logger->log('Subscriber fetched successfully', 'info', ['id' => $subscriberData['id']]);
                return $subscriberData;
            } else {
                // Log the API failure response and throw an exception
                $this->logger->log('Failed to fetch subscriber from API', 'error', [
                    'identifier' => $identifier,
                    'response' => $response
                ]);
                throw new API_Exception(
                    'Failed to fetch subscriber from MailerLite.',
                    'subscribers/' . $identifier,
                    'GET',
                    $response['status_code'] ?? 0,
                    true
                );
            }

        } catch (Exception $e) {
            // Re-throw the exception to be handled by the caller
            throw new API_Exception(
                'Failed to get subscriber from MailerLite: ' . $e->getMessage(),
                'subscribers',
                'GET',
                $e->getCode(),
                true
            );
        }
    }


    /**
     * Fetches subscribers from MailerLite with support for a custom limit.
     *
     * This function handles cursor-based pagination from the MailerLite API.
     * It will stop fetching when the requested limit is reached.
     *
     * @param array $params Optional parameters for filtering and limiting the results.
     * e.g., ['limit' => 10, 'email' => 'test@example.com']
     * @return array A paginated array of subscriber data.
     * @throws Exception Throws an exception if the API call fails.
     */
    public function getSubscribers($params = []): array
    {
        $cacheKey = 'subscribers_' . md5(serialize($params));
        $cachedResult = wp_cache_get($cacheKey, self::CACHE_GROUP);

        if ($cachedResult !== false) {
            return $cachedResult;
        }

        try {
            $allSubscribers = [];
            $cursor = null;
            $limit = $params['limit'] ?? null; // Get the user's requested limit
            $count = 0; // Initialize a counter for fetched subscribers

            // Remove the limit from the params array so it doesn't get sent to the API incorrectly
            unset($params['limit']);

            do {
                $this->waitForRateLimit();

                $requestParams = $params;
                if ($cursor) {
                    $requestParams['cursor'] = $cursor;
                }

                // If a limit is specified, adjust the API's limit to not fetch more than needed
                $batchLimit = $this->batchSize;
                if ($limit !== null) {
                    $remaining = $limit - $count;
                    if ($remaining <= 0) {
                        break; // Stop if we've already met the limit
                    }
                    $batchLimit = min($remaining, $this->batchSize);
                }
                $requestParams['limit'] = $batchLimit;

                $response = $this->makeRequest(
                    "subscribers?" . http_build_query($requestParams),
                    'GET'
                );

                if (!isset($response['data']) || empty($response['data'])) {
                    break;
                }

                foreach ($response['data'] as $subscriber) {
                    if ($limit !== null && $count >= $limit) {
                        break; // Stop adding subscribers if the limit is reached
                    }
                    $allSubscribers[] = [
                        'email' => $subscriber['email'],
                        'id' => $subscriber['id'],
                        'fields' => [
                            'first_name' => $subscriber['fields']['first_name'] ?? '',
                            'last_name' => $subscriber['fields']['last_name'] ?? '',
                            'name' => $subscriber['fields']['name'] ?? ''
                        ],
                        'status' => $subscriber['status'],
                        'subscribed_at' => $subscriber['subscribed_at'] ?? null,
                        'updated_at' => $subscriber['updated_at'] ?? null
                    ];
                    $count++;
                }

                $cursor = $response['meta']['next_cursor'] ?? null;

            } while ($cursor !== null && ($limit === null || $count < $limit));

            wp_cache_set($cacheKey, $allSubscribers, self::CACHE_GROUP, self::CACHE_TTL);
            return $allSubscribers;

        } catch (Exception $e) {
            throw new API_Exception(
                'Failed to get subscribers from MailerLite: ' . $e->getMessage(),
                'subscribers',
                'GET',
                $e->getCode(),
                true
            );
        }
    }

    // In class.mailerlite.php, add:

    public function verifyTierUpdate(string $subscriberId, string $expectedTier, int $maxAttempts = 5): bool
    {
        $attempts = 0;
        $delay = 30; // 30 seconds between checks

        while ($attempts < $maxAttempts) {
            $subscriber = $this->getSubscriberDetails($subscriberId);
            if (isset($subscriber['fields']['tier']) && $subscriber['fields']['tier'] === $expectedTier) {
                return true;
            }
            sleep($delay);
            $attempts++;
        }
        return false;
    }

    public function getSubscriberDetails(string $subscriberIdOrEmail): array
    {
        return $this->makeRequest("subscribers/{$subscriberIdOrEmail}", 'GET');
    }

    public function addOrUpdateSubscriber(array $data): string
    {
        if (!isset($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new API_Exception('Invalid email format', 'subscribers', 'POST', 400, false);
        }

        try {
            $this->waitForRateLimit();

            $subscriberData = [
                'email' => $data['email'],
                'fields' => [
                    'first_name' => $data['first_name'] ?? '',
                    'last_name' => $data['last_name'] ?? '',
                    'name' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''))
                ],
                'status' => 'active',
                'groups' => $data['groups'] ?? [],
                'custom_fields' => $data['custom_fields'] ?? []
            ];

            $response = $this->makeRequest('subscribers', 'POST', $subscriberData);

            if (!isset($response['data']['id'])) {
                throw new API_Exception('Invalid response format', 'subscribers', 'POST', 500, false);
            }

            $this->invalidateSubscriberCache($response['data']['id']);
            $this->logger->log('Subscriber added/updated successfully', 'info', [
                'email' => $data['email'],
                'id' => $response['data']['id']
            ]);

            return $response['data']['id'];
        } catch (Exception $e) {
            throw new API_Exception(
                "Failed to add/update subscriber: {$e->getMessage()}",
                'subscribers',
                'POST',
                500,
                true
            );
        }
    }

    public function addSubscriberToGroup($subscriberId, $groupId): bool
    {
        try {
            $this->waitForRateLimit();

            $this->makeRequest(
                "subscribers/{$subscriberId}/groups/{$groupId}",
                'POST',
                ['status' => 'active']
            );

            $this->invalidateSubscriberCache($subscriberId);
            $this->logger->log('Subscriber added to group', 'info', [
                'subscriber_id' => $subscriberId,
                'group_id' => $groupId
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->log('Failed to add subscriber to group', 'error', [
                'subscriber_id' => $subscriberId,
                'group_id' => $groupId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function removeSubscriberFromGroup($subscriberId, $groupId): bool
    {
        try {
            $this->waitForRateLimit();

            $this->makeRequest(
                "subscribers/{$subscriberId}/groups/{$groupId}",
                'DELETE'
            );

            $this->invalidateSubscriberCache($subscriberId);
            $this->logger->log('Subscriber removed from group', 'info', [
                'subscriber_id' => $subscriberId,
                'group_id' => $groupId
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->log('Failed to remove subscriber from group', 'error', [
                'subscriber_id' => $subscriberId,
                'group_id' => $groupId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Creates a new custom field in MailerLite.
     *
     * @param string $name The name of the field (max 255 characters).
     * @param string $type The field type, which can be 'text', 'number', or 'date'.
     * @return string empty string if the field was not created, otherwise the field id.
     * @throws Exception If the underlying `makeRequest` method throws an exception.
     */
    public function createField(string $name, string $type): bool|string
    {
        try {
            // Prepare the payload for the API request with both name and type.
            $payload = [
                'name' => $name,
                'type' => $type
            ];

            // Send a POST request to the 'fields' endpoint.
            $response = $this->makeRequest('fields', 'POST', $payload);
            error_log("Group Created: " . print_r($response, true) . "\n", 3, dirname(__FILE__) . '/debug.log');

            // Check for a successful response by ensuring the 'id' key exists in the data array.
            return $response['data']['id'];

        } catch (Exception $e) {
            // Log the failure to create the field.
            $this->logger->log('Failed to create MailerLite field', 'error', [
                'field_name' => $name,
                'field_type' => $type,
                'error_message' => $e->getMessage()
            ]);
            error_log("error creating group: " . $e->getMessage() . "\n", 3, dirname(__FILE__) . '/debug.log');
            return '';
        }
    }

    /**
     * Updates the name of an existing custom field in MailerLite.
     *
     * @param string $current_name The current field name stored in the local database.
     * @param string $new_name The new name for the field (max 255 characters).
     * @return bool True if the field was successfully updated, otherwise false.
     * @throws Exception If the underlying `makeRequest` method throws an exception.
     */
    public function updateField(string $current_name, string $new_name): bool
    {
        try {
            // Get field details from the database
            $field = $this->field_db_manager->get_field_by_name($current_name);

            if (!$field || empty($field['field_id'])) {
                $this->logger->log('Field not found in database', 'error', [
                    'field_name' => $current_name
                ]);     
                error_log("Field not found: " . $current_name . "\n", 3, dirname(__FILE__) . '/debug.log');
                return false;
            }

            $field_id = (int) $field['field_id'];

            // Prepare the payload with the new field name
            $payload = [
                'name' => $new_name
            ];

            // Send a PUT request to the 'fields/{field_id}' endpoint
            $endpoint = 'fields/' . $field_id;
            $response = $this->makeRequest($endpoint, 'PUT', $payload);

            // Log the response for debugging
            error_log("Field Updated: " . print_r($response, true) . "\n", 3, dirname(__FILE__) . '/debug.log');

            if (isset($response['data'])) {
                return true;
            }

            return false;

        } catch (Exception $e) {
            // Log any error during update
            $this->logger->log('Failed to update MailerLite field', 'error', [
                'current_name' => $current_name,
                'new_name' => $new_name,
                'error_message' => $e->getMessage()
            ]);
            error_log("Error updating field: " . $e->getMessage() . "\n", 3, dirname(__FILE__) . '/debug.log');
            return false;
        }
    }

    /**
     * Deletes a custom field in MailerLite.
     *
     * @param string $field_name The field name to delete (looked up from the local database).
     * @return bool True if the field was successfully deleted, otherwise false.
     * @throws Exception If the underlying `makeRequest` method throws an exception.
     */
    public function deleteField(string $field_name): bool
    {
        try {
            error_log("deleteField: Start" . "\n", 3, dirname(__FILE__) . '/debug.log');

            // Get field details from the database
            $field = $this->field_db_manager->get_field_by_name($field_name);
            error_log("deleteField: " . print_r($field, true) . "\n", 3, dirname(__FILE__) . '/debug.log');

            if (!$field || empty($field['field_id'])) {
                $this->logger->log('Field not found in database', 'error', [
                    'field_name' => $field_name
                ]);
                
                error_log("Field not found: " . $field_name . "\n", 3, dirname(__FILE__) . '/debug.log');
                return false;
            }

            $field_id = $field['field_id'];

            // Send a DELETE request to the 'fields/{field_id}' endpoint
            $endpoint = 'fields/' . $field_id;
            $response = $this->makeRequest($endpoint, 'DELETE');

            // Log the response for debugging
            error_log("Field Deleted Response: " . print_r($response, true) . "\n", 3, dirname(__FILE__) . '/debug.log');

            // MailerLite returns 204 No Content on success, so response may be empty
            if ($response === null || $response === [] || (isset($response['status']) && $response['status'] === 204)) {
                return true;
            }

            return false;

        } catch (Exception $e) {
            // Log any error during delete
            $this->logger->log('Failed to delete MailerLite field', 'error', [
                'field_name' => $field_name,
                'error_message' => $e->getMessage()
            ]);
            error_log("Error deleting field: " . $e->getMessage() . "\n", 3, dirname(__FILE__) . '/debug.log');
            return false;
        }
    }


    /**
     * Creates a new subscriber group in MailerLite.
     *
     * @param string $name The name for the new group (max 255 characters).
     * @return array|null The new group's data array on success, or null on failure.
     * @throws Exception If the underlying `makeRequest` method throws an exception.
     */
    public function createGroup(string $name): ?array
    {
        try {

            // Prepare the payload for the API request.
            $payload = ['name' => $name];

            // Send a POST request to the 'groups' endpoint.
            $response = $this->makeRequest('groups', 'POST', $payload);

            // Return the group's data on a successful response.
            return $response['data'];

        } catch (Exception $e) {
            // Log the failure to create the group, including the name and error message.
            $this->logger->log('Failed to create MailerLite group', 'error', [
                'group_name' => $name,
                'error_message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Deletes a subscriber group in MailerLite.
     *
     * @param string $group_name The name of the group to delete (looked up from the local database).
     * @return bool True if the group was successfully deleted, otherwise false.
     * @throws Exception If the underlying `makeRequest` method throws an exception.
     */
    public function deleteGroup(string $group_name): bool
    {
        try {
            // Get group details from the database
            $group = $this->group_db_manager->get_group_by_name($group_name);

            error_log("Unexpected delete group response: " . print_r($group, true) . "\n", 3, dirname(__FILE__) . '/debug.log');


            if (!$group || empty($group['group_id'])) {
                $this->logger->log('Group not found in database', 'error', [
                    'group_name' => $group_name
                ]);
                error_log("Group not found: " . $group_name . "\n", 3, dirname(__FILE__) . '/debug.log');
                return false;
            }

            $group_id = $group['group_id'];

            // Send a DELETE request to the 'groups/{group_id}' endpoint
            $endpoint = 'groups/' . $group_id;
            $response = $this->makeRequest($endpoint, 'DELETE');

            // MailerLite returns 204 No Content on success, so response may be empty
            if ($response === null || $response === [] || (isset($response['status']) && $response['status'] === 204)) {
                return true;
            }

            // Log unexpected response
            error_log("Unexpected delete group response: " . print_r($response, true) . "\n", 3, dirname(__FILE__) . '/debug.log');
            return false;

        } catch (Exception $e) {
            // Log any error during delete
            $this->logger->log('Failed to delete MailerLite group', 'error', [
                'group_name' => $group_name,
                'error_message' => $e->getMessage()
            ]);
            error_log("Error deleting group: " . $e->getMessage() . "\n", 3, dirname(__FILE__) . '/debug.log');
            return false;
        }
    }


    public function getGroups(): array
    {
        $cacheKey = 'mailerlite_groups';
        $cachedGroups = wp_cache_get($cacheKey, self::CACHE_GROUP);

        if ($cachedGroups !== false) {
            debug_to_file([
                'using_cached_groups' => true,
                'count' => count($cachedGroups)
            ], 'ML_GROUPS');
            return $cachedGroups;
        }

        try {
            debug_to_file('Fetching groups from MailerLite API', 'ML_GROUPS');
            $this->waitForRateLimit();

            $groups = [];
            $page = 1;
            $hasMore = true;

            while ($hasMore) {
                $response = $this->makeRequest(
                    "groups?page={$page}&limit={$this->batchSize}",
                    'GET'
                );

                if (!isset($response['data']) || empty($response['data'])) {
                    break;
                }

                foreach ($response['data'] as $group) {
                    $groups[] = [
                        'id' => $group['id'],
                        'name' => $group['name'],
                        'active_count' => $group['active_count'] ?? 0,
                        'unsubscribed_count' => $group['unsubscribed_count'] ?? 0,
                        'created_at' => $group['created_at'],
                        'updated_at' => $group['updated_at']
                    ];

                    debug_to_file([
                        'group_found' => [
                            'id' => $group['id'],
                            'name' => $group['name']
                        ]
                    ], 'ML_GROUPS');
                }

                $page++;
                $hasMore = !empty($response['data']);

                if ($page % 10 === 0) {
                    $this->manageMemory();
                }
            }

            wp_cache_set($cacheKey, $groups, self::CACHE_GROUP, self::CACHE_TTL);

            debug_to_file([
                'groups_fetched' => count($groups)
            ], 'ML_GROUPS');

            return $groups;
        } catch (Exception $e) {
            debug_to_file([
                'groups_fetch_failed' => true,
                'error' => $e->getMessage()
            ], 'ML_GROUPS');
            throw $e;
        }
    }

    /**
     * Get all groups with full subscriber data
     * @return array
     */
    public function getAllGroupsWithSubscribers(): array
    {
        try {
            $allGroups = $this->getGroups();
            $groupData = [];

            foreach ($allGroups as $group) {
                $subscribers = $this->getGroupSubscribers($group['id']);
                $groupData[] = [
                    'group_id' => $group['id'],
                    'name' => $group['name'],
                    'subscribers' => $subscribers,
                    'total_subscribers' => count($subscribers),
                    'active_count' => $group['active_count'] ?? 0,
                    'created_at' => $group['created_at'] ?? null
                ];

                // Rate limiting
                usleep($this->rateLimitDelay * 1000000);
            }

            return $groupData;
        } catch (Exception $e) {
            $this->logger->log('Failed to get groups with subscribers', 'error', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get group details with subscribers
     * @param string $groupId
     * @return array
     */
    public function getGroupDetails(string $groupId): array
    {
        $cacheKey = 'ml_group_' . md5($groupId);
        $cachedResult = wp_cache_get($cacheKey, self::CACHE_GROUP);

        if ($cachedResult !== false) {
            return $cachedResult;
        }

        try {
            $this->waitForRateLimit();

            $response = $this->makeRequest(
                "groups/{$groupId}",
                'GET'
            );

            if (!isset($response['data'])) {
                throw new API_Exception(
                    'Invalid group response format',
                    "groups/{$groupId}",
                    'GET',
                    500
                );
            }

            $groupData = [
                'id' => $response['data']['id'],
                'name' => $response['data']['name'],
                'active_count' => $response['data']['active_count'] ?? 0,
                'subscribers' => $this->getGroupSubscribers($groupId)
            ];

            wp_cache_set($cacheKey, $groupData, self::CACHE_GROUP, self::CACHE_TTL);
            return $groupData;
        } catch (Exception $e) {
            $this->logger->log('Failed to get group details', 'error', [
                'group_id' => $groupId,
                'error' => $e->getMessage()
            ]);
            throw new API_Exception(
                'Failed to get group details: ' . $e->getMessage(),
                "groups/{$groupId}",
                'GET',
                500
            );
        }
    }

    /**
     * Get subscribers for a specific group with pagination
     * @param string $groupId Group identifier
     * @param array $options Pagination options
     * @return array
     */
    public function getGroupSubscribers(string $groupId, array $options = []): array
    {
        $page = $options['page'] ?? 1;
        $limit = $options['limit'] ?? 100;

        try {
            $response = $this->makeRequest(
                "groups/{$groupId}/subscribers?page={$page}&limit={$limit}",
                'GET'
            );

            if (!isset($response['data'])) {
                return [];
            }

            $subscribers = [];
            foreach ($response['data'] as $subscriber) {
                $subscribers[] = [
                    'id' => $subscriber['id'],
                    'email' => $subscriber['email'],
                    'fields' => $subscriber['fields'] ?? [],
                    'status' => $subscriber['status'],
                    'subscribed_at' => $subscriber['subscribed_at'] ?? null,
                    'group_id' => $groupId
                ];
            }

            debug_to_file([
                'group_subscribers_fetched' => [
                    'group_id' => $groupId,
                    'page' => $page,
                    'count' => count($subscribers)
                ]
            ], 'ML_API');

            return $subscribers;
        } catch (Exception $e) {
            $this->logger->log('Failed to get group subscribers', 'error', [
                'group_id' => $groupId,
                'page' => $page,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Normalize subscriber fields
     */
    private function normalizeFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $key => $value) {
            // Handle MailerLite's custom field format
            if (is_array($value) && isset($value['value'])) {
                $normalized[$key] = $value['value'];
            } else {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
    }

    /**
     * Get groups for a specific subscriber
     * @param string $subscriberId
     * @return array
     */
    public function getSubscriberGroups(string $subscriberId): array
    {
        try {
            $cacheKey = 'subscriber_groups_' . md5($subscriberId);
            $cachedResult = wp_cache_get($cacheKey, self::CACHE_GROUP);

            if ($cachedResult !== false) {
                return $cachedResult;
            }

            $this->waitForRateLimit();

            $response = $this->makeRequest(
                "subscribers/{$subscriberId}/groups",
                'GET'
            );

            if (!isset($response['data'])) {
                return [];
            }

            $groups = array_map(function ($group) {
                return [
                    'id' => $group['id'],
                    'name' => $group['name']
                ];
            }, $response['data']);

            wp_cache_set($cacheKey, $groups, self::CACHE_GROUP, self::CACHE_TTL);
            return $groups;
        } catch (Exception $e) {
            $this->logger->log('Failed to get subscriber groups', 'error', [
                'subscriber_id' => $subscriberId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
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

    private function invalidateSubscriberCache($subscriberId): void
    {
        $pattern = 'subscribers_*';
        $this->clearCacheByPattern($pattern);
        $this->logger->log('Subscriber cache invalidated', 'debug', [
            'subscriber_id' => $subscriberId
        ]);
    }

    private function clearCacheByPattern($pattern): void
    {
        wp_cache_delete($pattern, self::CACHE_GROUP);
    }

    /**
     * Abort any pending API requests
     * @return void
     */
    public function abortPendingRequests(): void
    {
        try {
            debug_to_file('Attempting to abort pending MailerLite requests', 'ML_API_DEBUG');

            // Clear any cached requests
            wp_cache_delete('mailerlite_request', self::CACHE_GROUP);

            // Clear rate limit data
            wp_cache_delete(self::RATE_LIMIT_KEY, self::CACHE_GROUP);
            $this->rateLimitRemaining = null;
            $this->rateLimitReset = null;

            // Clear any pending transients
            delete_transient('mailerlite_request_lock');
            delete_transient('mailerlite_api_request');

            // Reset request headers with new request ID
            $this->setHeaders();

            debug_to_file('Successfully aborted pending MailerLite requests', 'ML_API_DEBUG');
        } catch (Exception $e) {
            debug_to_file([
                'error' => 'Failed to abort pending requests',
                'message' => $e->getMessage()
            ], 'ML_API_ERROR');

            if ($this->logger) {
                $this->logger->log('Failed to abort pending requests', 'error', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function manageMemory(): void
    {
        if (memory_get_usage(true) > $this->getMemoryThreshold()) {
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            $this->cache->flush();
        }
    }

    private function getMemoryThreshold(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        preg_match('/^(\d+)(.)$/', $limit, $matches);
        $value = (int) $matches[1];
        switch (strtoupper($matches[2] ?? 'B')) {
            case 'G':
                $value *= 1024;
            case 'M':
                $value *= 1024;
            case 'K':
                $value *= 1024;
        }

        return (int) ($value * 0.8); // 80% of memory limit
    }

    /**
     * Update a subscriber in MailerLite with the provided data.
     *
     * @param string|int $id Subscriber ID
     * @param array $data Update data. Expected format: ['fields' => ['field_name' => 'value']].
     * @return bool Returns true if the update was successful, false otherwise.
     * @throws Exception Throws a custom API_Exception if the call fails unexpectedly.
     */
    public function updateSubscriber($id, $data): bool
    {
        try {
            if (empty($id)) {
                $this->logger->log('Update failed: Missing subscriber ID', 'error');
                return false;
            }

            $fields = $data['fields'] ?? [];
            if (empty($fields)) {
                $this->logger->log('Update failed: No fields provided', 'error', ['id' => $id]);
                return false;
            }

            $updateData = ['fields' => []];
            // Change field characters to lowercase
            foreach ($fields as $field => $value) {
                $updateData['fields'][strtolower($field)] = $value;
            }

            $this->logger->log('Updating subscriber fields', 'debug', [
                'subscriber_id' => $id,
                'fields' => $updateData['fields']
            ]);

            $response = $this->makeRequest("subscribers/{$id}", 'PUT', $updateData);
            $statusCode = $response['status_code'] ?? 0;

            if (
                ($statusCode >= 200 && $statusCode < 300) ||
                ($statusCode === 0 && isset($response['data']['id']) && $response['data']['id'] === $id)
            ) {

                $this->logger->log('Subscriber updated successfully', 'info', ['id' => $id]);
                return true;
            } else {
                $this->logger->log('Update failed: API returned an unexpected response', 'error', [
                    'id' => $id,
                    'status_code' => $statusCode,
                    'response' => $response
                ]);
                return false;
            }

        } catch (Exception $e) {
            $this->logger->log('Failed to update subscriber due to exception', 'error', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }



    /**
     * Convert field name to MailerLite tag format
     * @param string $field
     * @return string
     */
    private function formatFieldToTag(string $field): string
    {
        // Convert uppercase field name to lowercase tag format
        // e.g., "2025_ETB_EOE_PURCHASED" -> "$2025_etb_eoe_purchased"
        return '$' . strtolower($field);
    }

    public function test_connection(): bool
    {
        try {
            if (empty($this->apiKey)) {
                debug_to_file('MailerLite test failed - empty API key', 'API_TEST');
                return false;
            }

            // Clear any cached responses
            wp_cache_delete('mailerlite_test_connection', self::CACHE_GROUP);

            // First try using cURL directly with better error handling
            if (function_exists('curl_init')) {
                debug_to_file('Attempting cURL connection to MailerLite', 'API_TEST');

                $ch = curl_init('https://connect.mailerlite.com/api/subscribers?limit=1');

                $options = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $this->apiKey,
                        'Accept: application/json',
                        'Content-Type: application/json',
                        'X-Request-Id: ' . uniqid('ml_test_', true)
                    ],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
                ];

                curl_setopt_array($ch, $options);

                $response = curl_exec($ch);
                $curl_info = curl_getinfo($ch);
                $curl_error = curl_error($ch);

                debug_to_file([
                    'curl_info' => $curl_info,
                    'curl_error' => $curl_error,
                    'response_code' => $curl_info['http_code']
                ], 'API_TEST');

                curl_close($ch);

                if ($curl_info['http_code'] >= 200 && $curl_info['http_code'] < 300) {
                    return true;
                }
            }

            // Fallback to WordPress HTTP API
            debug_to_file('Falling back to WP HTTP API', 'API_TEST');

            $args = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => uniqid('ml_test_', true)
                ],
                'timeout' => 30,
                'sslverify' => false,
                'reject_unsafe_urls' => false,
                'redirection' => 5,
                'httpversion' => '1.1',
                'body' => null
            ];

            // Try HTTPS first, then HTTP if that fails
            $urls = [
                'https://connect.mailerlite.com/api/subscribers?limit=1',
                'http://connect.mailerlite.com/api/subscribers?limit=1'
            ];

            foreach ($urls as $url) {
                debug_to_file("Trying MailerLite connection with URL: " . $url, 'API_TEST');

                $response = wp_remote_get($url, $args);

                if (!is_wp_error($response)) {
                    $code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);

                    debug_to_file([
                        'url' => $url,
                        'response_code' => $code,
                        'response_body' => substr($body, 0, 500)
                    ], 'API_TEST');

                    if ($code >= 200 && $code < 300) {
                        return true;
                    }

                    // Check if we need to try alternate method
                    if (strpos($body, 'blocked requests through HTTP') !== false) {
                        continue;
                    }
                } else {
                    debug_to_file("WP Remote request failed for URL " . $url . ": " . $response->get_error_message(), 'API_TEST');
                }
            }

            return false;
        } catch (Exception $e) {
            debug_to_file('MailerLite test_connection exception: ' . $e->getMessage(), 'API_TEST');
            debug_to_file('Stack trace: ' . $e->getTraceAsString(), 'API_TEST');
            return false;
        }
    }
}