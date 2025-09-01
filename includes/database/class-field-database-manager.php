<?php

namespace Bema\Database;

use Exception;
use Bema\BemaCRMLogger;
use wpdb;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages all database operations for the BemaCRM Field Meta table.
 *
 * This class provides a set of methods to interact with the custom database table
 * for storing field metadata, including creating the table, inserting, updating,
 * deleting, and retrieving field records.
 */
class Field_Database_Manager
{
    /**
     * The name of the custom database table for fields.
     *
     * @var string
     */
    private $table_name;
    
    /**
     * The name of the campaigns meta table, used for foreign key constraints.
     *
     * @var string
     */
    private $campaign_table_name;

    /**
     * The WordPress database access object.
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * The logging utility for handling errors and messages.
     *
     * @var BemaCRMLogger
     */
    private $logger;

    /**
     * Constructs the Field_Database_Manager object.
     *
     * Initializes the database table names and the logger instance.
     *
     * @param BemaCRMLogger|null $logger An optional logger instance. If not provided, a new one is created.
     */
    public function __construct(?BemaCRMLogger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_fieldmeta';
        $this->campaign_table_name = $wpdb->prefix . 'bemacrm_campaignsmeta';
        $this->logger = $logger ?? new BemaCRMLogger();
    }

    /**
     * Creates the custom database table for fields.
     *
     * Uses the `dbDelta` function for safe table creation and updates.
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
                id BIGINT UNSIGNED NOT NULL,
                field_name VARCHAR(255) NOT NULL,
                campaign_id BIGINT UNSIGNED NOT NULL,
                
                PRIMARY KEY (id),
                CONSTRAINT fk_campaign_id FOREIGN KEY (campaign_id) REFERENCES {$this->campaign_table_name}(id) ON DELETE CASCADE
            ) $charset_collate;";

            $result = dbDelta($sql);

            if (!$result) {
                throw new Exception('Failed to create the field table.');
            }

            return true;
        } catch (Exception $e) {
            $this->logger->log('Field_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Inserts a new field record or updates an existing one based on the primary key.
     *
     * @param int    $id          The unique ID of the field.
     * @param string $field_name  The name of the field.
     * @param int    $campaign_id The ID of the associated campaign.
     * @return int|false The number of affected rows on success, or false on failure.
     */
    public function upsert_field($id, $field_name, $campaign_id)
    {
        try {
            $query = "INSERT INTO {$this->table_name} (id, field_name, campaign_id) VALUES (%d, %s, %d) " .
                     "ON DUPLICATE KEY UPDATE field_name = VALUES(field_name), campaign_id = VALUES(campaign_id)";

            $result = $this->wpdb->query(
                $this->wpdb->prepare(
                    $query,
                    absint($id),
                    sanitize_text_field($field_name),
                    absint($campaign_id)
                )
            );

            if (false === $result) {
                throw new Exception('Failed to upsert field: ' . $this->wpdb->last_error);
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->log('Field_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Performs a bulk insert or update for multiple field records.
     *
     * @param array $fields_to_upsert An array of associative arrays, each representing a field with keys 'id', 'field_name', and 'campaign_id'.
     * @return int|false The number of affected rows on success, or false on failure.
     */
    public function upsert_fields_bulk(array $fields_to_upsert)
    {
        if (empty($fields_to_upsert)) {
            return false;
        }

        try {
            $placeholders = [];
            $values = [];
            
            foreach ($fields_to_upsert as $field) {
                $placeholders[] = "(%d, %s, %d)";
                $values[] = absint($field['id']);
                $values[] = sanitize_text_field($field['field_name']);
                $values[] = absint($field['campaign_id']);
            }

            $query = "INSERT INTO {$this->table_name} (id, field_name, campaign_id) VALUES " .
                     implode(', ', $placeholders) .
                     " ON DUPLICATE KEY UPDATE field_name = VALUES(field_name), campaign_id = VALUES(campaign_id)";

            $result = $this->wpdb->query($this->wpdb->prepare($query, $values));

            if (false === $result) {
                throw new Exception('Failed to bulk upsert fields: ' . $this->wpdb->last_error);
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->log('Field_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Deletes a field record from the database by its name.
     *
     * @param string $field_name The name of the field to delete.
     * @return int|false The number of deleted rows on success, or false on failure.
     */
    public function delete_field_by_name($field_name)
    {
        try {
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['field_name' => sanitize_text_field($field_name)],
                ['%s']
            );

            if (false === $deleted) {
                throw new Exception('Failed to delete field: ' . $this->wpdb->last_error);
            }

            return $deleted;
        } catch (Exception $e) {
            $this->logger->log('Field_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Retrieves a single field record by its ID.
     *
     * @param int $id The ID of the field to retrieve.
     * @return array|object|null A row object or array on success, null if no row is found.
     */
    public function get_field_by_id($id)
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
     * Retrieves a single field record by its name.
     *
     * @param string $field_name The name of the field to retrieve.
     * @return array|object|null A row object or array on success, null if no row is found.
     */
    public function get_field_by_name($field_name)
    {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE field_name = %s",
                sanitize_text_field($field_name)
            ),
            ARRAY_A
        );
    }

    /**
     * Retrieves all field records associated with a specific campaign ID.
     *
     * @param int $campaign_id The ID of the campaign.
     * @return array|object|null An array of row objects or arrays on success, null if no rows are found.
     */
    public function get_field_by_campaign_id($campaign_id)
    {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE campaign_id = %d",
                absint($campaign_id)
            ),
            ARRAY_A
        );
    }

    /**
     * Retrieves all field records from the database.
     *
     * @return array|object|null An array of row objects or arrays on success, null if no rows are found.
     */
    public function get_all_fields()
    {
        return $this->wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
    }

    /**
     * Deletes the entire field meta table.
     *
     * @return bool True if the table was successfully dropped, false otherwise.
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
            $this->logger->log('Database Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Deletes all field records associated with a specific campaign ID.
     *
     * @param int $campaign_id The ID of the campaign whose fields should be deleted.
     * @return int|false The number of deleted rows on success, or false on failure.
     */
    public function delete_fields_by_campaign_id(int $campaign_id)
    {
        try {
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['campaign_id' => absint($campaign_id)],
                ['%d']
            );

            if (false === $deleted) {
                throw new Exception('Failed to delete fields for campaign: ' . $this->wpdb->last_error);
            }

            return $deleted;
        } catch (Exception $e) {
            $this->logger->log('Field_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }
}
