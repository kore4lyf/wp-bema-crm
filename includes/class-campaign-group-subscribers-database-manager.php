<?php

namespace Bema;

use Exception;
use Bema\BemaCRMLogger;

if (!defined('ABSPATH')) {
    exit;
}

class Campaign_Group_Subscribers_Database_Manager
{
    private $table_name;
    private $wpdb;
    private $logger;

    public function __construct(?BemaCRMLogger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_campaign_subscribersmeta';
        $this->logger = $logger ?? new BemaCRMLogger();
    }

    /**
     * Creates the custom database table. The unique key is now on
     * subscriber_id and campaign_name to enforce one tier per campaign.
     * The group_id remains to identify the specific group within the campaign.
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
            subscriber_id BIGINT UNSIGNED NOT NULL,
            group_id BIGINT UNSIGNED NOT NULL,
            campaign_name VARCHAR(255) NOT NULL,
            tier VARCHAR(255) NOT NULL,
            purchase_id BIGINT UNSIGNED NULL,

            PRIMARY KEY (id),
            UNIQUE KEY sub_campaign (subscriber_id, campaign_name)
            ) $charset_collate;";

            dbDelta($sql);

            // Corrected check to see if the table was actually created
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
     * Inserts a new campaign subscriber entry.
     */
    public function insert_campaign_subscriber(int $subscriber_id, int $group_id, string $campaign_name, string $tier, ?int $purchase_id = null): int|false
    {
        try {
            // Build the data and format arrays dynamically to ensure they match,
// which is a more robust way to handle the optional purchase_id field.
            $data = [
                'subscriber_id' => absint($subscriber_id),
                'group_id' => absint($group_id),
                'campaign_name' => sanitize_text_field($campaign_name),
                'tier' => sanitize_text_field($tier),
            ];
            $format = ['%d', '%d', '%s', '%s'];

            // Only add the purchase_id if it's not null
            if (!is_null($purchase_id)) {
                $data['purchase_id'] = absint($purchase_id);
                $format[] = '%d';
            }

            $inserted = $this->wpdb->insert($this->table_name, $data, $format);

            if (false === $inserted) {
                // Return false and log the error for debugging purposes
                throw new Exception('Failed to insert campaign subscriber: ' . $this->wpdb->last_error);
            }

            return $this->wpdb->insert_id;
        } catch (Exception $e) {
            $this->logger->log('insert_campaign_subscriber Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Fetch a single subscriber record. Now requires campaign_name.
     */
    public function get_campaign_subscriber(int $subscriber_id, string $campaign_name): ?array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE subscriber_id = %d AND campaign_name = %s LIMIT 1",
            $subscriber_id,
            $campaign_name
        );

        $result = $this->wpdb->get_row($query, ARRAY_A);
        return $result ?: null;
    }

    /**
     * Retrieves all campaign records for a subscriber.
     */
    public function get_all_subscriber_campaigns(int $subscriber_id): array
    {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE subscriber_id = %d",
                $subscriber_id
            ),
            ARRAY_A
        ) ?: []; // Return empty array on failure instead of null
    }

    /**
     * Updates a specific campaign subscriber row by subscriber_id and campaign_name.
     */
    public function update_campaign_subscriber(int $subscriber_id, string $campaign_name, array $data): bool
    {
        try {
            if (empty($data)) {
                throw new Exception('No data provided for update.');
            }

            $update_data = [];
            $format = [];

            // A more robust way to handle the update fields and their formats
            foreach ($data as $key => $value) {
                switch ($key) {
                    case 'group_id':
                        $update_data['group_id'] = absint($value);
                        $format[] = '%d';
                        break;
                    case 'tier':
                        $update_data['tier'] = sanitize_text_field($value);
                        $format[] = '%s';
                        break;
                    case 'purchase_id':
                        // If null, pass null. wpdb will handle it correctly
                        $update_data['purchase_id'] = is_null($value) ? null : absint($value);
                        $format[] = '%d';
                        break;
                    // Add other updatable fields here if needed
                }
            }

            if (empty($update_data)) {
                throw new Exception('No valid fields provided for update.');
            }

            $updated = $this->wpdb->update(
                $this->table_name,
                $update_data,
                ['subscriber_id' => absint($subscriber_id), 'campaign_name' => sanitize_text_field($campaign_name)],
                $format,
                ['%d', '%s']
            );

            if (false === $updated) {
                // Log and return false on failure
                throw new Exception('Failed to update campaign subscriber: ' . $this->wpdb->last_error);
            }

            return $updated > 0; // Return true only if a row was actually updated
        } catch (Exception $e) {
            $this->logger->log('update_campaign_subscriber Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Upserts a campaign subscriber: updates the record if exists, inserts if not.
     * Now uses campaign_name for lookup.
     */
    public function upsert_campaign_subscriber(array $data): bool
    {
        try {
            if (empty($data['subscriber_id']) || empty($data['campaign_name'])) {
                throw new Exception('subscriber_id and campaign_name are required for upsert.');
            }

            $subscriber_id = (int) $data['subscriber_id'];
            $campaign_name = sanitize_text_field($data['campaign_name']);
            $group_id = (int) $data['group_id'];
            $tier = sanitize_text_field($data['subscriber_tier'] ?? '');
            $purchase_id = isset($data['purchase_id']) ? (int) $data['purchase_id'] : null;

            $existing = $this->get_campaign_subscriber($subscriber_id, $campaign_name);

            if ($existing) {
                // Update the record for the existing campaign entry
                return $this->update_campaign_subscriber($subscriber_id, $campaign_name, [
                    'group_id' => $group_id, // Also update the group_id
                    'tier' => $tier,
                    'purchase_id' => $purchase_id
                ]);
            } else {
                return $this->insert_campaign_subscriber($subscriber_id, $group_id, $campaign_name, $tier, $purchase_id);
            }
        } catch (Exception $e) {
            $this->logger->log('upsert_campaign_subscriber Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Deletes a campaign subscriber by subscriber_id and group_id.
     */
    public function delete_campaign_subscriber(int $subscriber_id, int $group_id): int|false
    {
        try {
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['subscriber_id' => absint($subscriber_id), 'group_id' => absint($group_id)],
                ['%d', '%d']
            );

            if (false === $deleted) {
                throw new Exception('Failed to delete campaign subscriber: ' . $this->wpdb->last_error);
            }

            return $deleted;
        } catch (Exception $e) {
            $this->logger->log('delete_campaign_subscriber Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Fetch all records.
     */
    public function get_all_records(): array
    {
        $results = $this->wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
        return $results ?: [];
    }

    /**
     * Deletes the table.
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
