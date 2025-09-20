<?php

namespace Bema\Database;

use Exception;
use Bema\Bema_CRM_Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the database table for BemaCRM transitions.
 *
 * This class handles the creation, insertion, and retrieval of transition records,
 * which track the movement of subscribers between different campaigns. It interacts
 * with the WordPress database API to perform these operations securely.
 *
 * @package Bema\Database
 * @property string $table_name The name of the transition metadata table.
 * @property object $wpdb The WordPress database abstraction object.
 * @property Bema_CRM_Logger $logger The logger instance for recording errors.
 * @property string $campaigns_table The name of the campaigns metadata table.
 */
class Transition_Database_Manager
{
    private $table_name;
    private $wpdb;
    private $logger;
    private $campaigns_table;

    /**
     * Transition_Database_Manager constructor.
     *
     * @param Bema_CRM_Logger|null $logger An optional logger instance.
     */
    public function __construct(?Bema_CRM_Logger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_transitionsmeta';
        $this->campaigns_table = $wpdb->prefix . 'bemacrm_campaignsmeta';
        $this->logger = $logger ?? Bema_CRM_Logger::create('transition-database');
    }

    /**
     * Creates the transition database table.
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
                source BIGINT UNSIGNED NOT NULL,
                destination BIGINT UNSIGNED NOT NULL,
                status ENUM('Complete', 'Failed') NOT NULL,
                subscribers INT UNSIGNED DEFAULT 0,
                transition_date DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY source_key (source),
                KEY destination_key (destination),
                CONSTRAINT fk_source FOREIGN KEY (source) REFERENCES {$this->campaigns_table}(id) ON DELETE CASCADE,
                CONSTRAINT fk_destination FOREIGN KEY (destination) REFERENCES {$this->campaigns_table}(id) ON DELETE CASCADE
            ) $charset_collate;";

            dbDelta($sql);

            // INFO: Log successful table creation for monitoring
            $this->logger->info('Transitions table created successfully', [
                'table_name' => $this->table_name,
                'campaigns_table' => $this->campaigns_table
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to create transitions table', [
                'table_name' => $this->table_name,
                'error' => $e->getMessage(),
                'trace' => WP_DEBUG ? $e->getTraceAsString() : null
            ]);
            return false;
        }
    }

    /**
     * Inserts a new transition record and returns its ID.
     *
     * @param int $source_id The ID of the source campaign.
     * @param int $destination_id The ID of the destination campaign.
     * @param string $status The status of the transition ('Complete' or 'Failed').
     * @param int $subscribers The number of subscribers involved in the transition.
     *
     * @return int|false The ID of the new record on success, or false on failure.
     */
    public function insert_record(int $source_id, int $destination_id, string $status, int $subscribers)
    {
        try {
            $record_data = [
                'source' => absint($source_id),
                'destination' => absint($destination_id),
                'status' => sanitize_text_field($status),
                'subscribers' => absint($subscribers),
                'transition_date' => current_time('mysql'),
            ];

            $inserted = $this->wpdb->insert(
                $this->table_name,
                $record_data,
                ['%d', '%d', '%s', '%d', '%s']
            );

            if ($inserted === false || $this->wpdb->last_error) {
                throw new Exception($this->wpdb->last_error);
            }

            $record_id = $this->wpdb->insert_id;
            
            // INFO: Log transition record creation for monitoring
            $this->logger->info('Transition record created', [
                'record_id' => $record_id,
                'source_id' => $source_id,
                'destination_id' => $destination_id,
                'status' => $status,
                'subscribers' => $subscribers
            ]);

            return $record_id;
        } catch (Exception $e) {
            $this->logger->error('Failed to insert transition record', [
                'source_id' => $source_id,
                'destination_id' => $destination_id,
                'status' => $status,
                'subscribers' => $subscribers,
                'db_error' => $this->wpdb->last_error,
                'error' => $e->getMessage(),
                'trace' => WP_DEBUG ? $e->getTraceAsString() : null
            ]);
            return false;
        }
    }

    /**
     * Fetches all transition records, joining with the campaigns table to get campaign names.
     *
     * @return array An array of associative arrays representing the transition records.
     */
    public function get_all_records()
    {
        $sql = "
            SELECT
            t.id,
            s.campaign AS source,
            d.campaign AS destination,
            t.status,
            t.subscribers,
            t.transition_date
            FROM
            {$this->table_name} AS t
            LEFT JOIN
            {$this->campaigns_table} AS s ON t.source = s.id
            LEFT JOIN
            {$this->campaigns_table} AS d ON t.destination = d.id
            ORDER BY
            t.transition_date DESC
        ";

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        foreach ($results as &$result) {
            try {
                $timestamp = new \DateTime($result['transition_date']);
                $result['transition_date'] = $timestamp->format('F j, Y, g:i a');
            } catch (Exception $e) {
                $result['transition_date'] = '—';
                $this->logger->error('Date formatting error', ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * Updates an existing transition record.
     *
     * @param int $transition_id The ID of the transition record to update.
     * @param string $status The new status of the transition ('Complete' or 'Failed').
     * @param int $subscribers The updated number of subscribers involved in the transition.
     *
     * @return bool True on success, false on failure.
     */
    public function upsert_record(int $transition_id, string $status, int $subscribers): bool
    {
        try {
            // Validate the transition ID exists
            $existing_record = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->table_name} WHERE id = %d",
                    absint($transition_id)
                )
            );

            if (!$existing_record) {
                $this->logger->error('Transition record not found for update', [
                    'transition_id' => $transition_id,
                    'status' => $status,
                    'subscribers' => $subscribers
                ]);
                return false;
            }

            // Update the existing record
            $update_data = [
                'status' => sanitize_text_field($status),
                'subscribers' => absint($subscribers),
                'transition_date' => current_time('mysql'),
            ];

            $updated = $this->wpdb->update(
                $this->table_name,
                $update_data,
                ['id' => absint($transition_id)],
                ['%s', '%d', '%s'],
                ['%d']
            );

            if ($updated === false) {
                throw new Exception($this->wpdb->last_error);
            }

            // INFO: Log successful transition record update
            $this->logger->info('Transition record updated successfully', [
                'transition_id' => $transition_id,
                'status' => $status,
                'subscribers' => $subscribers,
                'rows_affected' => $updated
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update transition record', [
                'transition_id' => $transition_id,
                'status' => $status,
                'subscribers' => $subscribers,
                'db_error' => $this->wpdb->last_error,
                'error' => $e->getMessage(),
                'trace' => WP_DEBUG ? $e->getTraceAsString() : null
            ]);
            return false;
        }
    }

    /**
     * Returns a map of transition records indexed by their ID.
     *
     * @return array Associative array with transition IDs as keys and full records as values.
     */
    public function transition_data_map(): array {
        // Get all transition records
        $transition_data = $this->get_all_records();

        $data = [];

        foreach ($transition_data as $item) {
            $data[$item['id']] = $item;
        }

        return $data;
    }

    /**
     * Returns a map of transition dates indexed by transition ID.
     *
     * @return array Associative array with transition IDs as keys and dates as values.
     */
    public function get_transition_date_from_id_map(): array {
        // Get all transition records
        $sql = "SELECT * FROM {$this->table_name}";
        $transition_data = $this->wpdb->get_results($sql, ARRAY_A);

        $data = [];

        foreach ($transition_data as $item) {
            $data[$item['id']] = $item['transition_date'];
        }

        return $data;
    }

    /**
     * Deletes the transition database table.
     *
     * @return bool True on success, false on failure.
     */
    public function delete_table(): bool
    {
        try {
            $this->wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
            $table_exists = $this->wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
            
            if (!$table_exists) {
                // CRITICAL: Log table deletion for security monitoring
                $this->logger->critical('Transitions table deleted', [
                    'table_name' => $this->table_name,
                    'user_id' => get_current_user_id(),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete transitions table', [
                'table_name' => $this->table_name,
                'db_error' => $this->wpdb->last_error,
                'error' => $e->getMessage(),
                'trace' => WP_DEBUG ? $e->getTraceAsString() : null
            ]);
            return false;
        }
    }
}
