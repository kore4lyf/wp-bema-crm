<?php

namespace Bema\Database;

use Exception;
use Bema\BemaCRMLogger;

if (!defined('ABSPATH')) {
    exit;
}

class Transition_Subscribers_Database_Manager
{
    private $table_name;
    private $wpdb;
    private $logger;
    private $transitions_table;
    private $subscribers_table;

    /**
     * Constructor to set up the class properties.
     * @param BemaCRMLogger|null $logger The logger instance.
     */
    public function __construct(?BemaCRMLogger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_transition_subscribersmeta';

        $this->transitions_table = $wpdb->prefix . 'bemacrm_transitionsmeta';
        $this->subscribers_table = $wpdb->prefix . 'bemacrm_subscribersmeta';
        $this->logger = $logger ?? new BemaCRMLogger();
    }

    /**
     * Creates the database table for transition subscribers.
     * @return bool True if the table was created successfully, false otherwise.
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
                transition_id BIGINT UNSIGNED NOT NULL,
                subscriber_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                KEY transition_key (transition_id),
                KEY subscriber_key (subscriber_id),
                CONSTRAINT fk_transition FOREIGN KEY (transition_id) REFERENCES {$this->transitions_table}(id) ON DELETE CASCADE,
                CONSTRAINT fk_subscriber FOREIGN KEY (subscriber_id) REFERENCES {$this->subscribers_table}(id) ON DELETE CASCADE
            ) $charset_collate;";

            dbDelta($sql);

            // Check for errors after dbDelta
            if ($this->wpdb->last_error) {
                throw new Exception($this->wpdb->last_error);
            }

            return true;
        } catch (Exception $e) {
            $this->logger->log('Transition_Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Inserts a new record into the transition subscribers table.
     * @param int $transition_id The ID of the transition record.
     * @param int $subscriber_id The ID of the subscriber record.
     * @return int|false The ID of the newly inserted record, or false on failure.
     */
    public function insert_record(int $transition_id, int $subscriber_id)
    {
        try {
            $record_data = [
                'transition_id' => absint($transition_id),
                'subscriber_id' => absint($subscriber_id),
            ];

            $this->wpdb->insert(
                $this->table_name,
                $record_data,
                ['%d', '%d']
            );

            if ($this->wpdb->last_error) {
                throw new Exception($this->wpdb->last_error);
            }

            return $this->wpdb->insert_id;
        } catch (Exception $e) {
            $this->logger->log('Transition_Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Inserts or updates a record based on a unique combination of transition_id and subscriber_id.
     * @param int $transition_id The ID of the transition record.
     * @param int $subscriber_id The ID of the subscriber record.
     * @return int|false The ID of the inserted/updated record, or false on failure.
     */
    public function upsert_record(int $transition_id, int $subscriber_id)
    {
        try {
            // Check if the record already exists
            $existing_id = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->table_name} WHERE transition_id = %d AND subscriber_id = %d",
                    $transition_id,
                    $subscriber_id
                )
            );

            $record_data = [
                'transition_id' => absint($transition_id),
                'subscriber_id' => absint($subscriber_id),
            ];

            if ($existing_id) {
                // Update the existing record (though there's nothing to update in this case)
                $this->wpdb->update(
                    $this->table_name,
                    $record_data,
                    ['id' => $existing_id],
                    ['%d', '%d'],
                    ['%d']
                );
                return (int) $existing_id;
            } else {
                // Insert a new record
                $this->wpdb->insert(
                    $this->table_name,
                    $record_data,
                    ['%d', '%d']
                );

                if ($this->wpdb->last_error) {
                    throw new Exception($this->wpdb->last_error);
                }
                return $this->wpdb->insert_id;
            }
        } catch (Exception $e) {
            $this->logger->log('Transition_Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Performs a bulk upsert of multiple records.
     * @param array $records An array of records, where each record is an array with 'transition_id' and 'subscriber_id'.
     * @return bool True on success, false on failure.
     */
    public function bulk_upsert(array $records, ?int $transition_id): bool
    {
        try {
            $values = [];
            $placeholders = [];

            $column_names = 'transition_id, subscriber_id';

            foreach ($records as $record) {
                if ((isset($record['transition_id']) || !isempty($transition_id)) && isset($record['subscriber_id'])) {
                    $values[] = !is_empty($transition_id) ? $transition_id : absint($record['transition_id']);
                    $values[] = absint($record['id']);
                    $placeholders[] = "(%d, %d)";
                }
            }

            if (empty($placeholders)) {
                return false;
            }

            $query = "INSERT INTO {$this->table_name} ($column_names) VALUES " . implode(', ', $placeholders) . " ON DUPLICATE KEY UPDATE id=id";
            $sql = $this->wpdb->prepare($query, $values);
            $this->wpdb->query($sql);

            if ($this->wpdb->last_error) {
                throw new Exception("Bulk upsert failed: " . $this->wpdb->last_error);
            }

            return true;
        } catch (Exception $e) {
            $this->logger->log('Transition_Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Fetches all records from the table, joining with related tables for more data.
     * @return array An array of results, or an empty array on failure.
     */
    public function get_all_records()
    {
        $sql = $this->wpdb->prepare("
            SELECT
                t.id,
                t.transition_id,
                t.subscriber_id,
                tm.source,
                tm.destination,
                sm.subscriber_email
            FROM
                {$this->table_name} AS t
            LEFT JOIN
                {$this->transitions_table} AS tm ON t.transition_id = tm.id
            LEFT JOIN
                {$this->subscribers_table} AS sm ON t.subscriber_id = sm.id
            ORDER BY
                t.id DESC
        ");

        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Deletes the database table.
     * @return bool True if the table was deleted, false otherwise.
     */
    public function delete_table(): bool
    {
        try {
            $this->wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
            return $this->wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name;
        } catch (Exception $e) {
            $this->logger->log('Transition_Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }
}
