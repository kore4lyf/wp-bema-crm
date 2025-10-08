<?php

namespace Bema\Database;

use Exception;
use Bema\Bema_CRM_Logger;
use const ARRAY_A;

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

    public function __construct(?Bema_CRM_Logger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_campaignsmeta';
        $this->logger = $logger ?? Bema_CRM_Logger::create('campaign-database');
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
                require_once \ABSPATH . 'wp-admin/includes/upgrade.php';
            }

            $charset_collate = $this->wpdb->get_charset_collate();
            $posts_table = $this->wpdb->posts;

            $sql = "CREATE TABLE {$this->table_name} (
                id BIGINT UNSIGNED NOT NULL,
                campaign VARCHAR(255) NOT NULL,
                product_id BIGINT UNSIGNED NULL,
                start_date DATE NULL,
                end_date DATE NULL,
                status ENUM('draft', 'pending', 'active', 'completed') NOT NULL DEFAULT 'draft',
                PRIMARY KEY (id),
                UNIQUE KEY campaign_unique (campaign),
                CONSTRAINT fk_bemacrm_campaignsmeta_product FOREIGN KEY (product_id) REFERENCES {$posts_table}(ID) ON DELETE CASCADE
            ) $charset_collate;";

            \dbDelta($sql);

            if ($this->wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name) {
                throw new Exception('Failed to create the database table.');
            }

            // INFO: Log successful table creation for monitoring
            $this->logger->info('Campaigns table created successfully', [
                'table_name' => $this->table_name
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to create campaigns table', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Inserts a new campaign record.
     *
     * @param int         $id          The unique ID for the campaign.
     * @param string      $campaign    The name of the campaign.
     * @param int|null    $product_id  The EDD product ID.
     * @param string|null $start_date  The campaign start date (Y-m-d format).
     * @param string|null $end_date    The campaign end date (Y-m-d format).
     * @param string      $status      The campaign status (draft, pending, active, completed).
     * @return int|false The inserted row's ID on success, or false on failure.
     */
    public function insert_campaign(int $id, string $campaign, ?int $product_id = null, ?string $start_date = null, ?string $end_date = null, string $status = 'draft'): int|false
    {
        try {
            // Validate status
            $valid_statuses = ['draft', 'pending', 'active', 'completed'];
            if (!\in_array($status, $valid_statuses)) {
                $status = 'draft';
            }

            $data = [
                'id' => \absint($id),
                'campaign' => \sanitize_text_field($campaign),
                'status' => $status
            ];
            $format = ['%d', '%s', '%s'];

            if (!is_null($product_id)) {
                $data['product_id'] = \absint($product_id);
                $format[] = '%d';
            }

            if (!is_null($start_date)) {
                $data['start_date'] = \sanitize_text_field($start_date);
                $format[] = '%s';
            }

            if (!is_null($end_date)) {
                $data['end_date'] = \sanitize_text_field($end_date);
                $format[] = '%s';
            }

            $inserted = $this->wpdb->insert($this->table_name, $data, $format);

            if (false === $inserted) {
                throw new Exception('Failed to insert campaign: ' . $this->wpdb->last_error);
            }

            return \absint($id);
        } catch (Exception $e) {
            $this->logger->error('Failed to insert campaign', [
                'campaign' => $campaign,
                'product_id' => $product_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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
            $update_columns = ['campaign', 'product_id', 'start_date', 'end_date', 'status'];

            foreach ($campaigns_to_upsert as $campaign) {
                if (empty($campaign['id'])) {
                    continue;
                }
                
                // Validate status
                $status = $campaign['status'] ?? 'draft';
                $valid_statuses = ['draft', 'pending', 'active', 'completed'];
                if (!\in_array($status, $valid_statuses)) {
                    $status = 'draft';
                }
                
                $placeholders[] = "(%d, %s, %d, %s, %s, %s)";
                $values[] = \absint($campaign['id']);
                $values[] = \sanitize_text_field($campaign['campaign'] ?? '');
                $values[] = \absint($campaign['product_id'] ?? 0);
                $values[] = !empty($campaign['start_date']) ? \sanitize_text_field($campaign['start_date']) : null;
                $values[] = !empty($campaign['end_date']) ? \sanitize_text_field($campaign['end_date']) : null;
                $values[] = $status;
            }

            if (empty($placeholders)) {
                return false;
            }

            $update_parts = [];
            foreach ($update_columns as $col) {
                $update_parts[] = "$col = VALUES($col)";
            }
            $update_clause = implode(', ', $update_parts);

            $query = "INSERT INTO {$this->table_name} (id, campaign, product_id, start_date, end_date, status) VALUES " .
                implode(', ', $placeholders) .
                " ON DUPLICATE KEY UPDATE " . $update_clause;

            $result = $this->wpdb->query($this->wpdb->prepare($query, $values));

            if (false === $result) {
                throw new Exception('Failed to bulk upsert campaigns: ' . $this->wpdb->last_error);
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Failed to bulk upsert campaigns', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Retrieves all campaigns from the database.
     *
     * @return array An array of campaign data.
     */
    public function get_all_campaigns(): array
    {
        $posts_table = $this->wpdb->posts;

        $query = $this->wpdb->prepare(
            "
            SELECT t1.campaign
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
     * Get campaigns with pagination and sorting support.
     *
     * @param int $per_page Number of campaigns per page.
     * @param int $offset Starting offset for pagination.
     * @param string $orderby Column to order by (campaign, product_id, start_date, end_date, status).
     * @param string $order Sort order (asc or desc).
     * @return array Array of campaign data.
     */
    public function get_campaigns($per_page = 25, $offset = 0, $orderby = 'start_date', $order = 'desc')
    {
        $posts_table = $this->wpdb->posts;
        $term_relationships_table = $this->wpdb->term_relationships;
        $term_taxonomy_table = $this->wpdb->term_taxonomy;
        $terms_table = $this->wpdb->terms;

        // Validate orderby parameter
        $valid_orderby = ['id', 'campaign', 'product_id', 'start_date', 'end_date', 'status'];
        if (!\in_array($orderby, $valid_orderby, true)) {
            $orderby = 'start_date';
        }

        // Validate order parameter
        $order = \strtoupper($order);
        if (!\in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        // Handle NULL values for date sorting - put NULL dates last
        if (\in_array($orderby, ['start_date', 'end_date'])) {
            $order_clause = "ORDER BY t1.$orderby IS NULL, t1.$orderby $order";
        } else {
            $order_clause = "ORDER BY t1.$orderby $order";
        }

        $query = $this->wpdb->prepare(
            "
            SELECT
                t1.id,
                t1.campaign,
                t1.product_id,
                t1.start_date,
                t1.end_date,
                t1.status,
                t2.post_title AS album,
                YEAR(t2.post_date) AS year,
                (SELECT t4.name FROM {$term_relationships_table} AS t3
                INNER JOIN {$term_taxonomy_table} AS t5 ON t3.term_taxonomy_id = t5.term_taxonomy_id
                INNER JOIN {$terms_table} AS t4 ON t4.term_id = t5.term_id
                WHERE t3.object_id = t1.product_id AND t5.taxonomy = 'download_category' AND t4.slug LIKE '%s' LIMIT 1) AS artist
            FROM {$this->table_name} AS t1
            LEFT JOIN {$posts_table} AS t2 ON t1.product_id = t2.ID
            WHERE t2.post_status = 'publish' OR t1.product_id IS NULL
            $order_clause
            LIMIT %d OFFSET %d
            ",
            '%-artist',
            $per_page,
            $offset
        );

        $results = $this->wpdb->get_results($query, ARRAY_A);
        return $results ?: [];
    }

    /**
     * Count total campaigns for pagination.
     *
     * @return int Total number of campaigns.
     */
    public function count_campaigns()
    {
        $posts_table = $this->wpdb->posts;

        $query = "
            SELECT COUNT(*)
            FROM {$this->table_name} AS t1
            LEFT JOIN {$posts_table} AS t2 ON t1.product_id = t2.ID
            WHERE t2.post_status = 'publish' OR t1.product_id IS NULL
        ";

        $count = $this->wpdb->get_var($query);
        return (int) $count;
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
                        $update_data['campaign'] = \sanitize_text_field($value);
                        $format[] = '%s';
                        break;
                    case 'product_id':
                        $update_data['product_id'] = is_null($value) ? null : \absint($value);
                        $format[] = '%d';
                        break;
                    case 'start_date':
                        $update_data['start_date'] = is_null($value) ? null : \sanitize_text_field($value);
                        $format[] = '%s';
                        break;
                    case 'end_date':
                        $update_data['end_date'] = is_null($value) ? null : \sanitize_text_field($value);
                        $format[] = '%s';
                        break;
                    case 'status':
                        $valid_statuses = ['draft', 'pending', 'active', 'completed'];
                        $status = \in_array($value, $valid_statuses) ? $value : 'draft';
                        $update_data['status'] = $status;
                        $format[] = '%s';
                        break;
                }
            }

            if (empty($update_data)) {
                throw new Exception('No valid fields provided for update.');
            }

            $updated = $this->wpdb->update(
                $this->table_name,
                $update_data,
                ['id' => \absint($id)],
                $format,
                ['%d']
            );

            if (false === $updated) {
                throw new Exception('Failed to update campaign: ' . $this->wpdb->last_error);
            }

            return $updated > 0;
        } catch (Exception $e) {
            $this->logger->error('Failed to update campaign by ID', [
                'id' => $id,
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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
                        $update_data['product_id'] = is_null($value) ? null : \absint($value);
                        $format[] = '%d';
                        break;
                    case 'start_date':
                        $update_data['start_date'] = is_null($value) ? null : \sanitize_text_field($value);
                        $format[] = '%s';
                        break;
                    case 'end_date':
                        $update_data['end_date'] = is_null($value) ? null : \sanitize_text_field($value);
                        $format[] = '%s';
                        break;
                    case 'status':
                        $valid_statuses = ['draft', 'pending', 'active', 'completed'];
                        $status = \in_array($value, $valid_statuses) ? $value : 'draft';
                        $update_data['status'] = $status;
                        $format[] = '%s';
                        break;
                }
            }

            if (empty($update_data)) {
                throw new Exception('No valid fields provided for update.');
            }

            $updated = $this->wpdb->update(
                $this->table_name,
                $update_data,
                ['campaign' => \sanitize_text_field($campaign)],
                $format,
                ['%s']
            );

            if (false === $updated) {
                throw new Exception('Failed to update campaign: ' . $this->wpdb->last_error);
            }

            return $updated > 0;
        } catch (Exception $e) {
            $this->logger->error('Failed to update campaign by name', [
                'campaign' => $campaign,
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Deletes a campaign record by its ID.
     *
     * @param int $id The ID of the campaign to delete.
     * @return bool True on success, false on failure.
     */
    public function delete_campaign_by_id(int $id): bool
    {
        try {
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['id' => \absint($id)],
                ['%d']
            );

            if (false === $deleted) {
                throw new Exception('Failed to delete campaign: ' . $this->wpdb->last_error);
            }

            return $deleted > 0;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete campaign by ID', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Retrieves a campaign by its ID.
     *
     * @param int $id The ID of the campaign to retrieve.
     * @return array|null The campaign data or null if not found.
     */
    public function get_campaign_by_id(int $id): ?array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            \absint($id)
        );

        $result = $this->wpdb->get_row($query, ARRAY_A);

        return $result ? $result : null;
    }

    /**
     * Retrieves a campaign by its name.
     *
     * @param string $campaign_name The name of the campaign to retrieve.
     * @return array|null The campaign data or null if not found.
     */
    public function get_campaign_by_name(string $campaign_name): ?array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE campaign = %s",
            \sanitize_text_field($campaign_name)
        );

        $result = $this->wpdb->get_row($query, ARRAY_A);

        return $result ? $result : null;
    }

    /**
     * Checks if a campaign exists by its ID.
     *
     * @param int $id The ID of the campaign to check.
     * @return bool True if the campaign exists, false otherwise.
     */
    public function campaign_exists_by_id(int $id): bool
    {
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE id = %d",
                \absint($id)
            )
        );

        return (int) $count > 0;
    }

    /**
     * Checks if a campaign exists by its name.
     *
     * @param string $campaign_name The name of the campaign to check.
     * @return bool True if the campaign exists, false otherwise.
     */
    public function campaign_exists_by_name(string $campaign_name): bool
    {
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE campaign = %s",
                \sanitize_text_field($campaign_name)
            )
        );

        return (int) $count > 0;
    }

    /**
     * Gets all campaigns with a specific status.
     *
     * @param string $status The status to filter by.
     * @return array An array of campaigns with the specified status.
     */
    public function get_campaigns_by_status(string $status): array
    {
        $valid_statuses = ['draft', 'pending', 'active', 'completed'];
        if (!\in_array($status, $valid_statuses)) {
            return [];
        }

        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE status = %s",
            $status
        );

        $results = $this->wpdb->get_results($query, ARRAY_A);
        return $results ?: [];
    }

    /**
     * Gets all active campaigns.
     *
     * @return array An array of active campaigns.
     */
    public function get_active_campaigns(): array
    {
        return $this->get_campaigns_by_status('active');
    }

    /**
     * Gets all campaigns within a date range.
     *
     * @param string $start_date Start date in Y-m-d format.
     * @param string $end_date End date in Y-m-d format.
     * @return array An array of campaigns within the date range.
     */
    public function get_campaigns_by_date_range(string $start_date, string $end_date): array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE 
             (start_date >= %s AND start_date <= %s) OR
             (end_date >= %s AND end_date <= %s) OR
             (start_date <= %s AND end_date >= %s)",
            $start_date,
            $end_date,
            $start_date,
            $end_date,
            $start_date,
            $end_date
        );

        $results = $this->wpdb->get_results($query, ARRAY_A);
        return $results ?: [];
    }
}