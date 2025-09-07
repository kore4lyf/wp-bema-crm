<?php

namespace Bema\Database;

use Exception;
use Bema\Bema_CRM_Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the database table for campaign subscribers.
 *
 * This class handles all CRUD (Create, Read, Update, Delete) operations
 * for the `bemacrm_campaign_subscribersmeta` table in a WordPress environment.
 * It ensures data integrity and security by using `dbDelta` for table creation
 * and `wpdb` prepared statements for data manipulation.
 *
 * @package Bema\Database
 * @since 1.0.0
 */
class Campaign_Group_Subscribers_Database_Manager
{
    /**
     * The main table name for campaign subscriber data.
     *
     * @var string
     */
    private $table_name;

    /**
     * The table name for campaign data.
     *
     * @var string
     */
    private $campaigns_table_name;

    /**
     * The table name for subscriber data.
     *
     * @var string
     */
    private $subscribers_table_name;

    /**
     * The table name for group data.
     *
     * @var string
     */
    private $groups_table_name;

    /**
     * The WordPress database object.
     *
     * @var \wpdb
     */
    private $wpdb;

    /**
     * The logger instance for error reporting.
     *
     * @var Bema_CRM_Logger
     */
    private $logger;

    /**
     * Campaign_Group_Subscribers_Database_Manager constructor.
     *
     * Initializes the database connection and table names.
     *
     * @param Bema_CRM_Logger|null $logger An optional logger instance.
     */
    public function __construct(?Bema_CRM_Logger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_campaign_subscribersmeta';
        $this->campaigns_table_name = $wpdb->prefix . 'bemacrm_campaignsmeta';
        $this->subscribers_table_name = $wpdb->prefix . 'bemacrm_subscribersmeta';
        $this->groups_table_name = $wpdb->prefix . 'bemacrm_groupmeta';
        if ($logger) {
            $this->logger = $logger;
            $this->logger->setIdentifier('campaign-group-subscribers-database');
        } else {
            $this->logger = Bema_CRM_Logger::create('campaign-group-subscribers-database');
        }
    }

    /**
     * Creates the database table for campaign subscribers.
     *
     * This method uses `dbDelta` to safely create or update the table schema.
     * It includes foreign key constraints to ensure data integrity.
     *
     * @return bool True if the table was created or updated successfully, false otherwise.
     */
    public function create_table(): bool
    {
        try {
            if (!function_exists('dbDelta')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }

            $charset_collate = $this->wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscriber_id BIGINT UNSIGNED NOT NULL,
            group_id BIGINT UNSIGNED NOT NULL,
            campaign_id BIGINT UNSIGNED NOT NULL,
            tier VARCHAR(255) NOT NULL,
            purchase_id BIGINT UNSIGNED NULL,

            PRIMARY KEY (id),
            UNIQUE KEY sub_campaign (subscriber_id, campaign_id),
            FOREIGN KEY (subscriber_id) REFERENCES {$this->subscribers_table_name}(id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES {$this->groups_table_name}(id) ON DELETE CASCADE,
            FOREIGN KEY (campaign_id) REFERENCES {$this->campaigns_table_name}(id) ON DELETE CASCADE
            ) $charset_collate;";

            dbDelta($sql);

            return true;
        } catch (Exception $e) {
            $this->logger->log("Database Manager Error: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Inserts a new campaign subscriber record.
     *
     * @param int      $subscriber_id The ID of the subscriber.
     * @param int      $group_id      The ID of the group the subscriber belongs to.
     * @param int      $campaign_id   The ID of the campaign.
     * @param string   $tier          The tier of the subscriber within the campaign.
     * @param int|null $purchase_id   Optional ID of a related purchase.
     *
     * @return int|false The ID of the inserted row on success, or false on failure.
     */
    public function insert_campaign_subscriber(int $subscriber_id, int $group_id, int $campaign_id, string $tier, ?int $purchase_id = null): int|false
    {
        try {
            $data = [
                'subscriber_id' => absint($subscriber_id),
                'group_id' => absint($group_id),
                'campaign_id' => absint($campaign_id),
                'tier' => sanitize_text_field($tier),
            ];
            $format = ['%d', '%d', '%d', '%s'];

            if (!is_null($purchase_id)) {
                $data['purchase_id'] = absint($purchase_id);
                $format[] = '%d';
            }

            $inserted = $this->wpdb->insert($this->table_name, $data, $format);

            if (false === $inserted) {
                throw new Exception('Failed to insert campaign subscriber: ' . $this->wpdb->last_error);
            }

            return $this->wpdb->insert_id;
        } catch (Exception $e) {
            $this->logger->log('insert_campaign_subscriber Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Retrieves a single campaign subscriber record.
     *
     * @param int $subscriber_id The ID of the subscriber.
     * @param int $campaign_id   The ID of the campaign.
     *
     * @return array|null An associative array of the record on success, or null if not found.
     */
    public function get_campaign_subscriber(int $subscriber_id, int $campaign_id): ?array
    {
        $query = $this->wpdb->prepare(
            "SELECT T1.*, T2.campaign_name FROM {$this->table_name} AS T1
             LEFT JOIN {$this->campaigns_table_name} AS T2 ON T1.campaign_id = T2.id
             WHERE T1.subscriber_id = %d AND T1.campaign_id = %d LIMIT 1",
            $subscriber_id,
            $campaign_id
        );

        $result = $this->wpdb->get_row($query, ARRAY_A);
        return $result ?: null;
    }

    /**
     * Retrieves all campaigns associated with a specific subscriber.
     *
     * @param int $subscriber_id The ID of the subscriber.
     *
     * @return array An array of associative arrays, one for each campaign record. Returns an empty array if no records are found.
     */
    public function get_all_subscriber_campaigns(int $subscriber_id): array
    {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT T1.*, T2.campaign_name FROM {$this->table_name} AS T1
                 LEFT JOIN {$this->campaigns_table_name} AS T2 ON T1.campaign_id = T2.id
                 WHERE T1.subscriber_id = %d",
                $subscriber_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Updates an existing campaign subscriber record.
     *
     * @param int   $subscriber_id The ID of the subscriber to update.
     * @param int   $campaign_id   The ID of the campaign to update.
     * @param array $data          An associative array of data to update (e.g., ['tier' => 'New Tier']).
     *
     * @return bool True if the record was updated, false on failure or if no changes were made.
     */
    public function update_campaign_subscriber(int $subscriber_id, int $campaign_id, array $data): bool
    {
        try {
            if (empty($data)) {
                throw new Exception('No data provided for update.');
            }

            $update_data = [];
            $format = [];

            foreach ($data as $key => $value) {
                switch ($key) {
                    case 'group_id':
                        $update_data['group_id'] = absint($value);
                        $format[] = '%d';
                        break;
                    case 'tier':
                        $update_data['tier'] = sanitize_text_field($value);
                        $format[] = '%s';
                        break;
                    case 'purchase_id':
                        $update_data['purchase_id'] = is_null($value) ? null : absint($value);
                        $format[] = '%d';
                        break;
                }
            }

            if (empty($update_data)) {
                throw new Exception('No valid fields provided for update.');
            }

            $updated = $this->wpdb->update(
                $this->table_name,
                $update_data,
                ['subscriber_id' => absint($subscriber_id), 'campaign_id' => absint($campaign_id)],
                $format,
                ['%d', '%d']
            );

            if (false === $updated) {
                throw new Exception('Failed to update campaign subscriber: ' . $this->wpdb->last_error);
            }

            return $updated > 0;
        } catch (Exception $e) {
            $this->logger->log('update_campaign_subscriber Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Inserts a new campaign subscriber record or updates an existing one if a unique key is found.
     *
     * @param array $data An associative array of data including 'subscriber_id', 'campaign_id', and other fields to insert/update.
     *
     * @return bool|int True on successful update, the new row's ID on successful insert, or false on failure.
     */
    public function upsert_campaign_subscriber(array $data): bool|int
    {
        try {
            if (empty($data['subscriber_id']) || empty($data['campaign_id'])) {
                throw new Exception('subscriber_id and campaign_id are required for upsert.');
            }

            $subscriber_id = (int) $data['subscriber_id'];
            $campaign_id = (int) $data['campaign_id'];
            $group_id = (int) $data['group_id'];
            $tier = sanitize_text_field($data['tier'] ?? '');
            $purchase_id = isset($data['purchase_id']) ? (int) $data['purchase_id'] : null;

            $existing = $this->get_campaign_subscriber($subscriber_id, $campaign_id);

            if ($existing) {
                return $this->update_campaign_subscriber($subscriber_id, $campaign_id, [
                    'group_id' => $group_id,
                    'tier' => $tier,
                    'purchase_id' => $purchase_id
                ]);
            } else {
                return $this->insert_campaign_subscriber($subscriber_id, $group_id, $campaign_id, $tier, $purchase_id);
            }
        } catch (Exception $e) {
            $this->logger->log('upsert_campaign_subscriber Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
 * Inserts or updates multiple campaign subscriber records in a single bulk operation.
 *
 * This method uses a single `INSERT...ON DUPLICATE KEY UPDATE` query for efficiency.
 *
 * @param array $data An array of associative arrays, where each inner array represents a record to upsert.
 *
 * @return bool True on success, false on failure.
 */
public function upsert_campaign_subscribers_bulk(array $data): bool
{
    try {
        if (empty($data)) {
            return true;
        }

        $values_placeholders = [];
        $query_values = [];
        $valid_records_count = 0;

        foreach ($data as $index => $record) {
            if (empty($record['subscriber_id']) || empty($record['campaign_id']) || empty($record['group_id'])) {
                continue;
            }

            $values_placeholders[] = '(%d, %d, %d, %s, %d)';
            $query_values[] = absint($record['subscriber_id']);
            $query_values[] = absint($record['group_id']);
            $query_values[] = absint($record['campaign_id']);
            $query_values[] = sanitize_text_field($record['tier'] ?? '');
            $query_values[] = isset($record['purchase_id']) ? absint($record['purchase_id']) : 0;
            $valid_records_count++;
        }

        if (empty($values_placeholders)) {
            return false;
        }

        $sql = "INSERT INTO {$this->table_name} (subscriber_id, group_id, campaign_id, tier, purchase_id) VALUES " . implode(', ', $values_placeholders) . " ON DUPLICATE KEY UPDATE group_id = VALUES(group_id), tier = VALUES(tier), purchase_id = VALUES(purchase_id)";

        $prepared_query = $this->wpdb->prepare($sql, $query_values);
        $result = $this->wpdb->query($prepared_query);

        if (false === $result) {
            $error_message = $this->wpdb->last_error;
            throw new Exception('Failed to bulk upsert campaign subscribers: ' . $error_message);
        }

        return true;

    } catch (Exception $e) {
        $this->logger->log('upsert_campaign_subscribers_bulk Error: ' . $e->getMessage(), 'error');
        return false;
    }
}

    /**
     * Deletes a campaign subscriber record.
     *
     * @param int $subscriber_id The ID of the subscriber to delete.
     * @param int $campaign_id   The ID of the campaign to delete.
     *
     * @return int|false The number of deleted rows on success, or false on failure.
     */
    public function delete_campaign_subscriber(int $subscriber_id, int $campaign_id): int|false
    {
        try {
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['subscriber_id' => absint($subscriber_id), 'campaign_id' => absint($campaign_id)],
                ['%d', '%d']
            );

            if (false === $deleted) {
                throw new Exception('Failed to delete campaign subscriber: ' . $this->wpdb->last_error);
            }

            return $deleted;
        } catch (Exception $e) {
            $this->logger->log('delete_campaign_subscriber Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Retrieves all records from the campaign subscribers table.
     *
     * This method joins with the campaigns table to include the campaign name in the results.
     *
     * @return array An array of all campaign subscriber records with campaign names. Returns an empty array if no records are found.
     */
    public function get_all_records(): array
    {
        $query = $this->wpdb->prepare("SELECT T1.*, T2.campaign_name FROM {$this->table_name} AS T1 LEFT JOIN {$this->campaigns_table_name} AS T2 ON T1.campaign_id = T2.id");
        $results = $this->wpdb->get_results($query, ARRAY_A);
        return $results ?: [];
    }

    /**
     * Deletes the entire campaign subscribers database table.
     *
     * This method is intended for plugin deactivation or uninstallation.
     *
     * @return bool True if the table was successfully dropped, false otherwise.
     */
    public function delete_table(): bool
    {
        try {
            $sql = "DROP TABLE IF EXISTS {$this->table_name}";
            $this->wpdb->query($sql);

            if ($this->wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name) {
                throw new Exception("Failed to delete the database table: {$this->table_name}");
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error('delete_table Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
}