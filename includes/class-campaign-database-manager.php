<?php

namespace Bema;

use Exception;
use Bema\BemaCRMLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A class to manage the custom database table for campaigns, albums, artists, and years.
 */
class Campaign_Database_Manager
{
    private $table_name;
    private $wpdb;
    private $logger;

    public function __construct(?BemaCRMLogger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        // The table name remains the same for continuity.
        $this->table_name = $wpdb->prefix . 'bemacrm_campaignsmeta';
        $this->logger = $logger ?? new BemaCRMLogger();
    }

    /**
     * Creates the custom database table with a unique key on 'campaign_id'.
     *
     * @return bool
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
            campaign_id BIGINT UNSIGNED NOT NULL,
            campaign VARCHAR(255) NOT NULL,
            album VARCHAR(255) NULL,
            artist VARCHAR(255) NULL,
            year INT(4) NULL,

            PRIMARY KEY (id),
            UNIQUE KEY campaign_id (campaign_id)
            ) $charset_collate;";

            dbDelta($sql);

            if ($this->wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name) {
                throw new Exception('Failed to create the database table.');
            }

            return true;
        } catch (Exception $e) {
            $this->logger->log("Database Manager Error: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Inserts a new campaign record.
     *
     * @param int         $campaign_id The unique ID for the campaign. A BIGINT is used.
     * @param string      $campaign    The name of the campaign.
     * @param string|null $album       The album name.
     * @param string|null $artist      The artist name.
     * @param int|null    $year        The year.
     * @return int|false The inserted row's ID on success, or false on failure.
     */
    public function insert_campaign(int $campaign_id, string $campaign, ?string $album = null, ?string $artist = null, ?int $year = null): int|false
    {
        try {
            // Build data and format arrays dynamically to handle optional fields
            $data = [
                'campaign_id' => absint($campaign_id),
                'campaign' => sanitize_text_field($campaign)
            ];
            $format = ['%d', '%s'];

            if (!is_null($album)) {
                $data['album'] = sanitize_text_field($album);
                $format[] = '%s';
            }
            if (!is_null($artist)) {
                $data['artist'] = sanitize_text_field($artist);
                $format[] = '%s';
            }
            if (!is_null($year)) {
                $data['year'] = absint($year);
                $format[] = '%d';
            }

            $inserted = $this->wpdb->insert($this->table_name, $data, $format);

            if (false === $inserted) {
                throw new Exception('Failed to insert campaign: ' . $this->wpdb->last_error);
            }

            return $this->wpdb->insert_id;
        } catch (Exception $e) {
            $this->logger->log('insert_campaign Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Inserts or updates multiple campaigns into the database in a single query.
     *
     * This function uses MySQL's "INSERT ... ON DUPLICATE KEY UPDATE" syntax
     * to efficiently handle both inserts and updates based on the unique 'campaign_id' key.
     *
     * @param array $campaigns_to_upsert An array of associative arrays, where each inner array
     * contains data for a campaign (e.g., 'campaign_id', 'campaign', 'album', 'artist', 'year').
     * @return int|false The number of rows affected on success, or false on failure.
     */
    public function upsert_campaigns_bulk(array $campaigns_to_upsert): int|false
    {
        if (empty($campaigns_to_upsert)) {
            return false;
        }

        try {
            $placeholders = [];
            $values = [];
            $update_columns = ['campaign', 'album', 'artist', 'year'];

            foreach ($campaigns_to_upsert as $campaign) {
                if (empty($campaign['campaign_id'])) {
                    // Skip campaigns without a unique ID.
                    continue;
                }
                $placeholders[] = "(%d, %s, %s, %s, %d)";
                $values[] = absint($campaign['campaign_id']);
                $values[] = sanitize_text_field($campaign['campaign'] ?? '');
                $values[] = sanitize_text_field($campaign['album'] ?? '');
                $values[] = sanitize_text_field($campaign['artist'] ?? '');
                $values[] = absint($campaign['year'] ?? 0);
            }

            if (empty($placeholders)) {
                return false;
            }

            $update_parts = [];
            foreach ($update_columns as $col) {
                $update_parts[] = "$col = VALUES($col)";
            }
            $update_clause = implode(', ', $update_parts);

            $query = "INSERT INTO {$this->table_name} (campaign_id, campaign, album, artist, year) VALUES " .
                implode(', ', $placeholders) .
                " ON DUPLICATE KEY UPDATE " . $update_clause;

            $result = $this->wpdb->query($this->wpdb->prepare($query, $values));

            if (false === $result) {
                throw new Exception('Failed to bulk upsert campaigns: ' . $this->wpdb->last_error);
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->log('Campaign_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }


    /**
     * Fetches a single campaign record by its unique campaign ID.
     */
    public function get_campaign_by_id(int $campaign_id): ?array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE campaign_id = %d LIMIT 1",
            absint($campaign_id)
        );

        $result = $this->wpdb->get_row($query, ARRAY_A);
        return $result ?: null;
    }

    /**
     * Fetches a single campaign record by its campaign name. Note: campaign names are not unique.
     */
    public function get_campaign_by_name(string $campaign): ?array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE campaign = %s LIMIT 1",
            sanitize_text_field($campaign)
        );

        $result = $this->wpdb->get_row($query, ARRAY_A);
        return $result ?: null;
    }


    /**
     * Retrieves all campaign records.
     */
    public function get_all_campaigns(): array
    {
        $results = $this->wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
        return $results ?: [];
    }

    /**
     * Updates a campaign record based on the campaign ID.
     */
    public function update_campaign_by_id(int $campaign_id, array $data): bool
    {
        try {
            if (empty($data)) {
                throw new Exception('No data provided for update.');
            }

            $update_data = [];
            $format = [];

            foreach ($data as $key => $value) {
                switch ($key) {
                    case 'campaign':
                        $update_data['campaign'] = sanitize_text_field($value);
                        $format[] = '%s';
                        break;
                    case 'album':
                        $update_data['album'] = sanitize_text_field($value);
                        $format[] = '%s';
                        break;
                    case 'artist':
                        $update_data['artist'] = sanitize_text_field($value);
                        $format[] = '%s';
                        break;
                    case 'year':
                        $update_data['year'] = is_null($value) ? null : absint($value);
                        $format[] = '%d';
                        break;
                }
            }

            if (empty($update_data)) {
                throw new Exception('No valid fields provided for update.');
            }

            $updated = $this->wpdb->update(
                $this->table_name,
                $update_data,
                ['campaign_id' => absint($campaign_id)],
                $format,
                ['%d']
            );

            if (false === $updated) {
                throw new Exception('Failed to update campaign: ' . $this->wpdb->last_error);
            }

            return $updated > 0;
        } catch (Exception $e) {
            $this->logger->log('update_campaign_by_id Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Updates a campaign record based on the campaign name. Note: campaign names are not unique.
     */
    public function update_campaign_by_name(string $campaign, array $data): bool
    {
        try {
            if (empty($data)) {
                throw new Exception('No data provided for update.');
            }

            $update_data = [];
            $format = [];

            foreach ($data as $key => $value) {
                switch ($key) {
                    case 'album':
                        $update_data['album'] = sanitize_text_field($value);
                        $format[] = '%s';
                        break;
                    case 'artist':
                        $update_data['artist'] = sanitize_text_field($value);
                        $format[] = '%s';
                        break;
                    case 'year':
                        $update_data['year'] = is_null($value) ? null : absint($value);
                        $format[] = '%d';
                        break;
                }
            }

            if (empty($update_data)) {
                throw new Exception('No valid fields provided for update.');
            }

            $updated = $this->wpdb->update(
                $this->table_name,
                $update_data,
                ['campaign' => sanitize_text_field($campaign)],
                $format,
                ['%s']
            );

            if (false === $updated) {
                throw new Exception('Failed to update campaign: ' . $this->wpdb->last_error);
            }

            return $updated > 0;
        } catch (Exception $e) {
            $this->logger->log('update_campaign_by_name Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Inserts or updates a campaign record.
     */
    public function upsert_campaign(int $campaign_id, string $campaign, array $data): bool
    {
        try {
            $existing_campaign = $this->get_campaign_by_id($campaign_id);

            if ($existing_campaign) {
                // If the campaign exists, update it with the new data.
                return $this->update_campaign_by_id($campaign_id, $data);
            } else {
                // If the campaign does not exist, insert a new record.
                $album = $data['album'] ?? null;
                $artist = $data['artist'] ?? null;
                $year = $data['year'] ?? null;

                return $this->insert_campaign($campaign_id, $campaign, $album, $artist, $year);
            }
        } catch (Exception $e) {
            $this->logger->log('upsert_campaign Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }


    /**
     * Deletes a campaign record by the campaign ID.
     */
    public function delete_campaign_by_id(int $campaign_id): int|false
    {
        try {
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['campaign_id' => absint($campaign_id)],
                ['%d']
            );

            if (false === $deleted) {
                throw new Exception('Failed to delete campaign: ' . $this->wpdb->last_error);
            }

            return $deleted;
        } catch (Exception $e) {
            $this->logger->log('delete_campaign_by_id Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Deletes a campaign record by the campaign name. Note: campaign names are not unique.
     */
    public function delete_campaign_by_name(string $campaign): int|false
    {
        try {
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['campaign' => sanitize_text_field($campaign)],
                ['%s']
            );

            if (false === $deleted) {
                throw new Exception('Failed to delete campaign: ' . $this->wpdb->last_error);
            }

            return $deleted;
        } catch (Exception $e) {
            $this->logger->log('delete_campaign_by_name Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Deletes the entire table.
     */
    public function delete_table(): bool
    {
        try {
            $sql = "DROP TABLE IF EXISTS {$this->table_name}";
            $this->wpdb->query($sql);

            if ($this->wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name) {
                throw new Exception("Failed to delete the database table: {$this->table_name}");
            }

            return true;
        } catch (Exception $e) {
            $this->logger->log('delete_table Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }
}
