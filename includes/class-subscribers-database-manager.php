<?php

namespace Bema;

use Exception;
use Bema\BemaCRMLogger;

/**
 * Subscribers_Database_Manager class.
 *
 * Handles database operations for the 'bemacrm_subscribermeta' table.
 *
 */
if (!defined('ABSPATH')) {
    exit;
}

class Subscribers_Database_Manager
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
     * Subscribers_Database_Manager constructor.
     *
     * @param BemaCRMLogger|null $logger An optional logger instance.
     */
    public function __construct(?BemaCRMLogger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_subscribersmeta';
        $this->logger = $logger ?? new BemaCRMLogger();
    }

    /**
     * Creates the custom database table for subscribers.
     *
     * @return bool True on success, false on failure.
     */
    public function create_table()
    {
        try {
            if (!function_exists('dbDelta')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }

            $charset_collate = $this->wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$this->table_name} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                subscriber_id BIGINT UNSIGNED NOT NULL,
                email VARCHAR(255) NOT NULL,
                name VARCHAR(255),
                status ENUM('active', 'unsubscribed', 'unconfirmed', 'bounced', 'junk') NOT NULL DEFAULT 'unconfirmed',

                PRIMARY KEY  (id),
                UNIQUE KEY subscriber_id (subscriber_id),
                UNIQUE KEY email (email)
            ) $charset_collate;";

            $result = dbDelta($sql);

            if (!$result) {
                throw new Exception('Failed to create the database table.');
            }
            return true;
        } catch (Exception $e) {
            $this->logger->log('Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Inserts a new subscriber into the database.
     *
     * @param int $subscriber_id The ID of the subscriber.
     * @param string $email         The email of the subscriber.
     * @param string $name          The name of the subscriber.
     * @param string $status          The status of the subscriber.
     * @return bool The ID of the newly inserted row on success, or false on failure.
     */
    public function insert_subscriber($subscriber_id, $email, $name, $status)
    {
        try {
            $inserted = $this->wpdb->insert(
                $this->table_name,
                [
                    'subscriber_id' => absint($subscriber_id),
                    'email' => sanitize_email($email),
                    'name' => sanitize_text_field($name),
                    'status' => sanitize_text_field($status),
                ],
                ['%d', '%s', '%s', '%s']
            );

            if (false === $inserted) {
                throw new Exception('Failed to insert subscriber: ' . $this->wpdb->last_error);
            }

            return true;
        } catch (Exception $e) {
            $this->logger->log('Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Inserts multiple subscribers into the database in a single query.
     *
     * @param array $subscribers_to_insert An array of arrays, where each inner array
     * contains 'subscriber_id', 'email', and 'name'.
     * @return int|false The number of rows inserted on success, or false on failure.
     */
    public function insert_subscribers_bulk(array $subscribers_to_insert)
    {
        if (empty($subscribers_to_insert)) {
            return false;
        }

        try {
            $placeholders = [];
            $values = [];
            foreach ($subscribers_to_insert as $subscriber) {
                // Use a placeholder pair for each row to be inserted.
                $placeholders[] = "(%d, %s, %s, %s)";
                // Add the sanitized values to the values array.
                $values[] = absint($subscriber['subscriber_id']);
                $values[] = sanitize_email($subscriber['email']);
                $values[] = sanitize_text_field($subscriber['name']);
                $values[] = sanitize_text_field($subscriber['status']);
            }

            // Build the SQL query with the placeholders.
            $query = "INSERT INTO {$this->table_name} (subscriber_id, email, name, status) VALUES " . implode(', ', $placeholders);

            // Prepare the query securely and execute it.
            $inserted = $this->wpdb->query($this->wpdb->prepare($query, $values));

            if (false === $inserted) {
                throw new Exception('Failed to bulk insert subscribers: ' . $this->wpdb->last_error);
            }

            return $inserted;
        } catch (Exception $e) {
            $this->logger->log('Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Updates an existing subscriber by their email.
     *
     * @param string $current_email     The current subscriber email.
     * @param array $new_subscriber_data New subscriber details.
     * @return int|false The number of rows updated on success, or false on failure.
     */
    public function update_subscriber_by_email($current_email, $new_subscriber_data)
    {
        try {
            $data = [];
            $where = ['email' => sanitize_email($current_email)];
            $data_format = [];
            $where_format = ['%s'];

            if ($new_subscriber_data['subscriber_id']) {
                $data['subscriber_id'] = absint($new_subscriber_data['subscriber_id']);
                $data_format[] = '%d';
            }

            if ($new_subscriber_data['email']) {
                $data['email'] = sanitize_email($new_subscriber_data['email']);
                $data_format[] = '%s';
            }

            if ($new_subscriber_data['name']) {
                $data['name'] = sanitize_text_field($new_subscriber_data['name']);
                $data_format[] = '%s';
            }
            
            if ($new_subscriber_data['status']) {
                $data['name'] = sanitize_text_field($new_subscriber_data['status']);
                $data_format[] = '%s';
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
                throw new Exception('Failed to update subscriber: ' . $this->wpdb->last_error);
            }
            return $updated;
        } catch (Exception $e) {
            $this->logger->log('Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Updates an existing subscriber by their email.
     *
     * @param int $subscriber_id     The current subscriber email.
     * @param array $new_subscriber_data New subscriber details.
     * @return int|false The number of rows updated on success, or false on failure.
     */
    public function update_subscriber_by_id($subscriber_id, $new_subscriber_data)
    {
        try {
            $data = [];
            $where = ['id' => absint($subscriber_id)];
            $data_format = [];
            $where_format = ['%d'];

            if ($new_subscriber_data['subscriber_id']) {
                $data['subscriber_id'] = absint($new_subscriber_data['subscriber_id']);
                $data_format[] = '%d';
            }

            if ($new_subscriber_data['email']) {
                $data['email'] = sanitize_email($new_subscriber_data['email']);
                $data_format[] = '%s';
            }

            if ($new_subscriber_data['name']) {
                $data['name'] = sanitize_text_field($new_subscriber_data['name']);
                $data_format[] = '%s';
            }
            
            if ($new_subscriber_data['status']) {
                $data['name'] = sanitize_text_field($new_subscriber_data['status']);
                $data_format[] = '%s';
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
                throw new Exception('Failed to update subscriber: ' . $this->wpdb->last_error);
            }
            return $updated;
        } catch (Exception $e) {
            $this->logger->log('Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Deletes a subscriber from the database by their email.
     *
     * @param string $email The email of the subscriber to delete.
     * @return int|false The number of rows deleted on success, or false on failure.
     */
    public function delete_subscriber_by_email($email)
    {
        try {
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['email' => sanitize_email($email)],
                ['%s']
            );

            if (false === $deleted) {
                throw new Exception('Failed to delete subscriber: ' . $this->wpdb->last_error);
            }
            return $deleted;
        } catch (Exception $e) {
            $this->logger->log('Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Retrieves a single subscriber by their email.
     *
     * @param string $email The email of the subscriber to retrieve.
     * @return array|null An associative array of the subscriber on success, or null if not found.
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
     * Retrieves a single subscriber by their ID.
     *
     * @param int $subscriber_id The ID of the subscriber to retrieve.
     * @return array|null An associative array of the subscriber on success, or null if not found.
     */
    public function get_subscriber_by_id($subscriber_id)
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
     * Retrieves all subscribers from the database.
     *
     * @return array An array of associative arrays on success, or an empty array if none found.
     */
    public function get_all_subscribers()
    {
        return $this->wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
    }



    /**
     * Synchronizes local subscribers with data from an external source.
     *
     * This method orchestrates the full sync process:
     * Inserts or updates all active subscribers from the MailerLite data
     *
     * @param array $mailerlite_subscribers_data The 'data' array from the MailerLite API response.
     * @return array|false An array with keys 'inserted', 'updated', and 'deleted' on success, or false on failure.
     */
    public function sync_subscribers(array $mailerlite_subscribers_data)
    {
        if (empty($mailerlite_subscribers_data)) {
            // If the source data is empty, we don't know what to delete, so we log and exit.
            $this->logger->log('No subscriber data received for sync.', 'notice');
            return false;
        }

        try {
            $updated = $this->insert_or_update_subscribers($mailerlite_subscribers_data);

            if (false === $updated) {
                throw new Exception('Failed to insert or update subscribers during sync.');
            }
            
            return true;
        } catch (Exception $e) {
            $this->logger->log('Subscribers_Database_Manager Sync Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Performs an upsert operation (updates existing or inserts new) on a list of subscribers.
     *
     * @param array $subscribers_to_process The 'data' array from the MailerLite API response.
     * @return bool An array of subscriber IDs that were processed on success, or false on failure.
     */
    private function insert_or_update_subscribers(array $subscribers_to_process):bool
    {
        $new_subscriber_data = [];

        foreach ($subscribers_to_process as $subscriber_data) {
            $new_subscriber_data['email'] = sanitize_email($subscriber_data['email']);
            $new_subscriber_data['name'] = sanitize_text_field($subscriber_data['fields']['name'] . ' ' . $subscriber_data['fields']['last_name']);
            $new_subscriber_data['status'] = sanitize_text_field($subscriber_data['status']);

            $existing_subscriber = $this->get_subscriber_by_id($subscriber_data['id']);

            if ($existing_subscriber) {
                // Subscriber exists, so we update the record.
                $this->update_subscriber_by_id($subscriber_data['id'], $new_subscriber_data );
            } else {
                // Subscriber does not exist, so we insert a new record.
                $this->insert_subscriber($subscriber_data['id'], $subscriber_data['email'], $new_subscriber_data['name'], $subscriber_data['status']);
            }
        }
        return true;
    }
}
