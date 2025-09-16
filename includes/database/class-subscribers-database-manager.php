<?php

namespace Bema\Database;

use Exception;
use Bema\Bema_CRM_Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the database operations for BemaCRM subscribers.
 *
 * This class handles the creation, insertion, retrieval, updating, and deletion of
 * subscriber records in a custom WordPress database table. It also includes methods for
 * bulk operations and syncing.
 *
 * @package Bema\Database
 */
class Subscribers_Database_Manager
{
    /**
     * The name of the database table for subscribers.
     *
     * @var string
     */
    private $table_name;

    /**
     * The WordPress database object.
     *
     * @var \wpdb
     */
    private $wpdb;

    /**
     * The logger instance for logging errors.
     *
     * @var Bema_CRM_Logger
     */
    private $logger;

    /**
     * Subscribers_Database_Manager constructor.
     *
     * Initializes the class by setting up the table name and the WordPress database object.
     *
     * @param Bema_CRM_Logger|null $logger An optional logger instance.
     */
    public function __construct(?Bema_CRM_Logger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_subscribersmeta';
        $this->logger = $logger ?? Bema_CRM_Logger::create('subscribers-db');
    }

    /**
     * Creates the custom database table for subscribers.
     *
     * This function uses the WordPress `dbDelta` function to create or update the table schema.
     *
     * @return bool True on success, false on failure.
     * @throws Exception If the database table creation fails.
     */
    public function create_table(): bool
    {
        try {
            if (!function_exists('dbDelta')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }

            $charset_collate = $this->wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(255) NOT NULL,
            name VARCHAR(255),
            status ENUM('active', 'unsubscribed', 'unconfirmed', 'bounced', 'junk') NOT NULL DEFAULT 'unconfirmed',

            subscribed_at DATETIME NULL,
            unsubscribed_at DATETIME NULL,
            updated_at DATETIME NULL,

            PRIMARY KEY  (id),
            UNIQUE KEY email (email)
            ) $charset_collate;";

            $result = dbDelta($sql);

            if (!$result) {
                throw new Exception('Failed to create the database table.');
            }
            return true;
        } catch (Exception $e) {
            $this->logger->error('Subscribers_Database_Manager Error: ' . $e->getMessage(), [
                'method' => 'create_table',
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Inserts a single subscriber record into the database.
     *
     * @param int         $id              The unique ID of the subscriber.
     * @param string      $email           The subscriber's email address.
     * @param string      $name            The subscriber's name.
     * @param string      $status          The status of the subscriber (e.g., 'active', 'unsubscribed').
     * @param string|null $subscribed_at   The datetime when the subscriber was subscribed.
     * @param string|null $unsubscribed_at The datetime when the subscriber was unsubscribed.
     * @param string|null $updated_at      The datetime when the subscriber was last updated.
     *
     * @return bool True on success, false on failure.
     * @throws Exception If the subscriber insertion fails.
     */
    public function insert_subscriber(
        $id,
        $email,
        $name,
        $status,
        $subscribed_at = null,
        $unsubscribed_at = null,
        $updated_at = null
    ): bool {
        try {
            $data = [
                'id' => absint($id),
                'email' => sanitize_email($email),
                'name' => sanitize_text_field($name),
                'status' => sanitize_text_field($status),
            ];
            $format = ['%d', '%s', '%s', '%s'];

            if ($subscribed_at !== null) {
                $data['subscribed_at'] = $subscribed_at;
                $format[] = '%s';
            }
            if ($unsubscribed_at !== null) {
                $data['unsubscribed_at'] = $unsubscribed_at;
                $format[] = '%s';
            }
            if ($updated_at !== null) {
                $data['updated_at'] = $updated_at;
                $format[] = '%s';
            }

            $inserted = $this->wpdb->insert($this->table_name, $data, $format);

            if (false === $inserted) {
                throw new Exception('Failed to insert subscriber: ' . $this->wpdb->last_error);
            }
            return true;
        } catch (Exception $e) {
            $this->logger->error('Subscribers_Database_Manager Error: ' . $e->getMessage(), [
                'method' => 'insert_subscriber',
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Inserts multiple subscriber records into the database in a single query.
     *
     * @param array $subscribers_to_insert An array of subscriber data arrays.
     *
     * @return int|false The number of affected rows on success, false on failure.
     * @throws Exception If the bulk insertion fails.
     */
    public function insert_subscribers_bulk(array $subscribers_to_insert)
    {
        if (empty($subscribers_to_insert)) {
            return false;
        }

        try {
            $placeholders = [];
            $values = [];
            foreach ($subscribers_to_insert as $s) {
                $placeholders[] = "(%d, %s, %s, %s, NULLIF(%s,''), NULLIF(%s,''), NULLIF(%s,''))";
                $values[] = absint($s['id']);
                $values[] = sanitize_email($s['email']);
                $values[] = sanitize_text_field($s['name'] ?? '');
                $values[] = sanitize_text_field($s['status'] ?? 'unconfirmed');
                $values[] = $s['subscribed_at'] ?? '';
                $values[] = $s['unsubscribed_at'] ?? '';
                $values[] = $s['updated_at'] ?? '';
            }

            $query = "INSERT INTO {$this->table_name}
(id, email, name, status, subscribed_at, unsubscribed_at, updated_at)
VALUES " . implode(', ', $placeholders);

            $inserted = $this->wpdb->query($this->wpdb->prepare($query, $values));

            if (false === $inserted) {
                throw new Exception('Failed to bulk insert subscribers: ' . $this->wpdb->last_error);
            }
            return $inserted;
        } catch (Exception $e) {
            $this->logger->error('Subscribers_Database_Manager Error: ' . $e->getMessage(), [
                'method' => 'bulk_insert_subscribers',
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Updates a subscriber record based on their email address.
     *
     * @param string $current_email The email address to identify the subscriber.
     * @param array  $new           An associative array of new data for the subscriber.
     *
     * @return int|false The number of rows updated on success, false on failure.
     * @throws Exception If the update operation fails.
     */
    public function update_subscriber_by_email($current_email, $new)
    {
        try {
            $data = [];
            $fmt = [];
            $where = ['email' => sanitize_email($current_email)];
            $where_fmt = ['%s'];

            if (isset($new['id'])) {
                $data['id'] = absint($new['id']);
                $fmt[] = '%d';
            }
            if (isset($new['email'])) {
                $data['email'] = sanitize_email($new['email']);
                $fmt[] = '%s';
            }
            if (isset($new['name'])) {
                $data['name'] = sanitize_text_field($new['name']);
                $fmt[] = '%s';
            }
            if (isset($new['status'])) {
                $data['status'] = sanitize_text_field($new['status']);
                $fmt[] = '%s';
            }

            if (array_key_exists('subscribed_at', $new)) {
                $data['subscribed_at'] = $new['subscribed_at'];
                $fmt[] = '%s';
            }
            if (array_key_exists('unsubscribed_at', $new)) {
                $data['unsubscribed_at'] = $new['unsubscribed_at'];
                $fmt[] = '%s';
            }
            if (array_key_exists('updated_at', $new)) {
                $data['updated_at'] = $new['updated_at'];
                $fmt[] = '%s';
            }

            if (empty($data)) {
                return 0;
            }

            $updated = $this->wpdb->update($this->table_name, $data, $where, $fmt, $where_fmt);

            if (false === $updated) {
                throw new Exception('Failed to update subscriber: ' . $this->wpdb->last_error);
            }
            return $updated;
        } catch (Exception $e) {
            $this->logger->error('Subscribers_Database_Manager Error: ' . $e->getMessage(), [
                'method' => 'update_subscriber_by_email',
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Updates a subscriber record based on their ID.
     *
     * @param int   $id  The ID of the subscriber to update.
     * @param array $new An associative array of new data for the subscriber.
     *
     * @return int|false The number of rows updated on success, false on failure.
     * @throws Exception If the update operation fails.
     */
    public function update_subscriber_by_id($id, $new)
    {
        try {
            $data = [];
            $fmt = [];
            $where = ['id' => absint($id)];
            $where_fmt = ['%d'];

            if (isset($new['email'])) {
                $data['email'] = sanitize_email($new['email']);
                $fmt[] = '%s';
            }
            if (isset($new['name'])) {
                $data['name'] = sanitize_text_field($new['name']);
                $fmt[] = '%s';
            }
            if (isset($new['status'])) {
                $data['status'] = sanitize_text_field($new['status']);
                $fmt[] = '%s';
            }

            if (array_key_exists('subscribed_at', $new)) {
                $data['subscribed_at'] = $new['subscribed_at'];
                $fmt[] = '%s';
            }
            if (array_key_exists('unsubscribed_at', $new)) {
                $data['unsubscribed_at'] = $new['unsubscribed_at'];
                $fmt[] = '%s';
            }
            if (array_key_exists('updated_at', $new)) {
                $data['updated_at'] = $new['updated_at'];
                $fmt[] = '%s';
            }

            if (empty($data)) {
                return 0;
            }

            $updated = $this->wpdb->update($this->table_name, $data, $where, $fmt, $where_fmt);

            if (false === $updated) {
                throw new Exception('Failed to update subscriber: ' . $this->wpdb->last_error);
            }
            return $updated;
        } catch (Exception $e) {
            $this->logger->error('Subscribers_Database_Manager Error: ' . $e->getMessage(), [
                'method' => 'update_subscriber_by_id',
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Deletes a subscriber record by their email address.
     *
     * This method deletes a subscriber from the main subscribers table.
     * Due to the foreign key with ON DELETE CASCADE, all associated
     * campaign subscriber records in the `bemacrm_campaign_subscribersmeta` table
     * will also be automatically deleted to maintain data integrity.
     *
     * @param string $email The email address of the subscriber to delete.
     *
     * @return int|false The number of rows deleted on success, or false on failure.
     * @throws Exception If the deletion operation fails.
     */
    public function delete_subscriber_by_email($email)
    {
        try {
            // Use wpdb->delete() to safely remove the subscriber record.
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['email' => sanitize_email($email)],
                ['%s']
            );

            // Check if the deletion failed.
            if (false === $deleted) {
                throw new Exception('Failed to delete subscriber: ' . $this->wpdb->last_error);
            }

            // Return the number of rows that were deleted.
            return $deleted;
        } catch (Exception $e) {
            // Log the error and return false on failure.
            $this->logger->error('Subscribers_Database_Manager Error: ' . $e->getMessage(), [
                'method' => 'delete_subscriber_by_email',
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Retrieves a single subscriber record by their email address.
     *
     * @param string $email The email address to search for.
     *
     * @return array|null The subscriber data as an associative array, or null if not found.
     */
    public function get_subscriber_by_email($email)
    {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE email = %s",
                sanitize_email($email)
            ),
            ARRAY_A
        );
    }

    /**
     * Retrieves a single subscriber record by their ID.
     *
     * @param int $id The ID to search for.
     *
     * @return array|null The subscriber data as an associative array, or null if not found.
     */
    public function get_subscriber_by_id($id)
    {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                absint($id)
            ),
            ARRAY_A
        );
    }

    /**
     * Retrieves all subscriber records from the database.
     *
     * @return array An array of all subscriber data.
     */
    public function get_all_subscribers(): array
    {
        return $this->wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
    }

    /**
     * Retrieves a paginated list of subscribers with optional filtering.
     *
     * This method joins the subscribers, campaign subscribers, and campaigns tables
     * to allow filtering by campaign name and tier. It supports pagination and
     * searching by email address.
     *
     * @param int    $per_page      The number of subscribers to retrieve per page.
     * @param int    $offset        The offset for the pagination.
     * @param string $campaign_name Optional. Filters by campaign name.
     * @param string $tier          Optional. Filters by campaign tier.
     * @param string $search        Optional. Searches by email.
     *
     * @return array An array of subscriber records with associated campaign data.
     */
    public function get_subscribers(
        int $per_page = 25,
        int $offset = 0,
        string $campaign_name = '',
        string $tier = '',
        string $search = ''
    ): array {
        $tier = trim($tier);
        $campaign_name = trim($campaign_name);
        // Start with the base SELECT and FROM clauses.
        $sql_select = "SELECT " . (empty($campaign_name) ? "s.*" : "s.*, MAX(c.tier) as tier, MAX(c.purchase_id) as purchase_id, MAX(t.campaign) as campaign, MAX(tm.transition_date) as transition_date");
        $sql_from = "FROM {$this->table_name} AS s";

        // Initialize WHERE clause conditions and parameters for prepared statements.
        $where = [];
        $params = [];


        // Conditionally add INNER JOINs to access campaign and tier data.
        // Joins are only added if a campaign or tier filter is present,
        // which optimizes the query for non-filtered calls.
        if ($campaign_name || $tier) {
            $sql_from .= " INNER JOIN {$this->wpdb->prefix}bemacrm_campaign_subscribersmeta AS c ON s.id = c.subscriber_id";
            $sql_from .= " INNER JOIN {$this->wpdb->prefix}bemacrm_campaignsmeta AS t ON c.campaign_id = t.id";
            
            // Add LEFT JOIN with transition_subscribersmeta table
            $sql_from .= " LEFT JOIN {$this->wpdb->prefix}bemacrm_transition_subscribersmeta AS ts ON s.id = ts.subscriber_id";
            $sql_from .= " LEFT JOIN {$this->wpdb->prefix}bemacrm_transitionsmeta AS tm ON ts.transition_id = tm.id AND tm.destination = c.campaign_id";
        } 

        // Build the WHERE clause based on the provided filters.
        if ($campaign_name) {
            // Add condition for filtering by campaign name.
            $where[] = "t.campaign = %s";
            $params[] = $campaign_name;
        }

        if ($tier) {
            // Add condition for filtering by campaign tier.
            $where[] = "c.tier = %s";
            $params[] = $tier;
        }

        if ($search) {
            // Add condition for searching by email using LIKE.
            $where[] = "s.email LIKE %s";
            $params[] = '%' . $this->wpdb->esc_like($search) . '%';
        }

        // Construct the full WHERE clause string.
        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        
        $group_by_sql = ($campaign_name || $tier) ? "GROUP BY s.id" : "";

        // Combine all parameters for the prepared statement.
        $final_params = array_merge($params, [$per_page, $offset]);

        // Prepare the final SQL query string.
        $sql = $this->wpdb->prepare(
            "{$sql_select}
         {$sql_from}
         {$where_sql}
         {$group_by_sql}
         ORDER BY s.id DESC
         LIMIT %d OFFSET %d",
            ...$final_params
        );

        // Execute the query and return the results as an associative array.
        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Counts the total number of subscribers with optional filtering.
     *
     * @param string $campaign_name Optional. Filters by campaign name.
     * @param string $tier          Optional. Filters by campaign tier.
     * @param string $search        Optional. Searches by email.
     *
     * @return int The total number of subscribers that match the criteria.
     */
    public function count_subscribers(
        string $campaign_name = '',
        string $tier = '',
        string $search = ''
    ): int {
        $where = [];
        $params = [];

        if ($campaign_name) {
            $where[] = "c.campaign_id = %s";
            $campaign_id = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->wpdb->prefix}bemacrm_campaignsmeta WHERE campaign = %s",
                    $campaign_name
                )
            );
            $params[] = $campaign_id;
        }

        if ($tier) {
            $where[] = "c.tier = %s";
            $params[] = $tier;
        }

        if ($search) {
            $where[] = "s.email LIKE %s";
            $params[] = '%' . $this->wpdb->esc_like($search) . '%';
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        if ($campaign_name) {
            $sql = $this->wpdb->prepare(
                "SELECT COUNT(*)
             FROM {$this->table_name} AS s
             INNER JOIN {$this->wpdb->prefix}bemacrm_campaign_subscribersmeta AS c
               ON s.id = c.subscriber_id
             {$where_sql}",
                ...$params
            );
        } else {
            $sql = $this->wpdb->prepare(
                "SELECT COUNT(*)
             FROM {$this->table_name} AS s
             {$where_sql}",
                ...$params
            );
        }

        return (int) $this->wpdb->get_var($sql);
    }

    /**
     * Syncs subscriber data from an external source (e.g., MailerLite) with the database.
     *
     * @param array $mailerlite_subscribers_data An array of subscriber data to be synced.
     *
     * @return bool True on successful sync, false on failure.
     * @throws Exception If the insert or update operation fails.
     */
    public function sync_subscribers(array $mailerlite_subscribers_data): bool
    {
        if (empty($mailerlite_subscribers_data)) {
            $this->logger->notice('No subscriber data received for sync.', [
                'method' => 'sync_subscribers'
            ]);
            return false;
        }

        try {
            $affected = $this->upsert_subscribers($mailerlite_subscribers_data);
            if (false === $affected) {
                throw new Exception('Failed to insert or update subscribers during sync.');
            }
            return true;
        } catch (Exception $e) {
            $this->logger->error('Subscribers_Database_Manager Sync Error: ' . $e->getMessage(), [
                'method' => 'sync_subscribers',
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Inserts or updates multiple subscriber records in a single query.
     *
     * This private method handles the "upsert" logic using `ON DUPLICATE KEY UPDATE`.
     *
     * @param array $subscribers_to_process An array of subscriber data to process.
     *
     * @return int|false The number of affected rows on success, false on failure.
     * @throws Exception If the upsert operation fails.
     */
    private function upsert_subscribers(array $subscribers_to_process)
    {
        if (empty($subscribers_to_process)) {
            return false;
        }

        try {
            $placeholders = [];
            $values = [];

            foreach ($subscribers_to_process as $s) {
                $id = absint($s['id']);
                $email = sanitize_email($s['email']);
                $name = sanitize_text_field(trim(($s['fields']['name'] ?? '') . ' ' . ($s['fields']['last_name'] ?? '')));
                $status = sanitize_text_field($s['status'] ?? 'unconfirmed');

                $subscribed_at = $s['subscribed_at'] ?? '';
                $unsubscribed_at = $s['unsubscribed_at'] ?? '';
                $updated_at = $s['updated_at'] ?? '';

                $placeholders[] = "(%d, %s, %s, %s, NULLIF(%s,''), NULLIF(%s,''), NULLIF(%s,''))";
                $values[] = $id;
                $values[] = $email;
                $values[] = $name;
                $values[] = $status;
                $values[] = $subscribed_at;
                $values[] = $unsubscribed_at;
                $values[] = $updated_at;
            }

            $query = "INSERT INTO {$this->table_name}
             (id, email, name, status, subscribed_at, unsubscribed_at, updated_at)
             VALUES " . implode(', ', $placeholders) . "
             ON DUPLICATE KEY UPDATE
             email  = VALUES(email),
             name = VALUES(name),
             status = VALUES(status),
             subscribed_at  = VALUES(subscribed_at),
             unsubscribed_at = VALUES(unsubscribed_at),
             updated_at = VALUES(updated_at)";

            $result = $this->wpdb->query($this->wpdb->prepare($query, $values));

            if (false === $result) {
                throw new Exception('Failed to bulk upsert subscribers: ' . $this->wpdb->last_error);
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Subscriber_Database_Manager Error: ' . $e->getMessage(), [
                'method' => 'upsert_subscribers',
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Deletes the subscribers database table.
     *
     * This function should be used with caution, as it will permanently remove the table and all its data.
     *
     * @return bool True on success, false on failure.
     * @throws Exception If the database table deletion fails.
     */
    public function delete_table(): bool
    {
        try {
            $sql = "DROP TABLE IF EXISTS {$this->table_name}";
            $this->wpdb->query($sql);

            if ($this->wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name) {
                return true;
            }
            throw new Exception("Failed to delete the database table: {$this->table_name}");
        } catch (Exception $e) {
            $this->logger->error('Database Manager Error: ' . $e->getMessage(), [
                'method' => 'delete_table',
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
}
