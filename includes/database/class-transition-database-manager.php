<?php

namespace Bema\Database;

use Exception;
use Bema\BemaCRMLogger;

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
 * @property BemaCRMLogger $logger The logger instance for recording errors.
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
     * @param BemaCRMLogger|null $logger An optional logger instance.
     */
    public function __construct(?BemaCRMLogger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_transitionsmeta';
        $this->campaigns_table = $wpdb->prefix . 'bemacrm_campaignsmeta';
        $this->logger = $logger ?? new BemaCRMLogger();
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

            return true;
        } catch (Exception $e) {
            $this->logger->log('Transition_Database_Manager Error: ' . $e->getMessage(), 'error');
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

            return $this->wpdb->insert_id;
        } catch (Exception $e) {
            $this->logger->log('Transition_Database_Manager Error: ' . $e->getMessage(), 'error');
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
        $sql = $this->wpdb->prepare("
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
        ");

        return $this->wpdb->get_results($sql, ARRAY_A);
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
            return $this->wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name;
        } catch (Exception $e) {
            $this->logger->log('Database Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }
}
