<?php

namespace Bema;

use Exception;
use Bema\BemaCRMLogger;

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
            if (!function_exists('dbDelta')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }

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
     * Inserts a new group into the database.
     *
     * @param string $group_name The name of the group.
     * @param int    $group_id The ID of the group.
     * @return int|false The ID of the newly inserted group on success, or false on failure.
     */
    public function insert_group($group_name, $group_id)
    {
        try {
            $inserted = $this->wpdb->insert(
                $this->table_name,
                [
                    'group_name' => sanitize_text_field($group_name),
                    'group_id' => absint($group_id)
                ],
                ['%s', '%d']
            );

            if (false === $inserted) {
                throw new Exception('Failed to insert group: ' . $this->wpdb->last_error);
            }
            return $this->wpdb->insert_id;
        } catch (Exception $e) {
            $this->logger->log('Group_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Inserts multiple groups into the database in a single query.
     *
     * @param array $groups_to_insert An array of arrays, where each inner array
     * contains 'group_name' and 'group_id'.
     * @return int|false The number of rows inserted on success, or false on failure.
     */
    public function insert_groups_bulk(array $groups_to_insert)
    {
        if (empty($groups_to_insert)) {
            return false;
        }

        try {
            $placeholders = [];
            $values = [];
            foreach ($groups_to_insert as $group) {
                // Use a placeholder pair for each row to be inserted.
                $placeholders[] = "(%s, %d)";
                // Add the sanitized values to the values array.
                $values[] = sanitize_text_field($group['group_name']);
                $values[] = absint($group['group_id']);
            }

            // Build the SQL query with the placeholders.
            $query = "INSERT INTO {$this->table_name} (group_name, group_id) VALUES " . implode(', ', $placeholders);

            // Prepare the query securely and execute it.
            $inserted = $this->wpdb->query($this->wpdb->prepare($query, $values));

            if (false === $inserted) {
                throw new Exception('Failed to bulk insert groups: ' . $this->wpdb->last_error);
            }

            return $inserted;
        } catch (Exception $e) {
            $this->logger->log('Group_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Updates an existing group by its group name.
     *
     * @param string      $current_group_name The current group name.
     * @param string|null $new_group_name Optional new group name.
     * @param int|null    $new_group_id Optional new group ID.
     * @return int|false The number of rows updated on success, or false on failure.
     */
    public function update_group_by_name($current_group_name, $new_group_name = null, $new_group_id = null)
    {
        try {
            $data = [];
            $where = ['group_name' => sanitize_text_field($current_group_name)];
            $data_format = [];
            $where_format = ['%s'];

            if ($new_group_name !== null) {
                $data['group_name'] = sanitize_text_field($new_group_name);
                $data_format[] = '%s';
            }

            if ($new_group_id !== null) {
                $data['group_id'] = absint($new_group_id);
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
                throw new Exception('Failed to update group: ' . $this->wpdb->last_error);
            }
            return $updated;
        } catch (Exception $e) {
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
    public function get_group_by_name($group_name)
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
    public function get_group_by_id($group_id)
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
}