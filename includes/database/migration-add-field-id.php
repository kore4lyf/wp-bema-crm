<?php

namespace Bema\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Migration script to add field_id column to campaign_subscribersmeta table
 */
class Migration_Add_Field_Id
{
    private $wpdb;
    private $table_name;
    private $fields_table_name;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_campaign_subscribersmeta';
        $this->fields_table_name = $wpdb->prefix . 'bemacrm_fieldmeta';
    }

    /**
     * Run the migration to add field_id column
     */
    public function run(): bool
    {
        try {
            // Check if column already exists
            $column_exists = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SHOW COLUMNS FROM {$this->table_name} LIKE %s",
                    'field_id'
                )
            );

            if (!empty($column_exists)) {
                return true; // Column already exists
            }

            // Add the field_id column
            $sql = "ALTER TABLE {$this->table_name} 
                    ADD COLUMN field_id BIGINT UNSIGNED NULL AFTER campaign_id,
                    ADD CONSTRAINT fk_field_id FOREIGN KEY (field_id) REFERENCES {$this->fields_table_name}(id) ON DELETE SET NULL";

            $result = $this->wpdb->query($sql);

            return $result !== false;

        } catch (Exception $e) {
            error_log('Migration Add Field ID Error: ' . $e->getMessage());
            return false;
        }
    }
}
