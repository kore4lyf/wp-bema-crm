<?php

namespace Bema;

use Exception;
use Bema\BemaCRMLogger;

/**
 * Campaign_subscriber_Database_Manager class.
 *
 * Handles database operations for the 'bemacrm_campaign_subscribers' table.
 *
 */
if (!defined('ABSPATH')) {
    exit;
}

class Campaign_subscribers_Database_Manager
{

    /**
     * The database table name.
     *
     * @var string
     */
    private $table_name;

    /**
     * The WordPress database object.
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * The BemaCRMLogger instance.
     *
     * @var BemaCRMLogger
     */
    private $logger;

    /**
     * Campaign_subscriber_Database_Manager constructor.
     *
     * @param string $table_name The name of the database table to manage.
     * @param BemaCRMLogger|null $logger     An optional logger instance.
     */
    public function __construct(string $table_name, ?BemaCRMLogger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_' . $table_name;
        $this->logger = $logger ?? new BemaCRMLogger();
    }

    /**
     * Creates the custom database table.
     *  
     * @return bool True on success, false on failure.
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
                tier VARCHAR(255) NOT NULL,
                purchase_id BIGINT UNSIGNED NULL,
                
                PRIMARY KEY (id),
                UNIQUE KEY subscriber_id (subscriber_id)
            ) $charset_collate;";

            $result = dbDelta($sql);

            if (!$result) {
                throw new Exception('Failed to create the database table.');
            }
            return true;
        } catch (Exception $e) {
            $this->logger->log("Database Manager Error: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Inserts a new campaign subscriber entry into the database.
     *
     * @param int $subscriber_id The ID of the subscriber.
     * @param string $tier The campaign tier.
     * @param int|null $purchase_id The purchase ID, if applicable.
     * @return int|false The ID of the newly inserted row on success, or false on failure.
     */
    public function insert_campaign_subscriber(int $subscriber_id, string $tier, ?int $purchase_id = null): int|false
    {
        try {
            $data = [
                'subscriber_id' => absint($subscriber_id),
                'tier'          => sanitize_text_field($tier),
                'purchase_id'   => !is_null($purchase_id) ? absint($purchase_id) : null,
            ];
            $format = ['%d', '%s', '%d'];

            $inserted = $this->wpdb->insert(
                $this->table_name,
                $data,
                $format
            );

            if (false === $inserted) {
                throw new Exception('Failed to insert campaign subscriber: ' . $this->wpdb->last_error);
            }

            return $this->wpdb->insert_id;
        } catch (Exception $e) {
            $this->logger->log('Campaign_subscriber_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Retrieves a single campaign subscriber entry by subscriber ID.
     *
     * @param int $subscriber_id The ID of the subscriber to retrieve.
     * @return array|null An associative array of the campaign subscriber on success, or null if not found.
     */
    public function get_campaign_subscriber_by_id(int $subscriber_id): ?array
    {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE subscriber_id = %d",
                absint($subscriber_id)
            ),
            ARRAY_A
        );
    }
    
    /**
     * Updates an existing campaign subscriber entry by subscriber ID.
     *
     * @param int   $subscriber_id The ID of the subscriber to update.
     * @param array $new_campaign_subscriber_data New campaign subscriber details.
     * @return int|false The number of rows updated on success, or false on failure.
     */
    public function update_campaign_subscriber_by_id(int $subscriber_id, array $new_campaign_subscriber_data): int|false
    {
        try {
            $data = [];
            $where = ['subscriber_id' => absint($subscriber_id)];
            $data_format = [];
            $where_format = ['%d'];
            
            if (isset($new_campaign_subscriber_data['tier'])) {
                $data['tier'] = sanitize_text_field($new_campaign_subscriber_data['tier']);
                $data_format[] = '%s';
            }

            if (isset($new_campaign_subscriber_data['purchase_id'])) {
                $data['purchase_id'] = absint($new_campaign_subscriber_data['purchase_id']);
                $data_format[] = '%d';
            }

            if (empty($data)) {
                return 0; // Nothing to update.
            }

            $updated = $this->wpdb->update(
                $this->table_name,
                $data,
                $where,
                $data_format,
                $where_format
            );

            if (false === $updated) {
                throw new Exception('Failed to update campaign subscriber: ' . $this->wpdb->last_error);
            }
            return $updated;
        } catch (Exception $e) {
            $this->logger->log('Campaign_subscriber_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Deletes a campaign subscriber entry from the database by subscriber ID.
     *
     * @param int $subscriber_id The ID of the subscriber to delete.
     * @return int|false The number of rows deleted on success, or false on failure.
     */
    public function delete_campaign_subscriber_by_id(int $subscriber_id): int|false
    {
        try {
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['subscriber_id' => absint($subscriber_id)],
                ['%d']
            );

            if (false === $deleted) {
                throw new Exception('Failed to delete campaign subscriber: ' . $this->wpdb->last_error);
            }
            return $deleted;
        } catch (Exception $e) {
            $this->logger->log('Campaign_subscriber_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Retrieves all campaign subscribers data from the database.
     *
     * @return array An array of associative arrays on success, or an empty array if none found.
     */
    public function get_all_subscribers()
    {
        return $this->wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
    }
}