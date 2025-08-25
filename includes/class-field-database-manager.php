<?php

namespace Bema;

use Exception;
use Bema\BemaCRMLogger;
use wpdb;

if (!defined('ABSPATH')) {
    exit;
}

class Field_Database_Manager
{
    /**
     * @var string
     */
    private $table_name;
    
    /**
     * @var string
     */
    private $campaign_table_name;

    /**
     * @var wpdb
     */
    private $wpdb;

    /**
     * @var BemaCRMLogger
     */
    private $logger;

    public function __construct(?BemaCRMLogger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_fieldmeta';
        $this->campaign_table_name = $wpdb->prefix . 'bemacrm_campaignsmeta';
        $this->logger = $logger ?? new BemaCRMLogger();
    }

    /**
     * Creates the custom database table.
     *
     * The 'campaign_id' column is now an integer to serve as a proper foreign key.
     *
     * @return bool
     */
    public function create_table()
    {
        try {
            if (!function_exists('dbDelta')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }

            $charset_collate = $this->wpdb->get_charset_collate();

            // Added a FOREIGN KEY constraint to the SQL.
            // NOTE: The WordPress dbDelta function ignores FOREIGN KEY constraints.
            // The relationship is handled logically in the application.
            // Using the new $this->campaign_table_name property.
            $sql = "CREATE TABLE {$this->table_name} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                field_name VARCHAR(255) NOT NULL,
                field_id BIGINT UNSIGNED NOT NULL,
                campaign_id BIGINT UNSIGNED NOT NULL,
                
                PRIMARY KEY (id),
                UNIQUE KEY field_id (field_id),
                FOREIGN KEY (campaign_id) REFERENCES {$this->campaign_table_name}(id)
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
     * Inserts or updates a single field.
     *
     * The method now accepts an integer campaign ID instead of a string.
     *
     * @param string|int $field_id The unique ID of the field.
     * @param string $field_name The name of the field.
     * @param int $campaign_id The ID of the campaign.
     * @return int|false The number of affected rows on success, or false on failure.
     */
    public function upsert_field($field_id, $field_name, $campaign_id)
    {
        try {
            // Updated query to use 'campaign_id' and the %d placeholder.
            $query = "INSERT INTO {$this->table_name} (field_name, field_id, campaign_id) VALUES (%s, %d, %d) " .
                     "ON DUPLICATE KEY UPDATE field_name = VALUES(field_name), campaign_id = VALUES(campaign_id)";

            $result = $this->wpdb->query(
                $this->wpdb->prepare(
                    $query,
                    sanitize_text_field($field_name),
                    absint($field_id),
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
     * Inserts or updates multiple fields in a single bulk query.
     *
     * The method now expects an integer campaign ID in the input array.
     *
     * @param array $fields_to_upsert An array of associative arrays, where each
     * array contains 'field_name', 'field_id', and the new 'campaign_id'.
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
            
            // Updated loop to get the 'campaign_id' integer value.
            foreach ($fields_to_upsert as $field) {
                $placeholders[] = "(%s, %d, %d)";
                $values[] = sanitize_text_field($field['field_name']);
                $values[] = absint($field['field_id']);
                $values[] = absint($field['campaign_id']);
            }

            // Updated query to include the 'campaign_id' column.
            $query = "INSERT INTO {$this->table_name} (field_name, field_id, campaign_id) VALUES " .
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
     * Delete a field by its field_name.
     *
     * @param string $field_name
     * @return int|false
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
     * Get a field by its field_id.
     *
     * @param string|int $field_id
     * @return array|null
     */
    public function get_field_by_id($field_id)
    {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE field_id = %d",
                absint($field_id)
            ),
            ARRAY_A
        );
    }

    /**
     * Get a field by its field_name.
     *
     * @param string $field_name
     * @return array|null
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
     * Get a field by its campaign_id.
     *
     * @param int $campaign_id
     * @return array|null
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
     * Get all fields.
     *
     * @return array
     */
    public function get_all_fields()
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

    /**
     * Deletes all fields associated with a specific campaign ID.
     *
     * @param int $campaign_id The ID of the campaign whose fields need to be deleted.
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
