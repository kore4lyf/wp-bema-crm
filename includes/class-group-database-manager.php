<?php

namespace Bema;

use Exception;
use Bema\BemaCRMLogger;
use wpdb;

/**
 * Group_Database_Manager class.
 *
 * Handles database operations for the 'bemacrm_groupmeta' table.
 *
 */
if (!defined('ABSPATH')) {
    exit;
}

class Group_Database_Manager
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
     * The logger instance.
     *
     * @var BemaCRMLogger
     */
    private BemaCRMLogger $logger;

    /**
     * Group_Database_Manager constructor.
     */
    public function __construct(?BemaCRMLogger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_groupmeta';
        $this->logger = $logger ?? new BemaCRMLogger();
    }

    /**
     * Creates the custom database table.
     *
     * @return bool True on success, false on failure.
     */
    public function create_table()
    {
        try {

            $charset_collate = $this->wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$this->table_name} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                group_name VARCHAR(255) NOT NULL,
                group_id BIGINT UNSIGNED NOT NULL,

                PRIMARY KEY  (id),
                UNIQUE KEY group_name (group_name),
                UNIQUE KEY group_id (group_id)
            ) $charset_collate;";

            $result = dbDelta($sql);

            if (!$result) {
                throw new Exception('Failed to create the database table.');
            }
            return true;
        } catch (Exception $e) {
            $this->logger->log('Group_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Inserts or updates a group in the database based on group_name.
     *
     * @param string $group_name The name of the group.
     * @param int    $group_id   The ID of the group.
     * @return int|false The ID of the affected row on success, or false on failure.
     */
    public function upsert_group(string $group_name, int $group_id)
    {
        try {
            // First, sanitize the input data.
            $sanitized_group_name = sanitize_text_field($group_name);
            $sanitized_group_id = absint($group_id);

            // Check if a record with the same group_name already exists.
            $existing_group = $this->get_group_by_name($sanitized_group_name);

            if ($existing_group) {
                // If it exists, update the group_id.
                $updated = $this->wpdb->update(
                    $this->table_name,
                    [
                        'group_id' => $sanitized_group_id,
                    ],
                    [
                        'group_name' => $sanitized_group_name,
                    ],
                    ['%d'],
                    ['%s']
                );
                
                if (false === $updated) {
                    throw new Exception('Failed to update group: ' . $this->wpdb->last_error);
                }
                
                // Return the affected row ID. Since it's an update, we can return the existing ID.
                return $existing_group['id'];

            } else {
                // If it doesn't exist, insert a new record.
                $inserted = $this->wpdb->insert(
                    $this->table_name,
                    [
                        'group_name' => $sanitized_group_name,
                        'group_id' => $sanitized_group_id,
                    ],
                    ['%s', '%d']
                );

                if (false === $inserted) {
                    throw new Exception('Failed to insert group: ' . $this->wpdb->last_error);
                }
                return $this->wpdb->insert_id;
            }

        } catch (Exception $e) {
            $this->logger->log('Group_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Inserts or updates multiple groups into the database in a single query.
     *
     * This function uses MySQL's "INSERT ... ON DUPLICATE KEY UPDATE" syntax
     * to efficiently handle both inserts and updates based on the unique 'group_name' key.
     *
     * @param array $groups_to_upsert An array of arrays, where each inner array
     * contains 'group_name' and 'group_id'.
     * @return int|false The number of rows affected on success, or false on failure.
     */
    public function upsert_groups_bulk(array $groups_to_upsert)
    {

        if (empty($groups_to_upsert)) {
            return false;
        }

        try {
            $placeholders = [];
            $values = [];
            
            foreach ($groups_to_upsert as $group) {
                // Use a placeholder pair for each row to be inserted.
                $placeholders[] = "(%s, %d)";
                // Add the sanitized values to the values array.
                $values[] = sanitize_text_field($group['group_name']);
                $values[] = absint($group['group_id']);
            }

            // Build the SQL query with placeholders and the ON DUPLICATE KEY UPDATE clause.
            // This clause will update the group_id if the group_name already exists.
            $query = "INSERT INTO {$this->table_name} (group_name, group_id) VALUES " .
                     implode(', ', $placeholders) .
                     " ON DUPLICATE KEY UPDATE group_id = VALUES(group_id)";

            // Prepare the query securely and execute it.
            $result = $this->wpdb->query($this->wpdb->prepare($query, $values));

            if (false === $result) {
                throw new Exception('Failed to bulk upsert groups: ' . $this->wpdb->last_error);
            }

            return $result;
        } catch (Exception $e) {
            echo 'Failed: ' . $e->getMessage();
            $this->logger->log('Group_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Deletes a group from the database by its group name.
     *
     * @param string $group_name The name of the group to delete.
     * @return int|false The number of rows deleted on success, or false on failure.
     */
    public function delete_group_by_name($group_name)
    {
        try {
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['group_name' => sanitize_text_field($group_name)],
                ['%s']
            );

            if (false === $deleted) {
                throw new Exception('Failed to delete group: ' . $this->wpdb->last_error);
            }
            return $deleted;
        } catch (Exception $e) {
            $this->logger->log('Group_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Retrieves a single group by its group name.
     *
     * @param string $group_name The name of the group to retrieve.
     * @return array|null An associative array of the group on success, or null if not found.
     */
    public function get_group_by_name(string $group_name)
    {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE group_name = %s",
                sanitize_text_field($group_name)
            ),
            ARRAY_A
        );
    }

    /**
     * Retrieves a single group by its group ID.
     *
     * @param int $group_id The ID of the group to retrieve.
     * @return array|null An associative array of the group on success, or null if not found.
     */
    public function get_group_by_id(int $group_id)
    {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE group_id = %d",
                absint($group_id)
            ),
            ARRAY_A
        );
    }

    /**
     * Retrieves all groups from the database.
     *
     * @return array An array of associative arrays on success, or an empty array if none found.
     */
    public function get_all_groups()
    {
        return $this->wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
    }

    /**
     * Deletes the custom database table.
     *
     * @return bool True on success, false on failure.
     */
    public function delete_table(): bool
    {
        try {
            $sql = "DROP TABLE IF EXISTS {$this->table_name}";
            $this->wpdb->query($sql);

            // Check if the table was successfully dropped.
            if ($this->wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name) {
                return true;
            }
            
            throw new Exception("Failed to delete the database table: {$this->table_name}");

        } catch (Exception $e) {
            $this->logger->log('Database Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }
}
