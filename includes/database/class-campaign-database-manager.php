<?php

namespace Bema\Database;

use Exception;
use Bema\BemaCRMLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A class to manage the custom database table for campaigns.
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
        $this->table_name = $wpdb->prefix . 'bemacrm_campaignsmeta';
        $this->logger = $logger ?? new BemaCRMLogger();
    }

    /**
     * Creates the custom database table.
     *
     * Adds the product_id column to reference EDD products.
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
            $posts_table = $this->wpdb->posts;

            $sql = "CREATE TABLE {$this->table_name} (
                id BIGINT UNSIGNED NOT NULL,
                campaign VARCHAR(255) NOT NULL,
                product_id BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY campaign_unique (campaign),
                CONSTRAINT fk_bemacrm_campaignsmeta_product FOREIGN KEY (product_id) REFERENCES {$posts_table}(ID) ON DELETE CASCADE
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
     * @param int         $id          The unique ID for the campaign.
     * @param string      $campaign    The name of the campaign.
     * @param int|null    $product_id  The EDD product ID.
     * @return int|false The inserted row's ID on success, or false on failure.
     */
    public function insert_campaign(int $id, string $campaign, ?int $product_id = null): int|false
    {
        try {
            $data = [
                'id' => absint($id),
                'campaign' => sanitize_text_field($campaign)
            ];
            $format = ['%d', '%s'];

            if (!is_null($product_id)) {
                $data['product_id'] = absint($product_id);
                $format[] = '%d';
            }

            $inserted = $this->wpdb->insert($this->table_name, $data, $format);

            if (false === $inserted) {
                throw new Exception('Failed to insert campaign: ' . $this->wpdb->last_error);
            }

            return absint($id);
        } catch (Exception $e) {
            $this->logger->log('insert_campaign Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Inserts or updates multiple campaigns into the database in a single query.
     *
     * This function uses MySQL's "INSERT ... ON DUPLICATE KEY UPDATE" syntax
     * to efficiently handle both inserts and updates based on the unique 'id' key.
     *
     * @param array $campaigns_to_upsert An array of associative arrays, where each inner array
     * contains data for a campaign (e.g., 'id', 'campaign', 'product_id').
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
            $update_columns = ['campaign', 'product_id'];

            foreach ($campaigns_to_upsert as $campaign) {
                if (empty($campaign['id'])) {
                    continue;
                }
                $placeholders[] = "(%d, %s, %d)";
                $values[] = absint($campaign['id']);
                $values[] = sanitize_text_field($campaign['campaign'] ?? '');
                $values[] = absint($campaign['product_id'] ?? 0);
            }

            if (empty($placeholders)) {
                return false;
            }

            $update_parts = [];
            foreach ($update_columns as $col) {
                $update_parts[] = "$col = VALUES($col)";
            }
            $update_clause = implode(', ', $update_parts);

            $query = "INSERT INTO {$this->table_name} (id, campaign, product_id) VALUES " .
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
     * Fetches a single campaign record by its unique campaign ID, including published product details.
     */
    public function get_campaign_by_id(int $id): ?array
    {
        $posts_table = $this->wpdb->posts;
        $term_relationships_table = $this->wpdb->term_relationships;
        $term_taxonomy_table = $this->wpdb->term_taxonomy;
        $terms_table = $this->wpdb->terms;

        $query = $this->wpdb->prepare(
            "
            SELECT
                t1.id,
                t1.campaign,
                t1.product_id,
                t2.post_title AS album,
                YEAR(t2.post_date) AS year,
                (SELECT t4.name FROM {$term_relationships_table} AS t3
                INNER JOIN {$term_taxonomy_table} AS t5 ON t3.term_taxonomy_id = t5.term_taxonomy_id
                INNER JOIN {$terms_table} AS t4 ON t4.term_id = t5.term_id
                WHERE t3.object_id = t1.product_id AND t5.taxonomy = 'download_category' AND t4.slug LIKE '%s' LIMIT 1) AS artist
            FROM {$this->table_name} AS t1
            LEFT JOIN {$posts_table} AS t2 ON t1.product_id = t2.ID
            WHERE t1.id = %d AND (t2.post_status = 'publish' OR t1.product_id IS NULL)
            LIMIT 1
            ",
            '%-artist',
            absint($id)
        );

        $result = $this->wpdb->get_row($query, ARRAY_A);
        return $result ?: null;
    }

    /**
     * Fetches a single campaign record by its campaign name, including published product details.
     */
    public function get_campaign_by_name(string $campaign_name): ?array
    {
        $posts_table = $this->wpdb->posts;
        $term_relationships_table = $this->wpdb->term_relationships;
        $term_taxonomy_table = $this->wpdb->term_taxonomy;
        $terms_table = $this->wpdb->terms;

        $query = $this->wpdb->prepare(
            "
            SELECT
                t1.id,
                t1.campaign,
                t1.product_id,
                t2.post_title AS album,
                YEAR(t2.post_date) AS year,
                (SELECT t4.name FROM {$term_relationships_table} AS t3
                INNER JOIN {$term_taxonomy_table} AS t5 ON t3.term_taxonomy_id = t5.term_taxonomy_id
                INNER JOIN {$terms_table} AS t4 ON t4.term_id = t5.term_id
                WHERE t3.object_id = t1.product_id AND t5.taxonomy = 'download_category' AND t4.slug LIKE '%s' LIMIT 1) AS artist
            FROM {$this->table_name} AS t1
            LEFT JOIN {$posts_table} AS t2 ON t1.product_id = t2.ID
            WHERE t1.campaign = %s AND (t2.post_status = 'publish' OR t1.product_id IS NULL)
            LIMIT 1
            ",
            '%-artist',
            sanitize_text_field($campaign_name)
        );

        $result = $this->wpdb->get_row($query, ARRAY_A);
        return $result ?: null;
    }

    /**
     * Retrieves all unique campaign names from the database.
     *
     * @return array An array of campaign names, or an empty array on failure.
     */
    public function get_all_campaign_names(): array
    {
        try {
            $query = "SELECT campaign FROM {$this->table_name}";
            $results = $this->wpdb->get_col($query);

            if (is_wp_error($results)) {
                throw new Exception("Database query failed: " . $results->get_error_message());
            }
            
            return $results ?: [];
        } catch (Exception $e) {
            $this->logger->log('get_only_campaigns Error: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * Retrieves all published campaign records, including product details.
     */
    public function get_all_campaigns(): array
    {
        $posts_table = $this->wpdb->posts;
        $term_relationships_table = $this->wpdb->term_relationships;
        $term_taxonomy_table = $this->wpdb->term_taxonomy;
        $terms_table = $this->wpdb->terms;

        $query = $this->wpdb->prepare(
            "
            SELECT
                t1.id,
                t1.campaign,
                t1.product_id,
                t2.post_title AS album,
                YEAR(t2.post_date) AS year,
                (SELECT t4.name FROM {$term_relationships_table} AS t3
                INNER JOIN {$term_taxonomy_table} AS t5 ON t3.term_taxonomy_id = t5.term_taxonomy_id
                INNER JOIN {$terms_table} AS t4 ON t4.term_id = t5.term_id
                WHERE t3.object_id = t1.product_id AND t5.taxonomy = 'download_category' AND t4.slug LIKE '%s' LIMIT 1) AS artist
            FROM {$this->table_name} AS t1
            LEFT JOIN {$posts_table} AS t2 ON t1.product_id = t2.ID
            WHERE t2.post_status = 'publish' OR t1.product_id IS NULL
            ",
            '%-artist'
        );

        $results = $this->wpdb->get_results($query, ARRAY_A);
        return $results ?: [];
    }

    /**
     * Updates a campaign record based on the campaign ID.
     */
    public function update_campaign_by_id(int $id, array $data): bool
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
                    case 'product_id':
                        $update_data['product_id'] = is_null($value) ? null : absint($value);
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
                ['id' => absint($id)],
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
                    case 'product_id':
                        $update_data['product_id'] = is_null($value) ? null : absint($value);
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
    public function upsert_campaign(int $id, string $campaign, array $data): bool
    {
        try {
            $existing_campaign = $this->get_campaign_by_id($id);

            if ($existing_campaign) {
                // If the campaign exists, update it with the new data.
                return $this->update_campaign_by_id($id, $data);
            } else {
                // If the campaign does not exist, insert a new record.
                $product_id = $data['product_id'] ?? null;

                return $this->insert_campaign($id, $campaign, $product_id) !== false;
            }
        } catch (Exception $e) {
            $this->logger->log('upsert_campaign Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }


    /**
     * Deletes a campaign record by the campaign ID.
     */
    public function delete_campaign_by_id(int $id): int|false
    {
        try {
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['id' => absint($id)],
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