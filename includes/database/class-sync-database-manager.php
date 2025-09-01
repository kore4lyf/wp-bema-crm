<?php

namespace Bema\Database;

use Exception;
use Bema\BemaCRMLogger;

if (!defined('ABSPATH')) {
    exit;
}

class Sync_Database_Manager
{
    private $table_name;
    private $wpdb;
    private $logger;
    private $max_records = 10;

    public function __construct(?BemaCRMLogger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_sync_log';
        $this->logger = $logger ?? new BemaCRMLogger();
    }

    public function create_table()
    {
        try {
            if (!function_exists('dbDelta')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }
            
            $charset_collate = $this->wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$this->table_name} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                sync_date DATE NOT NULL,
                status VARCHAR(50) NOT NULL,
                synced_subscribers INT UNSIGNED NOT NULL,
                notes TEXT,
                data LONGBLOB,
                PRIMARY KEY (id),
                UNIQUE KEY sync_date_unique (sync_date)
            ) $charset_collate;";

            dbDelta($sql);

            return true;
        } catch (Exception $e) {
            $this->logger->log('Sync_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Inserts or updates a sync record based on sync_date.
     */
    public function upsert_sync_record($status, $synced_subscribers, $notes = '', $data = null)
    {
        try {
            $date_only = current_time('Y-m-d');
            $existing = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE sync_date = %s LIMIT 1",
                $date_only
            ));

            if ($data !== null && (is_array($data) || is_object($data))) {
                $data = gzcompress(serialize($data));
            }

            $record_data = [
                'status' => sanitize_text_field($status),
                'synced_subscribers' => absint($synced_subscribers),
                'notes' => sanitize_textarea_field($notes),
                'data' => $data,
            ];

            if ($existing) {
                // Update the existing record for today
                $this->wpdb->update(
                    $this->table_name,
                    $record_data,
                    ['id' => $existing],
                    ['%s', '%d', '%s', '%s'],
                    ['%d']
                );
            } else {
                // Insert a new record for today
                $record_data['sync_date'] = $date_only;
                $this->wpdb->insert(
                    $this->table_name,
                    $record_data,
                    ['%s', '%d', '%s', '%s', '%s'] 
                );
            }

            // The remaining code (enforcing max records) is fine as is
            $this->wpdb->query("
                DELETE FROM {$this->table_name}
                WHERE id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM {$this->table_name} ORDER BY sync_date DESC LIMIT {$this->max_records}
                    ) AS temp
                )
            ");

            return $existing ?: $this->wpdb->insert_id;
        } catch (Exception $e) {
            $this->logger->log('Sync_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Fetch all sync records without decoding data (memory-efficient).
     */
    public function get_sync_records_without_data()
    {
        return $this->wpdb->get_results(
            "SELECT id, sync_date, status, synced_subscribers, notes FROM {$this->table_name} ORDER BY sync_date DESC",
            ARRAY_A
        );
    }

    /**
     * Fetch only the data for a given record ID.
     */
    public function get_sync_data_by_id($id)
    {
        $record = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT data FROM {$this->table_name} WHERE id = %d LIMIT 1",
            absint($id)
        ));

        if ($record) {
            $decoded = @gzuncompress($record);
            return $decoded !== false ? unserialize($decoded) : null;
        }

        return null;
    }

    /**
     * Deletes the table.
     */
    public function delete_table(): bool
    {
        try {
            $this->wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
            return $this->wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name;
        } catch (Exception $e) {
            $this->logger->log('Database Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }
}